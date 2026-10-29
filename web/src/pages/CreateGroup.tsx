import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { apiPost, ApiError } from '../lib/api'
import { pointsFor } from '../lib/format'
import { Alert, Field, Spinner } from '../components/ui'
import { LeaguePicker, PointsInput } from '../components/LeaguePicker'
import { useToast } from '../components/Toast'

export default function CreateGroup() {
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [leagues, setLeagues] = useState<number[]>([])
  const [pts, setPts] = useState({ exact: 5, winner: 3, diff: 1 })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      const r = await apiPost<{ id: number; fetching: boolean }>('groups/create', { name, password, leagues, ...pts })
      qc.invalidateQueries({ queryKey: ['groups'] })
      toast(r.fetching ? 'Bolão criado! Buscando a tabela completa; pode levar alguns minutos.' : 'Bolão criado! Agora convide a galera.')
      nav(`/bolao/${r.id}?bemvindo=1`)
    } catch (e) {
      setErr(e as ApiError)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setBusy(false)
    }
  }
  const Step = ({ n, title }: { n: number; title: string }) => (
    <h2 className="flex items-center gap-3 text-lg font-bold">
      <span className="size-8 grid place-items-center rounded-full bg-accent text-on-accent text-sm">{n}</span>
      {title}
    </h2>
  )
  return (
    <form onSubmit={submit} className="max-w-2xl mx-auto space-y-5 animate-rise">
      <div>
        <p className="text-sm font-semibold text-accent-ink">Meus bolões</p>
        <h1 className="text-3xl font-black tracking-tight">Novo bolão</h1>
        <p className="text-ink-soft mt-1">Você vira o organizador e pode mudar as regras depois.</p>
      </div>
      {err && <Alert>{err.message}</Alert>}
      <section className="card p-5 space-y-4">
        <Step n={1} title="Nome e entrada" />
        <Field label="Nome do bolão" name="name" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} error={err?.fields.name} placeholder="Ex.: Galera do Futebol" />
        <Field label="Senha para entrar (opcional)" name="password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={err?.fields.password} help="Sem senha, quem tiver o link entra direto." />
      </section>
      <section className="card p-5 space-y-4">
        <Step n={2} title="Campeonatos" />
        {err?.fields.leagues && <p className="text-sm font-medium text-danger">{err.fields.leagues}</p>}
        <LeaguePicker value={leagues} onChange={setLeagues} initial={[]} />
      </section>
      <section className="card p-5 space-y-4">
        <Step n={3} title="Pontuação" />
        <div className="grid grid-cols-3 gap-2">
          <PointsInput label="Placar exato" value={pts.exact} onChange={(v) => setPts({ ...pts, exact: v })} />
          <PointsInput label="Vencedor ou empate" value={pts.winner} onChange={(v) => setPts({ ...pts, winner: v })} />
          <PointsInput label="Saldo de gols" value={pts.diff} onChange={(v) => setPts({ ...pts, diff: v })} />
        </div>
        <p className="text-sm text-ink-faint" aria-live="polite">
          Palpite 2 × 1 e resultado 3 × 2: vencedor ({pts.winner}) e saldo (+{pts.diff}) = <strong className="text-ink">{pointsFor(2, 1, 3, 2, pts)} pontos</strong>.
        </p>
      </section>
      <button className="btn-primary w-full h-13 text-base" disabled={busy}>{busy ? <Spinner /> : 'Criar bolão'}</button>
    </form>
  )
}
