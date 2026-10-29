import { useState, type FormEvent } from 'react'
import { Navigate, NavLink, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, RefreshCw, Search, Trash2 } from 'lucide-react'
import { apiGet, apiPost, ApiError } from '../../lib/api'
import { useUser } from '../../lib/session'
import type { League, Team } from '../../lib/types'
import { clock, dayMonth, plural } from '../../lib/format'
import { Alert, EmptyState, Field, Skeleton, Spinner } from '../../components/ui'
import { Crest } from '../../components/Crest'
import { LeagueMark } from '../../components/Flag'
import { ConfirmButton } from '../../components/Confirm'
import { useToast } from '../../components/Toast'

const TABS = [
  ['resultados', 'Resultados'],
  ['sincronizacao', 'Sincronização'],
  ['importar', 'Importar'],
  ['ligas', 'Ligas'],
  ['usuarios', 'Usuários'],
] as const

type AdminLeague = League & { code: string; active: boolean; auto: boolean }

function useLeagues() {
  return useQuery({ queryKey: ['admin', 'leagues'], queryFn: () => apiGet<{ leagues: AdminLeague[] }>('admin/leagues') })
}

export default function Admin() {
  const user = useUser()!
  const { tab = 'resultados' } = useParams()
  if (!user.isAdmin) return <Alert>Só a administração do site pode abrir esta página.</Alert>
  if (!TABS.some(([k]) => k === tab)) return <Navigate to="/admin" replace />
  return (
    <div className="space-y-5 animate-rise">
      <div>
        <p className="text-sm font-semibold text-accent-ink">Só para a administração do site</p>
        <h1 className="text-3xl font-black tracking-tight">Administração</h1>
      </div>
      <nav className="flex gap-1 p-1 rounded-2xl bg-surface-2 border border-line overflow-x-auto scrollbar-none" aria-label="Seções da administração">
        {TABS.map(([k, label]) => (
          <NavLink
            key={k}
            to={k === 'resultados' ? '/admin' : `/admin/${k}`}
            end
            className={({ isActive }) => `flex-1 min-w-fit px-3 h-10 inline-flex items-center justify-center rounded-xl text-sm font-semibold whitespace-nowrap ${isActive ? 'bg-surface shadow-card' : 'text-ink-soft'}`}
          >
            {label}
          </NavLink>
        ))}
      </nav>
      {tab === 'resultados' && <Results />}
      {tab === 'sincronizacao' && <Sync />}
      {tab === 'importar' && <Import />}
      {tab === 'ligas' && <Leagues />}
      {tab === 'usuarios' && <Users />}
    </div>
  )
}

type AdminMatch = { id: number; league: string; round: number; kickoff: number; home: Team; away: Team; score: [number, number] | null; auto: boolean }

function Results() {
  const leagues = useLeagues()
  const [league, setLeague] = useState(0)
  const [round, setRound] = useState('')
  const q = useQuery({
    queryKey: ['admin', 'results', league, round],
    queryFn: () => apiGet<{ waiting: AdminMatch[]; upcoming: AdminMatch[]; finished: AdminMatch[] }>('admin/results', { league, round }),
    placeholderData: (p) => p,
  })
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <select className="input w-auto flex-1 min-w-48" value={league} onChange={(e) => setLeague(Number(e.target.value))} aria-label="Liga">
          <option value={0}>Todas as ligas</option>
          {leagues.data?.leagues.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
        </select>
        <input className="input w-32" inputMode="numeric" placeholder="Rodada" value={round} onChange={(e) => setRound(e.target.value.replace(/\D/g, ''))} aria-label="Rodada" />
      </div>
      {q.isLoading || !q.data ? <Skeleton className="h-80" /> : (
        <>
          <MatchList title="Aguardando placar" list={q.data.waiting} empty="Nenhum jogo esperando placar." />
          <MatchList title="Encerrados (mais recentes)" list={q.data.finished} empty="Nenhum jogo encerrado." />
          <MatchList title="Próximos" list={q.data.upcoming} empty="Nenhum jogo marcado." readOnly />
        </>
      )}
    </div>
  )
}

function MatchList({ title, list, empty, readOnly }: { title: string; list: AdminMatch[]; empty: string; readOnly?: boolean }) {
  return (
    <section className="space-y-2">
      <h2 className="font-bold">{title} <span className="text-ink-faint font-medium">({list.length})</span></h2>
      {list.length === 0 ? <p className="text-sm text-ink-faint">{empty}</p> : (
        <ul className="card divide-y divide-line">
          {list.map((m) => <ScoreRow key={m.id} m={m} readOnly={readOnly} />)}
        </ul>
      )}
    </section>
  )
}

function ScoreRow({ m, readOnly }: { m: AdminMatch; readOnly?: boolean }) {
  const qc = useQueryClient()
  const toast = useToast()
  const [h, setH] = useState(m.score ? String(m.score[0]) : '')
  const [a, setA] = useState(m.score ? String(m.score[1]) : '')
  const [busy, setBusy] = useState(false)
  const save = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    try {
      const r = await apiPost<{ message: string }>('admin/score', { matchId: m.id, home: h, away: a })
      toast(r.message)
      qc.invalidateQueries({ queryKey: ['admin', 'results'] })
    } catch (x) {
      toast((x as ApiError).message, 'error')
    } finally {
      setBusy(false)
    }
  }
  const num = 'w-11 h-11 rounded-xl text-center display text-2xl font-black bg-surface-2 border border-line-strong outline-none focus:border-accent'
  return (
    <li className="px-3 py-3 flex flex-wrap items-center gap-3">
      <div className="text-xs text-ink-faint w-28 shrink-0">
        <p className="font-semibold text-ink-soft truncate">{m.league}</p>
        <p>R{m.round} · {dayMonth(m.kickoff)} {clock(m.kickoff)}{m.auto && ' · ESPN'}</p>
      </div>
      <form onSubmit={save} className="flex-1 flex items-center justify-center gap-2 min-w-64">
        <span className="flex-1 flex items-center justify-end gap-2 text-sm font-semibold min-w-0"><span className="truncate">{m.home.short}</span><Crest team={m.home} size={28} /></span>
        {readOnly ? <span className="text-ink-faint">×</span> : (
          <>
            <input className={num} inputMode="numeric" value={h} onChange={(e) => setH(e.target.value.replace(/\D/g, '').slice(0, 2))} aria-label={`Gols ${m.home.name}`} />
            <span className="text-ink-faint">×</span>
            <input className={num} inputMode="numeric" value={a} onChange={(e) => setA(e.target.value.replace(/\D/g, '').slice(0, 2))} aria-label={`Gols ${m.away.name}`} />
          </>
        )}
        <span className="flex-1 flex items-center gap-2 text-sm font-semibold min-w-0"><Crest team={m.away} size={28} /><span className="truncate">{m.away.short}</span></span>
        {!readOnly && <button className="btn-primary btn-sm" disabled={busy || h === '' || a === ''}>{busy ? <Spinner className="size-4" /> : <Check className="size-4" />}</button>}
      </form>
      {m.score && !readOnly && (
        <ConfirmButton
          className="size-9 grid place-items-center rounded-xl text-ink-faint hover:text-danger hover:bg-danger-soft"
          label={<Trash2 className="size-4" aria-label="Apagar resultado" />}
          title="Apagar o resultado?"
          confirmLabel="Apagar"
          onConfirm={async () => {
            const r = await apiPost<{ message: string }>('admin/clear-score', { matchId: m.id })
            toast(r.message)
            qc.invalidateQueries({ queryKey: ['admin', 'results'] })
          }}
        >
          Os pontos deste jogo voltam a zero até lançar de novo.
        </ConfirmButton>
      )}
    </li>
  )
}

type SyncInfo = {
  lastQuick: number | null
  lastFull: number | null
  lastError: string | null
  detached: boolean
  leagues: { id: number; name: string; games: number; quick: number | null; full: number | null; failures: number }[]
}

function Sync() {
  const qc = useQueryClient()
  const toast = useToast()
  const q = useQuery({ queryKey: ['admin', 'sync'], queryFn: () => apiGet<SyncInfo>('admin/sync'), refetchInterval: 30_000 })
  const [busy, setBusy] = useState(false)
  const when = (t: number | null) => (t ? `${dayMonth(t)} ${clock(t)}` : 'nunca')
  const leagues = useLeagues()
  const [f, setF] = useState({ leagueId: 0, round: '', home: '', away: '', kickoff: '' })
  const [err, setErr] = useState<ApiError | null>(null)
  return (
    <div className="grid gap-4 lg:grid-cols-2 items-start">
      <section className="card p-5 space-y-4">
        <h2 className="font-bold text-lg">Jogos automáticos (ESPN)</h2>
        <p className="text-sm text-ink-soft">
          Jogos e placares das ligas que estão em algum bolão se atualizam sozinhos: os jogos do momento a cada 5 minutos (a cada minuto com jogo ao vivo) e a tabela de cada liga a cada 6 horas.
          {q.data && !q.data.detached && ' Este servidor não libera a resposta antes de sincronizar, então só a parte rápida roda sozinha.'}
        </p>
        {q.data && (
          <>
            <p className="text-sm">Última atualização: <strong>{when(q.data.lastQuick)}</strong> · Tabela completa: <strong>{when(q.data.lastFull)}</strong></p>
            {q.data.lastError && <Alert>Último erro: {q.data.lastError}</Alert>}
            {q.data.leagues.length ? (
              <div className="overflow-x-auto rounded-2xl border border-line">
                <table className="w-full text-sm">
                  <thead className="bg-surface-2 text-[11px] uppercase text-ink-faint"><tr><th className="text-left p-2.5">Liga</th><th className="p-2.5">Jogos</th><th className="p-2.5">Rápida</th><th className="p-2.5">Completa</th><th className="p-2.5">Falhas</th></tr></thead>
                  <tbody className="divide-y divide-line">
                    {q.data.leagues.map((l) => (
                      <tr key={l.id}><th className="text-left p-2.5 font-semibold">{l.name}</th><td className="p-2.5 text-center tabular">{l.games}</td><td className="p-2.5 text-center">{when(l.quick)}</td><td className="p-2.5 text-center">{when(l.full)}</td><td className={`p-2.5 text-center ${l.failures ? 'text-danger font-bold' : ''}`}>{l.failures}</td></tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : <p className="text-sm text-ink-faint">Nenhuma liga em uso ainda.</p>}
          </>
        )}
        <button
          className="btn-primary"
          disabled={busy}
          onClick={async () => {
            setBusy(true)
            try {
              const r = await apiPost<{ message: string }>('admin/sync')
              toast(r.message)
              qc.invalidateQueries({ queryKey: ['admin'] })
            } catch (x) {
              toast((x as ApiError).message, 'error')
            } finally {
              setBusy(false)
            }
          }}
        >
          {busy ? <Spinner /> : <RefreshCw className="size-4" />}Sincronizar agora
        </button>
      </section>
      <section className="card p-5 space-y-4">
        <h2 className="font-bold text-lg">Cadastrar jogo à mão</h2>
        <form
          className="space-y-3"
          onSubmit={async (e) => {
            e.preventDefault()
            setErr(null)
            try {
              const r = await apiPost<{ message: string }>('admin/match', f)
              toast(r.message)
              setF({ ...f, home: '', away: '' })
            } catch (x) {
              setErr(x as ApiError)
            }
          }}
        >
          <div>
            <label className="label" htmlFor="m-league">Liga</label>
            <select id="m-league" className="input" value={f.leagueId} onChange={(e) => setF({ ...f, leagueId: Number(e.target.value) })}>
              <option value={0}>Escolha</option>
              {leagues.data?.leagues.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </select>
            {err?.fields.leagueId && <p className="text-[13px] text-danger mt-1">{err.fields.leagueId}</p>}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Rodada" name="round" inputMode="numeric" value={f.round} onChange={(e) => setF({ ...f, round: e.target.value })} error={err?.fields.round} />
            <Field label="Data e hora" name="kickoff" type="datetime-local" value={f.kickoff} onChange={(e) => setF({ ...f, kickoff: e.target.value })} error={err?.fields.kickoff} />
          </div>
          <Field label="Mandante" name="home" value={f.home} onChange={(e) => setF({ ...f, home: e.target.value })} error={err?.fields.home} />
          <Field label="Visitante" name="away" value={f.away} onChange={(e) => setF({ ...f, away: e.target.value })} error={err?.fields.away} />
          <button className="btn-primary">Cadastrar jogo</button>
        </form>
      </section>
    </div>
  )
}

type ImportRow = { line: number; raw: string; status: 'ok' | 'skip' | 'error'; message: string; round: number | null; kickoff: string | null; home: string; away: string }

function Import() {
  const leagues = useLeagues()
  const toast = useToast()
  const [leagueId, setLeagueId] = useState(0)
  const [text, setText] = useState('')
  const [preview, setPreview] = useState<{ rows: ImportRow[]; count: { ok: number; skip: number; error: number } } | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const send = async (save: boolean) => {
    setBusy(true)
    setErr(null)
    try {
      const r = await apiPost<{ preview?: typeof preview; message?: string }>('admin/import', { leagueId, text, save })
      if (r.message) {
        toast(r.message)
        setText('')
        setPreview(null)
      } else setPreview(r.preview ?? null)
    } catch (x) {
      setErr((x as ApiError).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <section className="card p-5 space-y-4">
      <p className="text-sm text-ink-soft">Uma linha por jogo, com 4 colunas separadas por ponto e vírgula (ou tabulação, ao colar de uma planilha): <code className="text-ink">rodada; data e hora; mandante; visitante</code>. Ex.: <code className="text-ink">33; 25/10/2026 16:00; Flamengo; Palmeiras</code></p>
      <select className="input" value={leagueId} onChange={(e) => { setLeagueId(Number(e.target.value)); setPreview(null) }} aria-label="Liga">
        <option value={0}>Escolha a liga</option>
        {leagues.data?.leagues.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
      </select>
      <textarea className="input h-48 py-3 font-mono text-sm" value={text} onChange={(e) => { setText(e.target.value); setPreview(null) }} aria-label="Jogos" placeholder="33; 25/10/2026 16:00; Flamengo; Palmeiras" />
      {err && <Alert>{err}</Alert>}
      <div className="flex gap-2">
        <button className="btn-ghost" disabled={busy} onClick={() => send(false)}>{busy ? <Spinner /> : 'Conferir linhas'}</button>
        {preview && preview.count.error === 0 && preview.count.ok > 0 && (
          <button className="btn-primary" disabled={busy} onClick={() => send(true)}>Gravar {plural(preview.count.ok, 'jogo', 'jogos')}</button>
        )}
      </div>
      {preview && (
        <div className="space-y-2">
          <p className="text-sm"><strong className="text-success">{preview.count.ok} ok</strong> · <strong className="text-ink-soft">{preview.count.skip} ignorados</strong> · <strong className="text-danger">{preview.count.error} com erro</strong></p>
          <ul className="rounded-2xl border border-line divide-y divide-line text-sm max-h-96 overflow-y-auto">
            {preview.rows.map((r) => (
              <li key={r.line} className={`px-3 py-2 flex gap-3 ${r.status === 'error' ? 'bg-danger-soft' : r.status === 'skip' ? 'opacity-60' : ''}`}>
                <span className="text-ink-faint tabular w-8">{r.line}</span>
                <span className="flex-1">{r.status === 'error' ? r.raw : `R${r.round} · ${r.kickoff?.slice(0, 16)} · ${r.home} × ${r.away}`}{r.message && <span className="block text-xs text-ink-soft">{r.message}</span>}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

function Leagues() {
  const q = useLeagues()
  const qc = useQueryClient()
  const toast = useToast()
  const [f, setF] = useState({ name: '', code: '' })
  const [err, setErr] = useState<ApiError | null>(null)
  if (q.isLoading) return <Skeleton className="h-96" />
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_320px] items-start">
      <ul className="card divide-y divide-line">
        {q.data?.leagues.map((l) => (
          <li key={l.id} className={`flex items-center gap-3 px-4 py-3 ${l.active ? '' : 'opacity-55'}`}>
            <LeagueMark league={l} size={24} />
            <span className="flex-1 min-w-0">
              <span className="block font-semibold text-sm truncate">{l.name}</span>
              <span className="text-xs text-ink-faint">{l.code} · {l.kind === 'copa' ? 'Copa' : 'Liga'}{l.auto ? ' · ESPN' : ' · manual'}</span>
            </span>
            <button
              role="switch"
              aria-checked={l.active}
              aria-label={`${l.active ? 'Desativar' : 'Ativar'} ${l.name}`}
              onClick={async () => {
                const r = await apiPost<{ message: string }>('admin/league-toggle', { leagueId: l.id, active: !l.active })
                toast(r.message)
                qc.invalidateQueries({ queryKey: ['admin', 'leagues'] })
                qc.invalidateQueries({ queryKey: ['leagues'] })
              }}
              className={`relative w-11 h-6 rounded-full transition ${l.active ? 'bg-success' : 'bg-surface-3'}`}
            >
              <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow transition-all ${l.active ? 'left-[22px]' : 'left-0.5'}`} />
            </button>
          </li>
        ))}
      </ul>
      <form
        className="card p-5 space-y-3"
        onSubmit={async (e) => {
          e.preventDefault()
          setErr(null)
          try {
            const r = await apiPost<{ message: string }>('admin/league', f)
            toast(r.message)
            setF({ name: '', code: '' })
            qc.invalidateQueries({ queryKey: ['admin', 'leagues'] })
          } catch (x) {
            setErr(x as ApiError)
          }
        }}
      >
        <h2 className="font-bold">Cadastrar liga manual</h2>
        <p className="text-xs text-ink-faint">Desativar uma liga a tira do seletor; bolões que já a têm continuam com ela.</p>
        <Field label="Nome" name="lname" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={err?.fields.name} />
        <Field label="Código" name="lcode" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value })} error={err?.fields.code} placeholder="BRA-X" />
        <button className="btn-primary">Cadastrar liga</button>
      </form>
    </div>
  )
}

function Users() {
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const toast = useToast()
  const [link, setLink] = useState<{ name: string; email: string; url: string } | null>(null)
  const [copied, setCopied] = useState(false)
  const r = useQuery({
    queryKey: ['admin', 'users', search],
    queryFn: () => apiGet<{ users: { id: number; name: string; email: string; since: number }[] }>('admin/users', { q: search }),
    placeholderData: (p) => p,
  })
  return (
    <div className="space-y-4">
      {link && (
        <section className="card p-5 space-y-3 border-success/40">
          <h2 className="font-bold">Link de redefinição gerado</h2>
          <p className="text-sm text-ink-soft">Mande para <strong className="text-ink">{link.name}</strong> ({link.email}) por um canal seguro. Vale 24 horas e funciona uma vez. Ele não aparece de novo.</p>
          <div className="flex gap-2 rounded-2xl bg-surface-2 border border-line p-2 pl-4">
            <input readOnly value={link.url} className="flex-1 min-w-0 bg-transparent outline-none text-sm" onFocus={(e) => e.target.select()} aria-label="Link" />
            <button className="btn-primary btn-sm" onClick={() => navigator.clipboard.writeText(link.url).then(() => { setCopied(true); setTimeout(() => setCopied(false), 2000) })}>
              {copied ? <Check className="size-4" /> : <Copy className="size-4" />}{copied ? 'Copiado' : 'Copiar'}
            </button>
          </div>
        </section>
      )}
      <form onSubmit={(e) => { e.preventDefault(); setSearch(q.trim()) }} className="relative">
        <Search className="absolute left-4 top-1/2 -translate-y-1/2 size-4 text-ink-faint" />
        <input type="search" className="input pl-11" placeholder="Buscar por nome ou e-mail" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar usuário" />
      </form>
      <p className="text-xs text-ink-faint">{search ? 'Resultados da busca (até 50).' : 'Os 50 cadastros mais recentes.'} Se o e-mail de "Esqueci minha senha" não chegar, gere aqui um link.</p>
      {r.isLoading ? <Skeleton className="h-64" /> : !r.data?.users.length ? <EmptyState title="Ninguém encontrado" /> : (
        <ul className="card divide-y divide-line">
          {r.data.users.map((u) => (
            <li key={u.id} className="px-4 py-3 flex flex-wrap items-center gap-3">
              <span className="flex-1 min-w-48">
                <span className="block font-semibold text-sm">{u.name}</span>
                <span className="text-xs text-ink-faint">{u.email} · desde {new Date(u.since * 1000).toLocaleDateString('pt-BR')}</span>
              </span>
              <button
                className="btn-ghost btn-sm"
                onClick={async () => {
                  try {
                    setLink(await apiPost('admin/reset-link', { userId: u.id }))
                    window.scrollTo({ top: 0, behavior: 'smooth' })
                  } catch (x) {
                    toast((x as ApiError).message, 'error')
                  }
                }}
              >
                Gerar link de nova senha
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
