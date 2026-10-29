import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, CalendarClock, Check, ChevronDown, ChevronLeft, ChevronRight, Clock, CloudOff, Eye, Lock, Minus, Plus, RefreshCw, Sparkles, Trophy,
} from 'lucide-react'
import { apiGet, apiPost, ApiError } from '../../lib/api'
import { useGroup } from '../../lib/group'
import type { Match, RoundData, RoundInfo } from '../../lib/types'
import { clock, countdown, dayClock, dayMonth, plural } from '../../lib/format'
import { Crest } from '../../components/Crest'
import { Flag } from '../../components/Flag'
import { Alert, EmptyState, ProgressRing, Skeleton, useNow } from '../../components/ui'
import { useToast } from '../../components/Toast'
import { InvitePanel } from '../../components/Invite'

type Draft = { home: string; away: string }
type SaveState = { status: 'dirty' | 'saving' | 'saved' | 'error'; message?: string }

export default function RoundTab() {
  const group = useGroup()
  const [params, setParams] = useSearchParams()
  const league = Number(params.get('liga')) || undefined
  const round = Number(params.get('rodada')) || undefined
  const key = ['round', group.id, league ?? 0, round ?? 0]
  const q = useQuery({
    queryKey: key,
    queryFn: () => apiGet<RoundData>('group/round', { id: group.id, league, round }),
    placeholderData: (prev) => prev,
    refetchInterval: (query) => {
      const d = query.state.data
      const live = d && [...d.matches, ...d.rescheduled].some((m) => m.live)
      return live ? 30_000 : 120_000
    },
  })
  const data = q.data
  const go = (patch: Record<string, number | undefined>) => {
    const next = new URLSearchParams(params)
    for (const [k, v] of Object.entries(patch)) {
      if (v) next.set(k, String(v))
      else next.delete(k)
    }
    setParams(next, { replace: false })
  }

  const saver = useAutosave(group.id, key)

  if (q.isLoading || !data)
    return (
      <div className="space-y-4">
        <Skeleton className="h-40" />
        {[0, 1, 2].map((i) => <Skeleton key={i} className="h-44" />)}
      </div>
    )
  if (q.isError) return <Alert>{(q.error as Error).message}</Alert>

  const all = [...data.matches, ...data.rescheduled]
  const openList = all.filter((m) => m.open)
  const filled = openList.filter((m) => m.pred || saver.ready(m.id)).length

  return (
    <div className="space-y-4">
      {group.leagues.length > 1 && (
        <div className="flex gap-2 overflow-x-auto scrollbar-none -mx-4 px-4" role="tablist" aria-label="Campeonatos">
          {group.leagues.map((l) => {
            const active = l.id === data.leagueId
            return (
              <button
                key={l.id}
                role="tab"
                aria-selected={active}
                onClick={() => go({ liga: l.id, rodada: undefined })}
                className={`shrink-0 inline-flex items-center gap-2 h-10 px-4 rounded-full border text-sm font-semibold transition ${
                  active ? 'bg-ink text-bg border-ink' : 'bg-surface border-line text-ink-soft hover:border-line-strong'
                }`}
              >
                <Flag code={l.country} className="w-5 h-[13px]" />
                {l.logo && <img src={l.logo} alt="" width={18} height={18} className="size-[18px] object-contain" loading="lazy" referrerPolicy="no-referrer" />}
                {l.short}
              </button>
            )
          })}
        </div>
      )}

      {params.get('bemvindo') && (
        <section className="card p-5 space-y-3 border-accent/40 animate-rise">
          <div className="flex items-start justify-between gap-3">
            <h2 className="text-lg font-bold">🎉 Bolão criado! Mande o convite para a galera.</h2>
            <button className="btn-ghost btn-sm" onClick={() => go({ bemvindo: undefined })}>Fechar</button>
          </div>
          <InvitePanel group={group} />
        </section>
      )}

      {data.over && <Alert tone="info">Temporada encerrada. Os resultados ficam aqui para consulta.</Alert>}

      <RoundBanner data={data} groupId={group.id} filled={filled} openCount={openList.length} onRound={(r) => go({ rodada: r })} fetching={q.isFetching} />

      {data.matches.length === 0 ? (
        <EmptyState icon={<CalendarClock className="size-6" />} title="A tabela desta rodada ainda não saiu">
          A sincronização traz os jogos sozinha. Tente outra rodada.
        </EmptyState>
      ) : (
        <ol className="grid gap-3 lg:grid-cols-2">
          {data.matches.map((m, i) => (
            <MatchCard key={m.id} m={m} index={i} saver={saver} deadlineMode={data.deadlineMode} />
          ))}
        </ol>
      )}

      {data.rescheduled.length > 0 && (
        <section className="space-y-3 pt-2">
          <div>
            <h3 className="font-bold flex items-center gap-2"><CalendarClock className="size-5 text-accent-ink" />Jogos remarcados</h3>
            <p className="text-sm text-ink-faint">Jogos de outras rodadas que vão ser disputados nesta semana.</p>
          </div>
          <ol className="grid gap-3 lg:grid-cols-2">
            {data.rescheduled.map((m, i) => (
              <MatchCard key={m.id} m={m} index={i} saver={saver} deadlineMode={data.deadlineMode} />
            ))}
          </ol>
        </section>
      )}

      {openList.length > 0 && <SaveBar filled={filled} total={openList.length} saver={saver} deadlineMode={data.deadlineMode} />}
    </div>
  )
}

/* ---------- Salvamento automático ---------- */

function useAutosave(groupId: number, queryKey: unknown[]) {
  const qc = useQueryClient()
  const toast = useToast()
  const [drafts, setDrafts] = useState<Record<number, Draft>>({})
  const [states, setStates] = useState<Record<number, SaveState>>({})
  const draftsRef = useRef(drafts)
  draftsRef.current = drafts
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const inflight = useRef(false)

  const flush = useCallback(async () => {
    if (inflight.current) {
      timer.current = setTimeout(flush, 300)
      return
    }
    const ready = Object.entries(draftsRef.current).filter(([id, d]) => d.home !== '' && d.away !== '' && states[Number(id)]?.status !== 'saved')
    if (!ready.length) return
    const preds: Record<string, Draft> = {}
    for (const [id, d] of ready) preds[id] = d
    inflight.current = true
    setStates((s) => {
      const n = { ...s }
      for (const id of Object.keys(preds)) n[Number(id)] = { status: 'saving' }
      return n
    })
    try {
      const r = await apiPost<{ saved: number[]; skipped: { id: number; reason: string }[] }>('group/predict', { id: groupId, preds })
      setStates((s) => {
        const n = { ...s }
        for (const id of r.saved) n[id] = { status: 'saved' }
        for (const sk of r.skipped) n[sk.id] = { status: 'error', message: sk.reason }
        return n
      })
      // Atualiza o palpite guardado sem esperar a próxima leitura.
      qc.setQueryData<RoundData>(queryKey, (old) => {
        if (!old) return old
        const upd = (m: Match) => (r.saved.includes(m.id) ? { ...m, pred: { home: Number(preds[m.id].home), away: Number(preds[m.id].away) } } : m)
        return { ...old, matches: old.matches.map(upd), rescheduled: old.rescheduled.map(upd) }
      })
      setDrafts((d) => {
        const n = { ...d }
        for (const id of r.saved) if (n[id]?.home === preds[id].home && n[id]?.away === preds[id].away) delete n[id]
        return n
      })
      if (r.skipped.length) toast(`Não salvo: ${r.skipped[0].reason}.`, 'error')
      qc.invalidateQueries({ queryKey: ['groups'] })
      qc.invalidateQueries({ queryKey: ['round', groupId], refetchType: 'none' })
    } catch (e) {
      const msg = (e as ApiError).message
      setStates((s) => {
        const n = { ...s }
        for (const id of Object.keys(preds)) n[Number(id)] = { status: 'error', message: msg }
        return n
      })
      toast(msg, 'error')
    } finally {
      inflight.current = false
    }
  }, [groupId, qc, queryKey, states, toast])

  const flushRef = useRef(flush)
  flushRef.current = flush

  const set = useCallback((id: number, d: Draft) => {
    setDrafts((all) => ({ ...all, [id]: d }))
    setStates((s) => ({ ...s, [id]: { status: 'dirty' } }))
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => flushRef.current(), 700)
  }, [])

  // Sai da página com algo por salvar: tenta salvar e avisa o navegador.
  useEffect(() => {
    const onLeave = (e: BeforeUnloadEvent) => {
      const pending = Object.values(draftsRef.current).some((d) => d.home !== '' && d.away !== '')
      if (pending) {
        flushRef.current()
        e.preventDefault()
      }
    }
    window.addEventListener('beforeunload', onLeave)
    return () => {
      window.removeEventListener('beforeunload', onLeave)
      if (timer.current) {
        clearTimeout(timer.current)
        flushRef.current()
      }
    }
  }, [])

  const busy = Object.values(states).some((s) => s.status === 'saving' || s.status === 'dirty')
  const errors = Object.values(states).filter((s) => s.status === 'error').length
  return {
    drafts,
    states,
    set,
    busy,
    errors,
    retry: () => flushRef.current(),
    ready: (id: number) => !!drafts[id] && drafts[id].home !== '' && drafts[id].away !== '',
  }
}
type Saver = ReturnType<typeof useAutosave>

/* ---------- Cabeçalho da rodada ---------- */

function RoundBanner({
  data, groupId, filled, openCount, onRound, fetching,
}: { data: RoundData; groupId: number; filled: number; openCount: number; onRound: (r: number) => void; fetching: boolean }) {
  const now = useNow()
  const idx = data.rounds.findIndex((r) => r.number === data.round)
  const prev = idx > 0 ? data.rounds[idx - 1] : null
  const next = idx >= 0 && idx < data.rounds.length - 1 ? data.rounds[idx + 1] : null
  const isCurrent = data.round === data.current && !data.over
  const stripRef = useRef<HTMLOListElement>(null)

  useEffect(() => {
    const el = stripRef.current?.querySelector('[aria-current="true"]') as HTMLElement | null
    el?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' })
  }, [data.round, data.leagueId])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement
      if (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA') return
      if (e.key === 'ArrowLeft' && prev) onRound(prev.number)
      if (e.key === 'ArrowRight' && next) onRound(next.number)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [prev, next, onRound])

  const tone = {
    aberta: 'from-accent/25 via-accent/5',
    aguardando: 'from-warning/20 via-warning/5',
    encerrada: 'from-success/20 via-success/5',
    bloqueada: 'from-ink-faint/15 via-transparent',
  }[data.state]

  return (
    <section className={`card overflow-hidden bg-gradient-to-br ${tone} to-transparent`} aria-labelledby="round-title">
      <div className="p-4 sm:p-5">
        <div className="flex items-center gap-2">
          <StepButton round={prev} dir="prev" onRound={onRound} />
          <div className="flex-1 text-center min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider text-ink-faint truncate">
              {data.leagueName}
              {isCurrent && <span className="text-accent-ink"> · rodada atual</span>}
            </p>
            <h2 id="round-title" className="display text-3xl sm:text-4xl font-black leading-tight flex items-center justify-center gap-2">
              {data.label}
              {fetching && <RefreshCw className="size-4 animate-spin text-ink-faint" aria-label="Atualizando" />}
            </h2>
          </div>
          <StepButton round={next} dir="next" onRound={onRound} />
        </div>

        <div className="mt-3 flex items-center justify-center gap-4">
          {openCount > 0 && (
            <div className="relative grid place-items-center">
              <ProgressRing value={filled} total={openCount} />
              <span className="absolute text-xs font-bold tabular">{filled}/{openCount}</span>
            </div>
          )}
          <p className="text-sm font-medium text-ink-soft text-center">
            {data.state === 'aberta' && data.deadline ? (
              <>
                <span className="inline-block size-2 rounded-full bg-success mr-2 animate-pulse-live align-middle" />
                Palpites abertos · {data.deadlineMode === 'rodada' ? 'fecham' : 'próximo fecha'}{' '}
                <strong className="text-ink">{countdown(data.deadline, now)}</strong>
              </>
            ) : data.state === 'aguardando' ? (
              <><Clock className="size-4 inline -mt-0.5 mr-1" />Palpites fechados · aguardando resultados</>
            ) : data.state === 'encerrada' ? (
              <>
                <Trophy className="size-4 inline -mt-0.5 mr-1 text-gold" />Encerrada
                {data.roundMe ? (
                  <> · você fez <strong className="text-ink">{plural(data.roundMe.total, 'ponto', 'pontos')}</strong> · {data.roundMe.pos}º na rodada</>
                ) : ' · você não palpitou'}
                {' · '}
                <Link className="font-semibold text-accent-ink hover:underline" to={`/bolao/${groupId}/ranking?vista=rodada&rodada=${data.leagueId}-${data.round}`}>ver ranking</Link>
              </>
            ) : (
              <><Lock className="size-4 inline -mt-0.5 mr-1" />{data.lockedText ?? 'Ainda fechada para palpites'}</>
            )}
          </p>
        </div>
      </div>

      {data.rounds.length > 1 && (
        <nav aria-label="Rodadas" className="border-t border-line bg-surface/60">
          <ol ref={stripRef} className="flex gap-1.5 overflow-x-auto scrollbar-none px-3 py-2.5 snap-x">
            {data.rounds.map((r) => (
              <li key={r.number} className="snap-center">
                <RoundChip r={r} active={r.number === data.round} current={r.number === data.current} onClick={() => onRound(r.number)} />
              </li>
            ))}
          </ol>
        </nav>
      )}
    </section>
  )
}

function StepButton({ round, dir, onRound }: { round: RoundInfo | null; dir: 'prev' | 'next'; onRound: (r: number) => void }) {
  const Icon = dir === 'prev' ? ChevronLeft : ChevronRight
  return (
    <button
      disabled={!round}
      onClick={() => round && onRound(round.number)}
      aria-label={round ? `${dir === 'prev' ? 'Anterior' : 'Próxima'}: ${round.label}` : undefined}
      className="size-11 grid place-items-center rounded-2xl bg-surface border border-line hover:border-line-strong disabled:opacity-30 transition active:scale-95"
    >
      <Icon className="size-5" />
    </button>
  )
}

function RoundChip({ r, active, current, onClick }: { r: RoundInfo; active: boolean; current: boolean; onClick: () => void }) {
  const dot =
    r.state === 'encerrada' ? 'bg-success' : r.state === 'aberta' ? (r.stats.pending ? 'bg-warning' : 'bg-accent') : r.state === 'aguardando' ? 'bg-sky-400' : ''
  const stateText =
    r.state === 'encerrada' ? 'encerrada' : r.state === 'bloqueada' ? 'ainda fechada' : r.state === 'aguardando' ? 'aguardando resultados' : r.stats.pending ? `faltam ${plural(r.stats.pending, 'palpite', 'palpites')}` : 'palpites em dia'
  return (
    <button
      onClick={onClick}
      aria-current={active ? 'true' : undefined}
      title={r.label}
      className={`relative min-w-11 h-11 px-2.5 rounded-xl text-sm font-bold tabular transition flex items-center justify-center gap-1 ${
        active ? 'bg-accent text-on-accent shadow-lg scale-105' : current ? 'bg-accent-soft text-accent-ink ring-1 ring-accent/40' : 'bg-surface-2 text-ink-soft hover:bg-surface-3'
      }`}
    >
      {r.state === 'bloqueada' && <Lock className="size-3 opacity-60" />}
      {r.chip}
      {dot && !active && <span className={`absolute top-1 right-1 size-1.5 rounded-full ${dot}`} />}
      <span className="sr-only">, {r.label}, {stateText}</span>
    </button>
  )
}

/* ---------- Cartão do jogo ---------- */

function MatchCard({ m, index, saver, deadlineMode }: { m: Match; index: number; saver: Saver; deadlineMode: string }) {
  const now = useNow(m.open && m.closeAt - Date.now() / 1000 < 3600 ? 10_000 : 30_000)
  const draft = saver.drafts[m.id]
  const state = saver.states[m.id]
  const home = draft ? draft.home : m.pred ? String(m.pred.home) : ''
  const away = draft ? draft.away : m.pred ? String(m.pred.away) : ''
  const open = m.open && m.closeAt > now
  const soon = open && m.closeAt - now < 3600
  const finished = !!m.score
  const [showOthers, setShowOthers] = useState(false)

  const ring = finished
    ? m.exact ? 'ring-2 ring-gold/70' : (m.points ?? 0) > 0 ? 'ring-1 ring-success/50' : ''
    : m.live ? 'ring-2 ring-live/60'
    : open && !m.pred && !saver.ready(m.id) ? 'ring-1 ring-warning/50'
    : ''

  const when = open || m.locked ? dayClock(m.kickoff, now) : `${dayMonth(m.kickoff)}, ${clock(m.kickoff)}`

  return (
    <li id={`jogo-${m.id}`} className={`card overflow-hidden animate-rise ${ring}`} style={{ animationDelay: `${Math.min(index, 8) * 40}ms` }}>
      <div className="flex items-center justify-between gap-2 px-4 pt-3.5 text-[13px]">
        <span className="text-ink-faint font-medium truncate">
          {when}
          {m.note && ` · ${m.note}`}
          {m.origin && ` · da ${m.origin}`}
        </span>
        <StatusBadge m={m} open={open} state={state} hasPick={!!m.pred || saver.ready(m.id)} />
      </div>

      <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2 px-3 py-4">
        <TeamSide team={m.home} />
        {open ? (
          <div className="flex items-center gap-1.5" role="group" aria-label={`Seu palpite para ${m.home.name} × ${m.away.name}`}>
            <ScoreInput id={`h-${m.id}`} label={`Gols do ${m.home.name}`} value={home} onChange={(v) => saver.set(m.id, { home: v, away })} />
            <span className="display text-2xl font-black text-ink-faint">:</span>
            <ScoreInput id={`a-${m.id}`} label={`Gols do ${m.away.name}`} value={away} onChange={(v) => saver.set(m.id, { home, away: v })} />
          </div>
        ) : (
          <Board m={m} />
        )}
        <TeamSide team={m.away} />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-t border-line bg-surface-2/60 text-[13px]">
        {open ? (
          <p className={`flex items-center gap-1.5 font-medium ${soon ? 'text-warning' : 'text-ink-soft'}`}>
            <Clock className="size-4" />
            {state?.status === 'error' ? <span className="text-danger">Não salvo: {state.message}</span> : <>Fecha {countdown(m.closeAt, now)}</>}
          </p>
        ) : finished || m.live ? (
          <>
            <p className="text-ink-soft">
              {m.pred ? <>Seu palpite <strong className="display text-base text-ink tabular">{m.pred.home} × {m.pred.away}</strong></> : 'Você não palpitou'}
            </p>
            {finished && <PointsPill m={m} />}
          </>
        ) : m.locked ? (
          <p className="flex items-center gap-1.5 text-ink-soft">
            <Lock className="size-4" />{m.lockText}
            {m.pred && <span className="text-ink-faint">· palpite {m.pred.home}×{m.pred.away} guardado</span>}
          </p>
        ) : (
          <p className="flex items-center gap-1.5 text-ink-soft">
            <Lock className="size-4" />Fechou às {clock(m.closeAt)}
            <span className="text-ink-faint">· {m.pred ? `seu palpite ${m.pred.home}×${m.pred.away}` : 'você não palpitou'}</span>
          </p>
        )}
        {m.others && (
          <button onClick={() => setShowOthers((s) => !s)} aria-expanded={showOthers} className="inline-flex items-center gap-1 font-semibold text-accent-ink hover:underline">
            <Eye className="size-4" />Galera ({m.others.filter((o) => o.home_pred !== null).length})
            <ChevronDown className={`size-4 transition ${showOthers ? 'rotate-180' : ''}`} />
          </button>
        )}
      </div>
      {showOthers && m.others && <Others m={m} />}
      {open && deadlineMode === 'rodada' && m.closeAt < m.kickoff && (
        <p className="px-4 pb-3 -mt-1 text-xs text-ink-faint">Prazo da rodada: fecha no primeiro jogo.</p>
      )}
    </li>
  )
}

function TeamSide({ team }: { team: Match['home'] }) {
  return (
    <div className="flex flex-col items-center text-center gap-1.5 min-w-0">
      <Crest team={team} size={48} />
      <span className="text-sm font-semibold leading-tight line-clamp-2 break-words max-w-full">{team.short}</span>
    </div>
  )
}

function Board({ m }: { m: Match }) {
  const s = m.score ?? m.liveScore
  const tile = 'w-12 h-14 rounded-xl grid place-items-center display text-4xl font-black tabular'
  if (!s)
    return (
      <div className="flex items-center gap-1.5" aria-label={m.pred ? `Seu palpite: ${m.pred.home} a ${m.pred.away}` : 'Sem palpite'}>
        <span className={`${tile} bg-surface-3 text-ink-faint`}>{m.pred ? m.pred.home : '–'}</span>
        <span className="display text-2xl font-black text-ink-faint">:</span>
        <span className={`${tile} bg-surface-3 text-ink-faint`}>{m.pred ? m.pred.away : '–'}</span>
      </div>
    )
  return (
    <div className="flex items-center gap-1.5" aria-label={`${m.live ? 'Placar parcial' : 'Placar final'}: ${s[0]} a ${s[1]}`}>
      <span className={`${tile} bg-board text-board-ink shadow-inner ${m.live ? 'text-live' : ''}`}>{s[0]}</span>
      <span className={`display text-2xl font-black ${m.live ? 'text-live animate-pulse' : 'text-ink-faint'}`}>:</span>
      <span className={`${tile} bg-board text-board-ink shadow-inner ${m.live ? 'text-live' : ''}`}>{s[1]}</span>
    </div>
  )
}

function ScoreInput({ id, label, value, onChange }: { id: string; label: string; value: string; onChange: (v: string) => void }) {
  const n = value === '' ? null : Number(value)
  const step = (d: number) => onChange(String(Math.min(30, Math.max(0, (n ?? (d > 0 ? -1 : 1)) + d))))
  const focusNext = (el: HTMLInputElement) => {
    const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[data-score]'))
    const i = inputs.indexOf(el)
    inputs[i + 1]?.focus()
  }
  return (
    <div className="flex flex-col items-center gap-1">
      <button type="button" tabIndex={-1} onClick={() => step(1)} aria-label={`Mais um: ${label}`} className="h-7 w-12 grid place-items-center rounded-lg text-ink-faint hover:text-accent-ink hover:bg-accent-soft active:scale-90 transition">
        <Plus className="size-4" />
      </button>
      <input
        id={id}
        data-score
        aria-label={label}
        inputMode="numeric"
        pattern="[0-9]*"
        autoComplete="off"
        enterKeyHint="next"
        maxLength={2}
        placeholder="–"
        value={value}
        onFocus={(e) => e.target.select()}
        onChange={(e) => {
          const v = e.target.value.replace(/\D/g, '').slice(0, 2)
          if (v !== '' && Number(v) > 30) return
          onChange(v === '' ? '' : String(Number(v)))
          if (v.length === 1 && v !== '1' && v !== '2' && v !== '3') focusNext(e.target)
          if (v.length === 2) focusNext(e.target)
        }}
        onKeyDown={(e) => {
          if (e.key === 'ArrowUp') { e.preventDefault(); step(1) }
          if (e.key === 'ArrowDown') { e.preventDefault(); step(-1) }
          if (e.key === 'Enter') { e.preventDefault(); focusNext(e.currentTarget) }
        }}
        className={`w-14 h-16 rounded-2xl text-center display text-4xl font-black tabular outline-none transition border-2 ${
          value === '' ? 'bg-surface-2 border-dashed border-line-strong text-ink-faint' : 'bg-board text-board-ink border-transparent'
        } focus:border-accent focus:ring-4 focus:ring-accent-soft`}
      />
      <button type="button" tabIndex={-1} onClick={() => step(-1)} aria-label={`Menos um: ${label}`} className="h-7 w-12 grid place-items-center rounded-lg text-ink-faint hover:text-accent-ink hover:bg-accent-soft active:scale-90 transition">
        <Minus className="size-4" />
      </button>
    </div>
  )
}

function StatusBadge({ m, open, state, hasPick }: { m: Match; open: boolean; state?: SaveState; hasPick: boolean }) {
  const base = 'chip h-6 shrink-0'
  if (open) {
    if (state?.status === 'saving' || state?.status === 'dirty')
      return <span className={`${base} text-accent-ink`}><RefreshCw className="size-3 animate-spin" />Salvando…</span>
    if (state?.status === 'error') return <span className={`${base} bg-danger-soft text-danger border-danger/30`}><CloudOff className="size-3" />Não salvo</span>
    if (state?.status === 'saved') return <span className={`${base} bg-success-soft text-success border-success/30 animate-pop`}><Check className="size-3" />Salvo</span>
    if (hasPick) return <span className={`${base} bg-success-soft text-success border-success/30`}><Check className="size-3" />Palpite salvo</span>
    return <span className={`${base} bg-warning-soft text-warning border-warning/30`}><AlertTriangle className="size-3" />Falta palpitar</span>
  }
  if (m.score) return <span className={base}>Encerrado</span>
  if (m.live)
    return (
      <span className={`${base} bg-live text-white border-live`}>
        <span className="size-1.5 rounded-full bg-white animate-pulse-live" />Ao vivo{m.statusDetail ? ` · ${m.statusDetail}` : ''}
      </span>
    )
  if (m.locked) return <span className={base}><Lock className="size-3" />{m.reason === 'deadline' ? 'Prazo encerrado' : 'Bloqueado'}</span>
  return <span className={base}><Lock className="size-3" />Fechado</span>
}

function PointsPill({ m }: { m: Match }) {
  const pts = m.points ?? 0
  if (m.exact)
    return (
      <span className="inline-flex items-center gap-1.5 rounded-full px-3 h-8 font-bold text-[#3b2a00] bg-gradient-to-r from-amber-300 to-yellow-400 shadow-[0_0_20px_-4px] shadow-amber-400 animate-pop">
        <Sparkles className="size-4" />+{pts} · Placar exato!
      </span>
    )
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-3 h-8 font-bold ${pts > 0 ? 'bg-success-soft text-success' : 'bg-surface-3 text-ink-faint'}`}>
      {pts > 0 ? `+${pts}` : '0'} <span className="font-medium">{m.pred ? m.pointsReason : 'sem palpite'}</span>
    </span>
  )
}

function Others({ m }: { m: Match }) {
  const finished = !!m.score
  const rows = m.others ?? []
  const picks = rows.filter((r) => r.home_pred !== null)
  const stats = useMemo(() => {
    const t = { home: 0, draw: 0, away: 0 }
    for (const r of picks) {
      const d = (r.home_pred ?? 0) - (r.away_pred ?? 0)
      if (d > 0) t.home++
      else if (d < 0) t.away++
      else t.draw++
    }
    return t
  }, [picks])
  const pct = (n: number) => (picks.length ? Math.round((n * 100) / picks.length) : 0)
  return (
    <div className="px-4 pb-4 pt-1 bg-surface-2/60 animate-rise">
      {picks.length > 0 && (
        <div className="mb-3">
          <div className="flex h-2.5 rounded-full overflow-hidden bg-surface-3">
            <span className="bg-accent" style={{ width: `${pct(stats.home)}%` }} />
            <span className="bg-ink-faint" style={{ width: `${pct(stats.draw)}%` }} />
            <span className="bg-sky-400" style={{ width: `${pct(stats.away)}%` }} />
          </div>
          <div className="mt-1.5 flex justify-between text-[11px] font-semibold text-ink-faint">
            <span>{m.home.short} {pct(stats.home)}%</span>
            <span>Empate {pct(stats.draw)}%</span>
            <span>{m.away.short} {pct(stats.away)}%</span>
          </div>
        </div>
      )}
      <ul className="divide-y divide-line rounded-2xl bg-surface border border-line">
        {rows.map((r) => (
          <li key={r.user_id} className={`flex items-center gap-3 px-3 py-2 text-sm ${r.home_pred === null ? 'opacity-50' : ''}`}>
            <span className="flex-1 truncate font-medium">{r.name}</span>
            <span className="display text-lg font-bold tabular">{r.home_pred === null ? '–' : `${r.home_pred} × ${r.away_pred}`}</span>
            {finished && (
              <span className={`w-12 text-right font-bold tabular ${(r.points ?? 0) > 0 ? 'text-success' : 'text-ink-faint'}`}>
                {r.points ?? 0} pt
              </span>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}

function SaveBar({ filled, total, saver, deadlineMode }: { filled: number; total: number; saver: Saver; deadlineMode: string }) {
  const done = filled === total
  return (
    <div className={done && !saver.busy && !saver.errors ? '' : 'sticky bottom-[calc(76px+env(safe-area-inset-bottom))] md:bottom-4 z-20'}>
      <div className="card bg-glass backdrop-blur-xl px-4 py-3 flex items-center gap-3 shadow-pop">
        <div className="flex-1 min-w-0">
          <div className="flex items-center justify-between text-sm">
            <span><strong className="tabular">{filled}</strong> de <strong className="tabular">{total}</strong> palpites</span>
            <span className="text-xs text-ink-faint">
              {saver.busy ? 'Salvando…' : saver.errors ? `${plural(saver.errors, 'palpite não salvo', 'palpites não salvos')}` : 'Salvo automaticamente'}
            </span>
          </div>
          <div className="mt-2 h-1.5 rounded-full bg-surface-3 overflow-hidden">
            <div className={`h-full rounded-full transition-all duration-500 ${done ? 'bg-success' : 'bg-accent'}`} style={{ width: `${(filled * 100) / total}%` }} />
          </div>
          <p className="mt-1.5 text-[11px] text-ink-faint">{deadlineMode === 'rodada' ? 'Dá para mudar até o primeiro jogo da rodada.' : 'Dá para mudar até cada jogo começar.'}</p>
        </div>
        {saver.errors > 0 && (
          <button className="btn-ghost btn-sm" onClick={saver.retry}><RefreshCw className="size-4" />Tentar de novo</button>
        )}
        {done && !saver.busy && !saver.errors && <span className="size-10 grid place-items-center rounded-full bg-success text-white animate-pop"><Check className="size-5" /></span>}
      </div>
    </div>
  )
}
