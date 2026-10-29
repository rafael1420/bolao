import { useQueryClient } from '@tanstack/react-query'
import { UserMinus } from 'lucide-react'
import { apiPost, ApiError } from '../../lib/api'
import { useGroup } from '../../lib/group'
import { useUser } from '../../lib/session'
import { plural } from '../../lib/format'
import { Avatar } from '../../components/ui'
import { ConfirmButton } from '../../components/Confirm'
import { useToast } from '../../components/Toast'

export default function MembersTab() {
  const g = useGroup()
  const me = useUser()!
  const qc = useQueryClient()
  const toast = useToast()
  const people = [...g.ranking].sort((a, b) => (a.id === g.ownerId ? -1 : b.id === g.ownerId ? 1 : a.name.localeCompare(b.name)))
  return (
    <section className="space-y-3">
      <h2 className="text-lg font-bold">Participantes ({g.ranking.length})</h2>
      <ul className="grid gap-2 sm:grid-cols-2">
        {people.map((r, i) => (
          <li key={r.id} className="card p-3.5 flex items-center gap-3 animate-rise" style={{ animationDelay: `${Math.min(i, 10) * 30}ms` }}>
            <Avatar name={r.name} size={42} ring={r.id === me.id} />
            <div className="min-w-0 flex-1">
              <p className="font-semibold truncate">
                {r.name}
                {r.id === g.ownerId && <span className="ml-2 chip h-5 px-2 text-[11px] bg-accent-soft text-accent-ink border-accent/30">organiza</span>}
                {r.id === me.id && <span className="ml-2 text-xs font-bold text-accent-ink">você</span>}
              </p>
              <p className="text-xs text-ink-faint">
                {r.pos}º · {plural(r.exact, 'exato', 'exatos')} · {plural(r.outcomes, 'acerto', 'acertos')}
              </p>
            </div>
            <span className="display text-2xl font-black tabular">{r.total}<small className="text-xs text-ink-faint ml-0.5 font-sans">pts</small></span>
            {g.isOwner && r.id !== g.ownerId && (
              <ConfirmButton
                className="size-9 grid place-items-center rounded-xl text-ink-faint hover:text-danger hover:bg-danger-soft"
                label={<UserMinus className="size-4" aria-label={`Remover ${r.name}`} />}
                title={`Remover ${r.name}?`}
                confirmLabel="Remover"
                onConfirm={async () => {
                  try {
                    await apiPost('group/remove-member', { id: g.id, userId: r.id })
                    toast(`${r.name} saiu do bolão.`)
                    qc.invalidateQueries({ queryKey: ['group', g.id] })
                  } catch (e) {
                    toast((e as ApiError).message, 'error')
                  }
                }}
              >
                Essa pessoa sai do bolão. Os palpites dela ficam guardados e voltam se ela entrar de novo.
              </ConfirmButton>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}
