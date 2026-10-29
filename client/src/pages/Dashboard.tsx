import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle, CalendarClock, CheckCircle2, ChevronRight, Crown, Plus, Ticket, Trophy } from 'lucide-react'
import { apiGet } from '../lib/api'
import { useUser } from '../lib/session'
import type { GroupCard } from '../lib/types'
import { countdown, firstName, plural } from '../lib/format'
import { Alert, GroupMark, Skeleton, useNow } from '../components/ui'
import { LeagueMark } from '../components/Flag'

export default function Dashboard() {
  const user = useUser()!
  const now = useNow()
  const q = useQuery({ queryKey: ['groups'], queryFn: () => apiGet<{ groups: GroupCard[] }>('groups'), refetchInterval: 60_000 })
  const groups = q.data?.groups ?? []
  const pending = groups.reduce((s, g) => s + g.pending, 0)
  const nextDeadline = groups.reduce<number | null>((m, g) => (g.next && g.pending && (m === null || g.next < m) ? g.next : m), null)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4 animate-rise">
        <div>
          <p className="text-sm font-semibold text-accent-ink">Olá, {firstName(user.name)} 👋</p>
          <h1 className="text-3xl sm:text-4xl font-black tracking-tight">Meus bolões</h1>
        </div>
        {groups.length > 0 && (
          <div className="hidden sm:flex gap-2">
            <Link to="/codigo" className="btn-ghost"><Ticket className="size-4" />Tenho um código</Link>
            <Link to="/novo" className="btn-primary"><Plus className="size-4" />Novo bolão</Link>
          </div>
        )}
      </div>

      {q.isError && <Alert>{(q.error as Error).message}</Alert>}

      {q.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2">
          <Skeleton className="h-20 sm:col-span-2" />
          <Skeleton className="h-52" />
          <Skeleton className="h-52" />
        </div>
      ) : groups.length === 0 ? (
        <Welcome />
      ) : (
        <>
          <div
            className={`relative overflow-hidden rounded-3xl p-5 sm:p-6 text-white animate-rise ${pending ? '' : ''}`}
            style={{
              background: pending
                ? 'linear-gradient(120deg, #5b4bd6 0%, #7c3aed 55%, #db2777 120%)'
                : 'linear-gradient(120deg, #0f766e 0%, #059669 60%, #22c55e 130%)',
            }}
          >
            <div className="absolute -right-10 -top-10 size-48 rounded-full bg-white/10 blur-2xl" />
            <div className="relative flex items-center gap-4">
              <span className="size-12 grid place-items-center rounded-2xl bg-white/15">
                {pending ? <CalendarClock className="size-6" /> : <CheckCircle2 className="size-6" />}
              </span>
              <div className="min-w-0">
                <p className="text-lg sm:text-xl font-bold">
                  {pending ? `${plural(pending, 'palpite pendente', 'palpites pendentes')}` : 'Tudo em dia!'}
                </p>
                <p className="text-sm text-white/80">
                  {pending
                    ? nextDeadline
                      ? `O próximo prazo fecha ${countdown(nextDeadline, now)}.`
                      : 'Palpite antes de cada prazo.'
                    : 'Nenhum palpite pendente. Agora é torcer.'}
                </p>
              </div>
            </div>
          </div>

          <ul className="grid gap-4 sm:grid-cols-2">
            {groups.map((g, i) => (
              <li key={g.id} className="animate-rise" style={{ animationDelay: `${i * 50}ms` }}>
                <GroupTile g={g} now={now} />
              </li>
            ))}
          </ul>

          <div className="grid grid-cols-2 gap-3 sm:hidden">
            <Link to="/novo" className="btn-primary h-12"><Plus className="size-4" />Novo bolão</Link>
            <Link to="/codigo" className="btn-ghost h-12"><Ticket className="size-4" />Código</Link>
          </div>
        </>
      )}
    </div>
  )
}

function GroupTile({ g, now }: { g: GroupCard; now: number }) {
  const leader = g.pos === 1 && g.points > 0
  return (
    <Link
      to={`/bolao/${g.id}`}
      className="group card block p-5 hover:-translate-y-0.5 hover:shadow-pop hover:border-line-strong transition duration-200"
    >
      <div className="flex items-center gap-3">
        <GroupMark name={g.name} />
        <div className="min-w-0 flex-1">
          <p className="font-bold text-lg truncate">{g.name}</p>
          <p className="text-sm text-ink-faint flex items-center gap-1.5 flex-wrap">
            <span className="inline-flex -space-x-1 mr-0.5">{g.leagues.slice(0, 4).map((l) => <span key={l.id} title={l.name} className="grid place-items-center size-6 rounded-full bg-surface-2 ring-2 ring-surface"><LeagueMark league={l} size={16} /></span>)}</span>
            {plural(g.members, 'participante', 'participantes')}
            {g.isOwner && <span className="ml-2 chip h-5 px-2 text-[11px]">organiza</span>}
          </p>
        </div>
        <ChevronRight className="size-5 text-ink-faint group-hover:translate-x-0.5 group-hover:text-accent-ink transition" />
      </div>

      <div className="mt-4 grid grid-cols-3 gap-2">
        <Stat label="Posição" highlight={leader}>
          {leader && <Crown className="size-4 text-gold inline -mt-1 mr-0.5" />}
          {g.pos ? `${g.pos}º` : '–'}
          <small className="text-xs font-semibold text-ink-faint ml-1">/{g.members}</small>
        </Stat>
        <Stat label="Pontos">{g.points}</Stat>
        <Stat label="Pendentes" warn={g.pending > 0}>{g.pending}</Stat>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-2 text-[13px]">
        {g.pending > 0 ? (
          <span className="chip bg-warning-soft text-warning border-warning/30"><AlertCircle className="size-3.5" />{plural(g.pending, 'palpite pendente', 'palpites pendentes')}</span>
        ) : (
          <span className="chip bg-success-soft text-success border-success/30"><CheckCircle2 className="size-3.5" />Palpites em dia</span>
        )}
        <span className="text-ink-faint">{g.next ? `Próximo prazo ${countdown(g.next, now)}` : 'Nenhum jogo aberto'}</span>
      </div>
      {g.leader && g.leader.total > 0 && !leader && (
        <p className="mt-3 text-[13px] text-ink-faint flex items-center gap-1.5">
          <Trophy className="size-3.5 text-gold" />Líder: <strong className="text-ink-soft">{g.leader.name}</strong> com {plural(g.leader.total, 'ponto', 'pontos')}
        </p>
      )}
    </Link>
  )
}

function Stat({ label, children, highlight, warn }: { label: string; children: React.ReactNode; highlight?: boolean; warn?: boolean }) {
  return (
    <div className={`rounded-2xl px-3 py-2.5 ${highlight ? 'bg-gold/15' : warn ? 'bg-warning-soft' : 'bg-surface-2'}`}>
      <p className="text-[11px] font-semibold uppercase tracking-wider text-ink-faint">{label}</p>
      <p className="display text-3xl font-extrabold tabular leading-tight">{children}</p>
    </div>
  )
}

function Welcome() {
  return (
    <section className="card relative overflow-hidden p-6 sm:p-10 text-center animate-rise">
      <div className="absolute inset-x-0 -top-24 mx-auto size-72 rounded-full bg-accent/25 blur-3xl" />
      <div className="relative">
        <div className="mx-auto mb-4 text-6xl" aria-hidden="true">⚽</div>
        <h2 className="text-2xl font-black">Nenhum bolão por aqui ainda</h2>
        <p className="mt-2 text-ink-soft">Crie o seu e convide a galera, ou entre com o código que um amigo mandou.</p>
        <div className="mt-6 grid sm:grid-cols-2 gap-3 max-w-lg mx-auto text-left">
          <Link to="/novo" className="card p-4 flex gap-3 hover:border-accent transition">
            <span className="size-11 grid place-items-center rounded-2xl bg-accent-soft text-accent-ink shrink-0"><Plus /></span>
            <span><strong className="block">Criar um bolão</strong><span className="text-sm text-ink-soft">Você escolhe ligas e pontuação.</span></span>
          </Link>
          <Link to="/codigo" className="card p-4 flex gap-3 hover:border-accent transition">
            <span className="size-11 grid place-items-center rounded-2xl bg-success-soft text-success shrink-0"><Ticket /></span>
            <span><strong className="block">Tenho um código</strong><span className="text-sm text-ink-soft">Entre no bolão de um amigo.</span></span>
          </Link>
        </div>
      </div>
    </section>
  )
}
