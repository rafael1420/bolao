import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CalendarX } from 'lucide-react'
import { apiGet } from '../../lib/api'
import { useGroup } from '../../lib/group'
import type { GameRow } from '../../lib/types'
import { clock, dayTitle } from '../../lib/format'
import { Crest } from '../../components/Crest'
import { Flag } from '../../components/Flag'
import { Alert, EmptyState, Skeleton } from '../../components/ui'

export default function GamesTab() {
  const g = useGroup()
  const [league, setLeague] = useState(0)
  const q = useQuery({
    queryKey: ['games', g.id, league],
    queryFn: () => apiGet<{ days: { day: string; matches: GameRow[] }[]; today: string }>('group/games', { id: g.id, league }),
    placeholderData: (p) => p,
    refetchInterval: (query) => (query.state.data?.days.some((d) => d.matches.some((m) => m.live)) ? 30_000 : 120_000),
  })
  const byLeague = Object.fromEntries(g.leagues.map((l) => [l.id, l]))

  return (
    <div className="space-y-4">
      {g.leagues.length > 1 && (
        <div className="flex gap-2 overflow-x-auto scrollbar-none -mx-4 px-4">
          {[{ id: 0, short: 'Todos', country: '' }, ...g.leagues].map((l) => (
            <button
              key={l.id}
              onClick={() => setLeague(l.id)}
              className={`shrink-0 inline-flex items-center gap-2 h-9 px-4 rounded-full border text-sm font-semibold transition ${
                league === l.id ? 'bg-ink text-bg border-ink' : 'bg-surface border-line text-ink-soft'
              }`}
            >
              {l.country && <Flag code={l.country} className="w-4 h-[11px]" />}
              {l.short}
            </button>
          ))}
        </div>
      )}
      {q.isError && <Alert>{(q.error as Error).message}</Alert>}
      {q.isLoading ? (
        <Skeleton className="h-96" />
      ) : !q.data?.days.length ? (
        <EmptyState icon={<CalendarX className="size-6" />} title="Nenhum jogo por perto">De 3 dias atrás até daqui a duas semanas.</EmptyState>
      ) : (
        q.data.days.map((d) => {
          const isToday = d.day === q.data!.today
          return (
            <section key={d.day} className="animate-rise">
              <h3 className={`sticky top-[124px] md:top-16 z-10 py-2 text-sm font-bold uppercase tracking-wider ${isToday ? 'text-accent-ink' : 'text-ink-faint'}`}>
                <span className="bg-bg/80 backdrop-blur rounded-full pr-3">{dayTitle(d.day, q.data!.today)}</span>
              </h3>
              <ul className="card divide-y divide-line overflow-hidden">
                {d.matches.map((m) => {
                  const s = m.score ?? m.liveScore
                  const l = byLeague[m.leagueId]
                  return (
                    <li key={m.id}>
                      <Link
                        to={`/bolao/${g.id}?liga=${m.leagueId}&rodada=${m.round}#jogo-${m.id}`}
                        className="grid grid-cols-[20px_1fr_auto_1fr] items-center gap-2 sm:gap-3 px-3 sm:px-4 py-3 hover:bg-surface-2 transition"
                      >
                        <span title={l?.name}>{l && <Flag code={l.country} className="w-5 h-[13px]" />}</span>
                        <span className="flex items-center justify-end gap-2 min-w-0 text-sm font-semibold">
                          <span className="truncate text-right">{m.home.short}</span>
                          <Crest team={m.home} size={28} />
                        </span>
                        <span className="min-w-16 text-center">
                          {s ? (
                            <span className={`display text-xl font-black tabular ${m.live ? 'text-live' : ''}`}>{s[0]} × {s[1]}</span>
                          ) : (
                            <span className="text-sm font-semibold text-ink-soft tabular">{clock(m.kickoff)}</span>
                          )}
                          {m.live && (
                            <span className="block text-[10px] font-bold uppercase text-live">
                              <span className="inline-block size-1.5 rounded-full bg-live mr-1 animate-pulse-live" />
                              {m.statusDetail ?? 'ao vivo'}
                            </span>
                          )}
                        </span>
                        <span className="flex items-center gap-2 min-w-0 text-sm font-semibold">
                          <Crest team={m.away} size={28} />
                          <span className="truncate">{m.away.short}</span>
                        </span>
                      </Link>
                    </li>
                  )
                })}
              </ul>
            </section>
          )
        })
      )}
    </div>
  )
}
