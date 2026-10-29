import { useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { ArrowLeft, CalendarDays, ListChecks, Settings, Share2, Ticket, Trophy, Users } from 'lucide-react'
import { useGroupId, useGroupQuery } from '../../lib/group'
import { plural } from '../../lib/format'
import { Flag } from '../../components/Flag'
import { Alert, GroupMark, Skeleton } from '../../components/ui'
import { Modal } from '../../components/Modal'
import { InvitePanel } from '../../components/Invite'

export default function GroupPage() {
  const id = useGroupId()
  const q = useGroupQuery(id)
  const [invite, setInvite] = useState(false)

  if (q.isLoading)
    return (
      <div className="space-y-4">
        <Skeleton className="h-28" />
        <Skeleton className="h-12" />
        <Skeleton className="h-72" />
      </div>
    )
  if (q.isError || !q.data)
    return (
      <div className="space-y-4">
        <Alert>{(q.error as Error)?.message ?? 'Bolão não encontrado.'}</Alert>
        <Link to="/" className="btn-ghost">Voltar aos meus bolões</Link>
      </div>
    )
  const g = q.data
  const tabs = [
    { to: '', label: 'Palpites', icon: Ticket, end: true },
    { to: 'jogos', label: 'Jogos', icon: CalendarDays },
    { to: 'ranking', label: 'Ranking', icon: Trophy },
    { to: 'participantes', label: 'Galera', icon: Users },
    g.isOwner ? { to: 'configurar', label: 'Configurar', icon: Settings } : { to: 'regras', label: 'Regras', icon: ListChecks },
  ]

  return (
    <div className="space-y-5">
      <Link to="/" className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-soft hover:text-ink">
        <ArrowLeft className="size-4" />Meus bolões
      </Link>

      <header className="card relative overflow-hidden p-5 sm:p-6 animate-rise">
        <div className="absolute -right-16 -top-20 size-64 rounded-full bg-accent/20 blur-3xl" />
        <div className="relative flex flex-wrap items-center gap-4">
          <GroupMark name={g.name} size={60} />
          <div className="min-w-0 flex-1">
            <h1 className="text-2xl sm:text-3xl font-black tracking-tight truncate">{g.name}</h1>
            <div className="mt-2 flex flex-wrap gap-1.5">
              {g.leagues.map((l) => (
                <span key={l.id} className="chip"><Flag code={l.country} className="w-4 h-[11px]" />{l.short}</span>
              ))}
            </div>
          </div>
          <button onClick={() => setInvite(true)} className="btn-primary w-full sm:w-auto"><Share2 className="size-4" />Convidar</button>
        </div>
        <div className="relative mt-4 flex flex-wrap gap-2 text-sm">
          <span className="chip h-8"><Users className="size-4" />{plural(g.ranking.length, 'participante', 'participantes')}</span>
          {g.me && (
            <span className={`chip h-8 ${g.me.pos === 1 && g.me.total > 0 ? 'bg-gold/15 text-gold border-gold/30' : ''}`}>
              <Trophy className="size-4" />Você em {g.me.pos}º · {plural(g.me.total, 'ponto', 'pontos')}
            </span>
          )}
          {g.isOwner && <span className="chip h-8 bg-accent-soft text-accent-ink border-accent/30">Você organiza</span>}
        </div>
      </header>

      <nav aria-label="Seções do bolão" className="sticky top-16 z-30 -mx-4 px-4 py-2 bg-glass backdrop-blur-xl md:static md:bg-transparent md:backdrop-blur-none md:p-0 md:mx-0">
        <div className="flex gap-1 p-1 rounded-2xl bg-surface-2 border border-line">
          {tabs.map((t) => (
            <NavLink
              key={t.to}
              to={t.to}
              end={t.end}
              className={({ isActive }) =>
                `flex-1 min-w-0 flex flex-col sm:flex-row items-center justify-center gap-0.5 sm:gap-1.5 px-1 sm:px-3 h-12 sm:h-10 rounded-xl text-[11px] sm:text-sm font-semibold transition whitespace-nowrap ${
                  isActive ? 'bg-surface text-ink shadow-card' : 'text-ink-soft hover:text-ink'
                }`
              }
            >
              <t.icon className="size-4" />
              {t.label}
            </NavLink>
          ))}
        </div>
      </nav>

      <Outlet context={g} />

      <Modal open={invite} onClose={() => setInvite(false)} title="Convidar para o bolão">
        <InvitePanel group={g} />
      </Modal>
    </div>
  )
}
