import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { CalendarClock, LogOut, Star, Trophy } from 'lucide-react'
import { apiPost, ApiError } from '../../lib/api'
import { useGroup } from '../../lib/group'
import { pointsFor } from '../../lib/format'
import { Flag } from '../../components/Flag'
import { ConfirmButton } from '../../components/Confirm'
import { useToast } from '../../components/Toast'

export function ScoringCard({ exact, winner, diff }: { exact: number; winner: number; diff: number }) {
  const ex = pointsFor(2, 1, 3, 2, { exact, winner, diff })
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-3 gap-2">
        {[
          ['Placar exato', exact, 'from-amber-300/30'],
          ['Vencedor ou empate', winner, 'from-accent/25'],
          ['Saldo de gols', `+${diff}`, 'from-sky-400/25'],
        ].map(([label, v, tone]) => (
          <div key={label as string} className={`rounded-2xl p-3 bg-gradient-to-br ${tone} to-transparent border border-line`}>
            <p className="display text-4xl font-black tabular">{v}</p>
            <p className="text-xs font-semibold text-ink-soft">{label}</p>
          </div>
        ))}
      </div>
      <p className="text-sm text-ink-faint">
        Exemplo: palpite 2 × 1 e resultado 3 × 2 — acertou o vencedor ({winner}) e o saldo (+{diff}) = <strong className="text-ink">{ex} pontos</strong>. Quem acerta o placar exato não soma os outros.
      </p>
    </div>
  )
}

export function Block({ icon, title, children, danger }: { icon: React.ReactNode; title: string; children: React.ReactNode; danger?: boolean }) {
  return (
    <section className={`card p-5 space-y-4 ${danger ? 'border-danger/30' : ''}`}>
      <h3 className="font-bold flex items-center gap-2.5">
        <span className={`size-9 grid place-items-center rounded-xl ${danger ? 'bg-danger-soft text-danger' : 'bg-accent-soft text-accent-ink'}`}>{icon}</span>
        {title}
      </h3>
      {children}
    </section>
  )
}

export default function RulesTab() {
  const g = useGroup()
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Block icon={<Star className="size-5" />} title="Pontuação">
        <ScoringCard {...g.rules} />
      </Block>
      <Block icon={<CalendarClock className="size-5" />} title="Rodadas">
        <dl className="space-y-3 text-sm">
          <div><dt className="text-ink-faint">Palpites</dt><dd className="font-semibold">{g.rules.roundModeText[0]}</dd><dd className="text-ink-soft">{g.rules.roundModeText[1]}</dd></div>
          <div><dt className="text-ink-faint">Prazo</dt><dd className="font-semibold">{g.rules.deadlineModeText[0]}</dd><dd className="text-ink-soft">{g.rules.deadlineModeText[1]}</dd></div>
        </dl>
      </Block>
      <Block icon={<Trophy className="size-5" />} title="Campeonatos">
        <div className="flex flex-wrap gap-2">
          {g.leagues.map((l) => <span key={l.id} className="chip h-8"><Flag code={l.country} />{l.name}</span>)}
        </div>
      </Block>
      <Block icon={<LogOut className="size-5" />} title="Sair do bolão" danger>
        <p className="text-sm text-ink-soft">Você deixa de ver este bolão. Seus palpites ficam guardados se você voltar.</p>
        <ConfirmButton
          label={<><LogOut className="size-4" />Sair do bolão</>}
          title="Sair do bolão?"
          confirmLabel="Sair"
          onConfirm={async () => {
            try {
              await apiPost('group/leave', { id: g.id })
              qc.invalidateQueries({ queryKey: ['groups'] })
              toast('Você saiu do bolão.')
              nav('/')
            } catch (e) {
              toast((e as ApiError).message, 'error')
            }
          }}
        />
      </Block>
    </div>
  )
}
