import { useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Crown, Medal, Star, TrendingUp } from 'lucide-react'
import { apiGet } from '../../lib/api'
import { useGroup } from '../../lib/group'
import { useUser } from '../../lib/session'
import type { RankRow, RankingData } from '../../lib/types'
import { plural } from '../../lib/format'
import { Avatar, EmptyState, Segmented, Skeleton } from '../../components/ui'

type View = 'geral' | 'rodada' | 'matriz'

export default function RankingTab() {
  const g = useGroup()
  const me = useUser()!
  const [params, setParams] = useSearchParams()
  const view = (['geral', 'rodada', 'matriz'].includes(params.get('vista') ?? '') ? params.get('vista') : 'geral') as View
  const key = params.get('rodada') ?? ''
  const q = useQuery({
    queryKey: ['ranking', g.id, key],
    queryFn: () => apiGet<RankingData>('group/ranking', { id: g.id, rodada: key }),
    enabled: view !== 'geral',
    placeholderData: (p) => p,
  })
  const set = (patch: Record<string, string>) => setParams({ ...Object.fromEntries(params), ...patch }, { replace: true })

  return (
    <div className="space-y-5">
      {g.me && <Standing />}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Segmented<View> label="Visões do ranking" value={view} onChange={(v) => set({ vista: v })} options={[['geral', 'Geral'], ['rodada', 'Rodada'], ['matriz', 'Rodada a rodada']]} />
      </div>

      {view === 'geral' && <Board rows={g.ranking} meId={me.id} />}

      {view !== 'geral' && (q.isLoading || !q.data) && <Skeleton className="h-80" />}

      {view === 'rodada' && q.data && (
        !q.data.round ? (
          <EmptyState icon={<Star className="size-6" />} title="Ainda não há rodada com jogo encerrado" />
        ) : (
          <>
            <RoundNav data={q.data} onPick={(k) => set({ rodada: k })} />
            <Highlight rows={q.data.roundRanking} />
            <Board rows={q.data.roundRanking} meId={me.id} />
          </>
        )
      )}

      {view === 'matriz' && q.data && <Matrix data={q.data} rows={g.ranking} meId={me.id} onPick={(k) => set({ vista: 'rodada', rodada: k })} />}

      <p className="text-xs text-ink-faint">Desempate: placares exatos, depois acertos de vencedor ou empate. Quem empata em tudo divide a posição.</p>
    </div>
  )
}

function Standing() {
  const g = useGroup()
  const me = g.me!
  const leading = me.pos === 1
  return (
    <section className="relative overflow-hidden rounded-3xl p-5 text-white animate-rise" style={{ background: 'linear-gradient(120deg,#1e1b4b,#4c1d95 60%,#7c3aed)' }} aria-live="polite">
      <div className="absolute -right-8 -top-8 size-40 rounded-full bg-white/10 blur-2xl" />
      <div className="relative flex items-center gap-4">
        <span className="display text-6xl font-black tabular leading-none">{me.pos}º</span>
        <p className="text-sm sm:text-base text-white/85">
          {leading ? (
            <>Você lidera com <strong className="text-white">{plural(me.total, 'ponto', 'pontos')}</strong>. Segura essa! 🏆</>
          ) : (
            <>
              Você está em {me.pos}º com <strong className="text-white">{plural(me.total, 'ponto', 'pontos')}</strong>.
              {g.above && <> Faltam <strong className="text-white">{g.above.total - me.total}</strong> para o {g.above.pos}º.</>}
            </>
          )}
        </p>
        <TrendingUp className="ml-auto size-8 text-white/40 hidden sm:block" />
      </div>
    </section>
  )
}

function Podium({ rows, meId }: { rows: RankRow[]; meId: number }) {
  const top = rows.slice(0, 3)
  if (top.length < 2 || top[0].total <= 0) return null
  const order = top.length === 3 ? [top[1], top[0], top[2]] : [top[1], top[0]]
  const height = (r: RankRow) => (r === top[0] ? 'h-28' : r === top[1] ? 'h-20' : 'h-14')
  const color = (r: RankRow) => (r === top[0] ? 'from-amber-300 to-yellow-500 text-[#3b2a00]' : r === top[1] ? 'from-slate-200 to-slate-400 text-slate-800' : 'from-orange-300 to-amber-700 text-[#3b1d00]')
  return (
    <ol className="grid grid-cols-3 items-end gap-2 sm:gap-4 max-w-md mx-auto pt-4" aria-hidden="true">
      {order.map((r) => (
        <li key={r.id} className="flex flex-col items-center gap-2 animate-rise">
          {r === top[0] && <Crown className="size-7 text-gold drop-shadow" />}
          <Avatar name={r.name} size={r === top[0] ? 56 : 46} ring={r.id === meId} />
          <span className="text-sm font-bold text-center line-clamp-1">{r.name.split(' ')[0]}</span>
          <div className={`w-full ${height(r)} rounded-t-2xl bg-gradient-to-b ${color(r)} grid place-items-center shadow-lg`}>
            <span className="text-center leading-tight">
              <span className="display block text-2xl font-black">{r.pos}º</span>
              <span className="text-xs font-bold">{r.total} pts</span>
            </span>
          </div>
        </li>
      ))}
    </ol>
  )
}

function Board({ rows, meId }: { rows: RankRow[]; meId: number }) {
  const max = Math.max(1, ...rows.map((r) => r.total))
  return (
    <>
      <Podium rows={rows} meId={meId} />
      <div className="card overflow-hidden">
        <table className="w-full text-sm">
          <caption className="sr-only">Classificação</caption>
          <thead className="text-[11px] uppercase tracking-wider text-ink-faint bg-surface-2">
            <tr>
              <th scope="col" className="py-2.5 pl-4 text-left w-12">#</th>
              <th scope="col" className="py-2.5 text-left">Participante</th>
              <th scope="col" className="py-2.5 text-right" title="Placares exatos">Exatos</th>
              <th scope="col" className="py-2.5 text-right hidden sm:table-cell" title="Acertos de vencedor ou empate">Acertos</th>
              <th scope="col" className="py-2.5 text-right hidden sm:table-cell" title="Palpites em jogos encerrados">Jogos</th>
              <th scope="col" className="py-2.5 pr-4 text-right">Pts</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {rows.map((r, i) => {
              const isMe = r.id === meId
              return (
                <tr key={r.id} className={`animate-rise ${isMe ? 'bg-accent-soft' : ''}`} style={{ animationDelay: `${Math.min(i, 10) * 30}ms` }}>
                  <th scope="row" className="py-3 pl-4 text-left">
                    <span className={`inline-grid place-items-center size-7 rounded-lg text-xs font-black ${r.pos === 1 ? 'bg-gold/20 text-gold' : r.pos === 2 ? 'bg-silver/20 text-silver' : r.pos === 3 ? 'bg-bronze/20 text-bronze' : 'text-ink-faint'}`}>
                      {r.pos <= 3 && r.total > 0 ? <Medal className="size-4" /> : r.pos}
                    </span>
                  </th>
                  <td className="py-3">
                    <span className="flex items-center gap-2.5 min-w-0">
                      <Avatar name={r.name} size={30} />
                      <span className="min-w-0">
                        <span className="font-semibold truncate block">
                          {r.name} {isMe && <span className="text-xs font-bold text-accent-ink">você</span>}
                        </span>
                        <span className="block h-1 mt-1 rounded-full bg-surface-3 overflow-hidden w-24 sm:w-40">
                          <span className="block h-full rounded-full bg-gradient-to-r from-accent to-accent-2" style={{ width: `${(r.total * 100) / max}%` }} />
                        </span>
                      </span>
                    </span>
                  </td>
                  <td className="py-3 text-right tabular text-ink-soft">{r.exact}</td>
                  <td className="py-3 text-right tabular text-ink-soft hidden sm:table-cell">{r.outcomes}</td>
                  <td className="py-3 text-right tabular text-ink-soft hidden sm:table-cell">{r.played}</td>
                  <td className="py-3 pr-4 text-right display text-xl font-black tabular">{r.total}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </>
  )
}

function RoundNav({ data, onPick }: { data: RankingData; onPick: (k: string) => void }) {
  const list = data.rounds
  const i = list.findIndex((r) => r.key === data.round!.key)
  const older = list[i + 1]
  const newer = i > 0 ? list[i - 1] : undefined
  return (
    <div className="flex items-center gap-2">
      <button disabled={!older} onClick={() => older && onPick(older.key)} className="size-11 grid place-items-center rounded-2xl bg-surface border border-line disabled:opacity-30" aria-label="Rodada anterior">
        <ChevronLeft className="size-5" />
      </button>
      <select value={data.round!.key} onChange={(e) => onPick(e.target.value)} className="input flex-1 font-bold text-center" aria-label="Escolher rodada">
        {list.map((r) => (
          <option key={r.key} value={r.key}>{r.label}</option>
        ))}
      </select>
      <button disabled={!newer} onClick={() => newer && onPick(newer.key)} className="size-11 grid place-items-center rounded-2xl bg-surface border border-line disabled:opacity-30" aria-label="Próxima rodada">
        <ChevronRight className="size-5" />
      </button>
    </div>
  )
}

function Highlight({ rows }: { rows: RankRow[] }) {
  const top = rows[0]
  if (!top || top.total <= 0) return null
  const tied = rows.filter((r) => r.pos === 1).map((r) => r.name)
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-gold/40 bg-gold/10 px-4 py-3 animate-rise">
      <Star className="size-6 text-gold shrink-0" fill="currentColor" />
      <p className="text-sm">
        <strong>Destaque da rodada:</strong> {tied.join(', ')}, com {plural(top.total, 'ponto', 'pontos')}
        {top.exact > 0 && ` e ${plural(top.exact, 'placar exato', 'placares exatos')}`}.
      </p>
    </div>
  )
}

function Matrix({ data, rows, meId, onPick }: { data: RankingData; rows: RankRow[]; meId: number; onPick: (k: string) => void }) {
  const cols = useMemo(() => data.rounds.slice(0, 8).reverse(), [data.rounds])
  const max = useMemo(() => {
    let m = 1
    for (const u of Object.values(data.matrix)) for (const v of Object.values(u)) m = Math.max(m, v)
    return m
  }, [data.matrix])
  if (!cols.length) return <EmptyState icon={<Star className="size-6" />} title="Ainda não há rodada com jogo encerrado" />
  return (
    <div className="card overflow-x-auto">
      <table className="w-full text-sm">
        <caption className="sr-only">Pontos de cada participante nas últimas rodadas</caption>
        <thead className="text-[11px] uppercase tracking-wider text-ink-faint bg-surface-2">
          <tr>
            <th scope="col" className="sticky left-0 bg-surface-2 py-2.5 pl-4 pr-2 text-left">Participante</th>
            {cols.map((c) => (
              <th key={c.key} scope="col" className="py-2.5 px-1 text-center">
                <button onClick={() => onPick(c.key)} title={c.label} className="hover:text-accent-ink">{c.short}</button>
              </th>
            ))}
            <th scope="col" className="py-2.5 pr-4 pl-2 text-right">Total</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {rows.map((r) => {
            const pts = data.matrix[r.id] ?? {}
            return (
              <tr key={r.id} className={r.id === meId ? 'bg-accent-soft' : ''}>
                <th scope="row" className={`sticky left-0 py-2.5 pl-4 pr-2 text-left font-semibold whitespace-nowrap ${r.id === meId ? 'bg-[color-mix(in_srgb,var(--surface)_88%,var(--accent))]' : 'bg-surface'}`}>
                  <span className="text-ink-faint mr-1.5 tabular">{r.pos}º</span>
                  {r.name.split(' ')[0]}
                </th>
                {cols.map((c) => {
                  const v = pts[c.key]
                  return (
                    <td key={c.key} className="px-1 py-1.5 text-center">
                      <span
                        className="inline-grid place-items-center size-9 rounded-lg text-sm font-bold tabular"
                        style={v !== undefined ? { background: `color-mix(in srgb, var(--accent) ${Math.round((v / max) * 55) + 6}%, transparent)` } : undefined}
                      >
                        {v ?? <span className="text-ink-faint">–</span>}
                      </span>
                    </td>
                  )
                })}
                <td className="py-2.5 pr-4 pl-2 text-right display text-lg font-black tabular">{r.total}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
      <p className="px-4 py-3 text-xs text-ink-faint">Últimas {cols.length} rodadas com jogo encerrado. Toque numa rodada para ver o ranking dela.</p>
    </div>
  )
}
