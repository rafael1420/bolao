import { useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CalendarClock, Lock, Settings, Share2, Star, Trophy, UserCog } from 'lucide-react'
import { apiPost, ApiError } from '../../lib/api'
import { useGroup } from '../../lib/group'
import { plural, pointsFor } from '../../lib/format'
import type { GroupInfo } from '../../lib/types'
import { Field, Spinner } from '../../components/ui'
import { LeaguePicker, PointsInput } from '../../components/LeaguePicker'
import { ConfirmButton } from '../../components/Confirm'
import { InvitePanel } from '../../components/Invite'
import { useToast } from '../../components/Toast'
import { Block } from './RulesTab'

const ROUND_MODES: [GroupInfo['rules']['roundMode'], string, string][] = [
  ['rodada', 'Rodada atual', 'Só a rodada da vez aceita palpite. A próxima abre quando todos os jogos da atual começarem, ou 48 h antes do primeiro jogo dela.'],
  ['duas', 'Atual e próxima', 'Dá para palpitar na rodada da vez e na seguinte.'],
  ['livre', 'Livre', 'Qualquer jogo já marcado aceita palpite até começar.'],
]
const DEADLINE_MODES: [GroupInfo['rules']['deadlineMode'], string, string][] = [
  ['jogo', 'Até cada jogo', 'Cada palpite pode mudar até o seu jogo começar.'],
  ['rodada', 'Até o 1º jogo da rodada', 'Quando a rodada começa, todos os palpites dela fecham.'],
]

function useAction() {
  const g = useGroup()
  const qc = useQueryClient()
  const toast = useToast()
  return async (action: string, data: Record<string, unknown> = {}) => {
    const r = await apiPost<{ message: string; deleted?: boolean }>('group/settings', { id: g.id, action, ...data })
    toast(r.message)
    qc.invalidateQueries({ queryKey: ['group', g.id] })
    qc.invalidateQueries({ queryKey: ['round', g.id] })
    qc.invalidateQueries({ queryKey: ['groups'] })
    return r
  }
}

function Form({ children, onSubmit, label }: { children: ReactNode; onSubmit: () => Promise<unknown>; label: string }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      await onSubmit()
    } catch (x) {
      setErr((x as ApiError).message)
    } finally {
      setBusy(false)
    }
  }
  return (
    <form onSubmit={submit} className="space-y-4">
      {children}
      {err && <p className="text-sm font-medium text-danger">{err}</p>}
      <button className="btn-primary" disabled={busy}>{busy ? <Spinner /> : label}</button>
    </form>
  )
}

function RadioCards<T extends string>({ legend, options, value, onChange }: { legend: string; options: [T, string, string][]; value: T; onChange: (v: T) => void }) {
  return (
    <fieldset className="space-y-2">
      <legend className="label">{legend}</legend>
      {options.map(([v, title, help]) => (
        <label key={v} className={`flex gap-3 rounded-2xl border p-3.5 cursor-pointer transition ${value === v ? 'border-accent bg-accent-soft' : 'border-line hover:border-line-strong'}`}>
          <input type="radio" name={legend} checked={value === v} onChange={() => onChange(v)} className="mt-1 accent-[var(--accent)] size-4" />
          <span>
            <span className="block font-semibold text-sm">{title}</span>
            <span className="block text-xs text-ink-soft mt-0.5">{help}</span>
          </span>
        </label>
      ))}
    </fieldset>
  )
}

export default function SettingsTab() {
  const g = useGroup()
  const act = useAction()
  const nav = useNavigate()
  const [name, setName] = useState(g.name)
  const [pw, setPw] = useState('')
  const initialLeagues = g.leagues.map((l) => l.id)
  const [leagues, setLeagues] = useState(initialLeagues)
  const [rm, setRm] = useState(g.rules.roundMode)
  const [dm, setDm] = useState(g.rules.deadlineMode)
  const [pts, setPts] = useState({ exact: g.rules.exact, winner: g.rules.winner, diff: g.rules.diff })
  const [owner, setOwner] = useState(0)
  const recalc = g.scored.preds
    ? `Ao salvar, os pontos de ${plural(g.scored.preds, 'palpite', 'palpites')} de ${plural(g.scored.matches, 'jogo encerrado', 'jogos encerrados')} serão recalculados.`
    : 'Ainda não há jogos encerrados. Nada será recalculado.'
  const others = g.ranking.filter((r) => r.id !== g.ownerId).sort((a, b) => a.name.localeCompare(b.name))

  if (!g.isOwner) return <p className="text-ink-soft">Só o organizador pode alterar as configurações deste bolão.</p>

  return (
    <div className="grid gap-4 md:grid-cols-2 items-start">
      <Block icon={<Settings className="size-5" />} title="Dados do bolão">
        <Form label="Salvar nome" onSubmit={() => act('name', { name })}>
          <Field label="Nome do bolão" name="gname" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} />
        </Form>
      </Block>

      <Block icon={<Lock className="size-5" />} title="Entrada">
        <p className="text-sm text-ink-soft">{g.hasPassword ? 'Este bolão tem senha. Quem tiver o link e a senha entra.' : 'Este bolão não tem senha. Quem tiver o link entra direto.'}</p>
        <Form label={g.hasPassword ? 'Trocar senha' : 'Colocar senha'} onSubmit={() => act('password', { password: pw }).then(() => setPw(''))}>
          <Field label="Nova senha do bolão" name="gpw" type="password" autoComplete="new-password" minLength={4} required value={pw} onChange={(e) => setPw(e.target.value)} help="Mínimo de 4 caracteres." />
        </Form>
        {g.hasPassword && (
          <ConfirmButton label="Remover senha" title="Remover a senha?" confirmLabel="Remover senha" onConfirm={() => act('remove_password')}>
            Sem senha, quem tiver o link entra direto.
          </ConfirmButton>
        )}
      </Block>

      <div className="md:col-span-2">
        <Block icon={<Trophy className="size-5" />} title="Campeonatos">
          <Form label="Salvar campeonatos" onSubmit={() => act('leagues', { leagues })}>
            <LeaguePicker value={leagues} onChange={setLeagues} initial={initialLeagues} />
            <p className="text-xs text-ink-faint">{recalc}</p>
          </Form>
        </Block>
      </div>

      <Block icon={<CalendarClock className="size-5" />} title="Rodadas">
        <Form label="Salvar rodadas" onSubmit={() => act('rounds', { roundMode: rm, deadlineMode: dm })}>
          <RadioCards legend="Quais rodadas aceitam palpite" options={ROUND_MODES} value={rm} onChange={setRm} />
          <RadioCards legend="Prazo dos palpites" options={DEADLINE_MODES} value={dm} onChange={setDm} />
          <p className="text-xs text-ink-faint">Os pontos não mudam. Palpites já feitos continuam guardados.</p>
        </Form>
      </Block>

      <Block icon={<Star className="size-5" />} title="Pontuação">
        <Form label="Salvar pontuação" onSubmit={() => act('points', pts)}>
          <div className="grid grid-cols-3 gap-2">
            <PointsInput label="Placar exato" value={pts.exact} onChange={(v) => setPts({ ...pts, exact: v })} />
            <PointsInput label="Vencedor ou empate" value={pts.winner} onChange={(v) => setPts({ ...pts, winner: v })} />
            <PointsInput label="Saldo de gols" value={pts.diff} onChange={(v) => setPts({ ...pts, diff: v })} />
          </div>
          <p className="text-sm text-ink-faint">Palpite 2 × 1, resultado 3 × 2 = <strong className="text-ink">{pointsFor(2, 1, 3, 2, pts)} pontos</strong>. {recalc}</p>
        </Form>
      </Block>

      <Block icon={<Share2 className="size-5" />} title="Convite">
        <InvitePanel group={g} />
        <ConfirmButton label="Gerar novo código" title="Gerar novo código?" confirmLabel="Gerar novo código" onConfirm={() => act('new_code')}>
          O link atual deixa de funcionar. Quem já está no bolão continua nele.
        </ConfirmButton>
      </Block>

      <Block icon={<UserCog className="size-5" />} title="Transferir organização">
        {others.length === 0 ? (
          <p className="text-sm text-ink-soft">Ainda não há outros participantes. Convide alguém para poder passar a organização.</p>
        ) : (
          <>
            <p className="text-sm text-ink-soft">Passe o bolão para outro participante. Você continua como participante.</p>
            <select className="input" value={owner} onChange={(e) => setOwner(Number(e.target.value))} aria-label="Novo organizador">
              <option value={0}>Escolha um participante</option>
              {others.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
            {owner > 0 && (
              <ConfirmButton
                label="Transferir organização"
                title="Transferir a organização?"
                confirmLabel="Transferir"
                requireText={g.name}
                onConfirm={() => act('transfer', { newOwnerId: owner, confirmName: g.name })}
              >
                {others.find((o) => o.id === owner)?.name} poderá mudar regras, remover pessoas e excluir o bolão.
              </ConfirmButton>
            )}
          </>
        )}
      </Block>

      <Block icon={<AlertTriangle className="size-5" />} title="Zona de perigo" danger>
        <p className="text-sm text-ink-soft">Somem os participantes, os palpites e o ranking. Não tem como desfazer.</p>
        <ConfirmButton
          className="btn-danger"
          label="Excluir bolão"
          title={`Excluir ${g.name}?`}
          confirmLabel="Excluir para sempre"
          requireText={g.name}
          onConfirm={async () => {
            await act('delete', { confirmName: g.name })
            nav('/')
          }}
        >
          Essa ação não pode ser desfeita.
        </ConfirmButton>
      </Block>
    </div>
  )
}
