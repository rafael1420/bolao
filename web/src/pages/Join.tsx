import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Ticket, User, Users } from 'lucide-react'
import { apiGet, apiPost, ApiError } from '../lib/api'
import type { League } from '../lib/types'
import { plural } from '../lib/format'
import { Alert, Field, GroupMark, Skeleton, Spinner } from '../components/ui'
import { Flag } from '../components/Flag'
import { useToast } from '../components/Toast'

type Preview = { id: number; name: string; owner: string; members: number; isMember: boolean; needsPassword: boolean; leagues: League[]; rules: { exact: number; winner: number; diff: number } }

export default function Join() {
  const { code } = useParams()
  return code ? <InvitePreview code={code} /> : <CodeForm />
}

function CodeForm() {
  const nav = useNavigate()
  const [code, setCode] = useState('')
  return (
    <div className="max-w-md mx-auto pt-6 animate-rise">
      <div className="text-center mb-6">
        <span className="mx-auto mb-4 size-14 grid place-items-center rounded-2xl bg-accent-soft text-accent-ink"><Ticket className="size-7" /></span>
        <h1 className="text-3xl font-black tracking-tight">Entrar em bolão</h1>
        <p className="text-ink-soft mt-1">Use o código ou o link que o organizador enviou.</p>
      </div>
      <form
        className="card p-5 space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          // Aceita o link inteiro colado: fica só com o código do final.
          const c = code.trim().split(/[/=]/).pop()!.replace(/\s+/g, '').toLowerCase()
          if (c) nav(`/convite/${encodeURIComponent(c)}`)
        }}
      >
        <Field label="Código ou link do convite" name="code" required autoFocus autoComplete="off" placeholder="a1b2c3d4e5" value={code} onChange={(e) => setCode(e.target.value)} help="Maiúsculas e espaços não importam." className="display text-xl tracking-widest" />
        <button className="btn-primary w-full h-12">Continuar</button>
      </form>
    </div>
  )
}

function InvitePreview({ code }: { code: string }) {
  const nav = useNavigate()
  const qc = useQueryClient()
  const toast = useToast()
  const q = useQuery({ queryKey: ['invite', code], queryFn: () => apiGet<Preview>('invite', { code }), retry: false })
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<ApiError | null>(null)

  if (q.isLoading) return <Skeleton className="h-80 max-w-md mx-auto" />
  if (q.isError)
    return (
      <div className="max-w-md mx-auto space-y-4 pt-6">
        <Alert>{(q.error as Error).message}</Alert>
        <CodeForm />
      </div>
    )
  const g = q.data!
  if (g.isMember) return <Navigate to={`/bolao/${g.id}`} replace />

  const join = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setErr(null)
    try {
      const r = await apiPost<{ id: number }>('invite/join', { code, password })
      qc.invalidateQueries({ queryKey: ['groups'] })
      toast('Você entrou! Dê seu primeiro palpite.')
      nav(`/bolao/${r.id}`)
    } catch (x) {
      setErr(x as ApiError)
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="max-w-md mx-auto pt-4 space-y-4 animate-rise">
      <section className="card relative overflow-hidden p-6 text-center">
        <div className="absolute inset-x-0 -top-20 mx-auto size-60 rounded-full bg-accent/25 blur-3xl" />
        <div className="relative">
          <GroupMark name={g.name} size={72} />
          <p className="mt-4 text-sm font-semibold text-accent-ink">Você foi convidado para</p>
          <h1 className="text-3xl font-black tracking-tight">{g.name}</h1>
          <div className="mt-3 flex flex-wrap justify-center gap-1.5">
            {g.leagues.map((l) => <span key={l.id} className="chip"><Flag code={l.country} className="w-4 h-[11px]" />{l.short}</span>)}
          </div>
          <div className="mt-4 flex flex-wrap justify-center gap-2 text-sm">
            <span className="chip h-8"><Users className="size-4" />{plural(g.members, 'participante', 'participantes')}</span>
            <span className="chip h-8"><User className="size-4" />Organizado por {g.owner}</span>
          </div>
          <p className="mt-4 text-xs text-ink-faint">Pontuação: exato {g.rules.exact} · vencedor {g.rules.winner} · saldo +{g.rules.diff}</p>
        </div>
      </section>
      <form onSubmit={join} className="card p-5 space-y-4">
        {err && !err.fields.password && <Alert>{err.message}</Alert>}
        {g.needsPassword && (
          <>
            <p className="text-sm text-ink-soft flex items-center gap-2"><Lock className="size-4" />Este bolão pede senha. Peça ao organizador.</p>
            <Field label="Senha do bolão" name="password" type="password" autoComplete="off" required autoFocus value={password} onChange={(e) => setPassword(e.target.value)} error={err?.fields.password ? err.message : undefined} />
          </>
        )}
        <button className="btn-primary w-full h-12" disabled={busy}>{busy ? <Spinner /> : 'Entrar no bolão'}</button>
      </form>
    </div>
  )
}
