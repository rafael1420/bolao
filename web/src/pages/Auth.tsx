import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Clock, Trophy, Users, MailCheck } from 'lucide-react'
import { apiGet, apiPost, ApiError } from '../lib/api'
import { useSession, useSetUser } from '../lib/session'
import type { User } from '../lib/types'
import { Alert, Field, Logo, Spinner } from '../components/ui'
import { Crest } from '../components/Crest'
import { PageLoader, ThemeButton, Wordmark } from '../components/Layout'

/** Só aceita caminho interno ("/convite/abc"), nunca outro site. */
function safeNext(next: string | null) {
  return next && /^\/(?!\/)[\w\-/?=&.%]*$/.test(next) ? next : '/'
}

function Pitch() {
  const demo = (name: string) => ({ name, short: name, abbr: name.slice(0, 3).toUpperCase(), logo: null, color: null, color2: null })
  return (
    <section className="relative hidden lg:flex flex-col justify-center gap-8 p-12 overflow-hidden rounded-[2rem] bg-board text-board-ink">
      <div className="absolute -top-32 -left-24 size-[28rem] rounded-full bg-accent/40 blur-3xl" />
      <div className="absolute -bottom-40 -right-24 size-[26rem] rounded-full bg-sky-500/20 blur-3xl" />
      <div className="relative flex items-center gap-3">
        <Logo size={40} />
        <Wordmark />
      </div>
      <h1 className="relative text-5xl font-black leading-[1.05] tracking-tight">
        O bolão da <span className="bg-gradient-to-r from-accent-2 to-sky-300 bg-clip-text text-transparent">sua turma</span>, rodada a rodada.
      </h1>
      <ul className="relative space-y-4 text-[15px] text-board-ink/80">
        {[
          [Clock, 'Uma rodada de cada vez', 'Palpite e mude o placar até o jogo começar.'],
          [Trophy, 'Ranking automático', 'Placar exato, vencedor e saldo viram pontos sozinhos.'],
          [Users, 'Só com quem você chamar', 'Convite por link ou código. Mais de 50 campeonatos.'],
        ].map(([Icon, title, text]) => {
          const I = Icon as typeof Clock
          return (
            <li key={title as string} className="flex gap-3">
              <span className="size-10 shrink-0 grid place-items-center rounded-xl bg-white/10">
                <I className="size-5" />
              </span>
              <span>
                <strong className="block text-board-ink">{title as string}</strong>
                {text as string}
              </span>
            </li>
          )
        })}
      </ul>
      <div className="relative flex items-center justify-center gap-5 rounded-3xl bg-white/5 border border-white/10 p-5">
        <span className="flex flex-col items-center gap-1 text-sm font-semibold"><Crest team={demo('Galera')} size={48} />Galera</span>
        <span className="display text-5xl font-black tabular">2 <span className="text-accent-2">:</span> 1</span>
        <span className="flex flex-col items-center gap-1 text-sm font-semibold"><Crest team={demo('Amigos')} size={48} />Amigos</span>
      </div>
    </section>
  )
}

function AuthShell({ title, lead, children }: { title: string; lead: string; children: ReactNode }) {
  return (
    <div className="min-h-dvh grid lg:grid-cols-2 gap-6 p-4 lg:p-6">
      <Pitch />
      <section className="flex flex-col justify-center w-full max-w-md mx-auto py-8 animate-rise">
        <div className="flex items-center justify-between mb-10 lg:hidden">
          <Link to="/" className="flex items-center gap-2.5"><Logo /><Wordmark /></Link>
          <ThemeButton />
        </div>
        <div className="hidden lg:flex justify-end -mt-4 mb-6"><ThemeButton /></div>
        <h1 className="text-3xl font-black tracking-tight">{title}</h1>
        <p className="mt-2 text-ink-soft">{lead}</p>
        <div className="mt-7">{children}</div>
      </section>
    </div>
  )
}

function useSubmit<T>(fn: () => Promise<T>, onOk: (r: T) => void) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [fields, setFields] = useState<Record<string, string>>({})
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    setFields({})
    try {
      onOk(await fn())
    } catch (err) {
      const ae = err as ApiError
      setError(ae.message)
      setFields(ae.fields ?? {})
    } finally {
      setBusy(false)
    }
  }
  return { busy, error, fields, submit }
}

export function Login() {
  const [params] = useSearchParams()
  const next = safeNext(params.get('next'))
  const { data, isLoading } = useSession()
  const setUser = useSetUser()
  const nav = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const { busy, error, submit } = useSubmit(
    () => apiPost<{ user: User }>('auth/login', { email, password }),
    (r) => {
      setUser(r.user)
      nav(next, { replace: true })
    },
  )
  if (isLoading) return <PageLoader />
  if (data?.user) return <Navigate to={next} replace />
  const reset = params.get('ok') === 'senha'
  return (
    <AuthShell title="Entrar" lead={next.startsWith('/convite') ? 'Entre para abrir o convite do bolão.' : 'Palpites rodada a rodada e um ranking só com os seus amigos.'}>
      <form onSubmit={submit} className="space-y-4">
        {reset && <Alert tone="success">Senha redefinida. Entre com a nova senha.</Alert>}
        {error && <Alert>{error}</Alert>}
        <Field label="E-mail" name="email" type="email" autoComplete="email" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />
        <Field label="Senha" name="password" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        <div className="flex justify-end">
          <Link to="/esqueci-senha" className="text-sm font-semibold text-accent-ink hover:underline">Esqueci minha senha</Link>
        </div>
        <button className="btn-primary w-full h-12" disabled={busy}>{busy ? <Spinner /> : 'Entrar'}</button>
      </form>
      <p className="mt-6 text-center text-sm text-ink-soft">
        Ainda não tem conta? <Link to={`/cadastro${next !== '/' ? `?next=${encodeURIComponent(next)}` : ''}`} className="font-semibold text-accent-ink hover:underline">Criar conta</Link>
      </p>
    </AuthShell>
  )
}

export function Register() {
  const [params] = useSearchParams()
  const next = safeNext(params.get('next'))
  const { data, isLoading } = useSession()
  const setUser = useSetUser()
  const nav = useNavigate()
  const [f, setF] = useState({ name: '', email: '', password: '' })
  const { busy, error, fields, submit } = useSubmit(
    () => apiPost<{ user: User }>('auth/register', f),
    (r) => {
      setUser(r.user)
      nav(next, { replace: true })
    },
  )
  if (isLoading) return <PageLoader />
  if (data?.user) return <Navigate to={next} replace />
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value })
  return (
    <AuthShell title="Criar conta" lead="Leva menos de um minuto. Depois é só criar um bolão ou entrar com o código de um amigo.">
      <form onSubmit={submit} className="space-y-4">
        {error && !Object.keys(fields).length && <Alert>{error}</Alert>}
        <Field label="Nome" name="name" autoComplete="name" required maxLength={100} autoFocus value={f.name} onChange={set('name')} error={fields.name} help="É como os seus amigos veem você no ranking." />
        <Field label="E-mail" name="email" type="email" autoComplete="email" required maxLength={150} value={f.email} onChange={set('email')} error={fields.email} />
        <Field label="Senha" name="password" type="password" autoComplete="new-password" required minLength={8} value={f.password} onChange={set('password')} error={fields.password} help="Mínimo de 8 caracteres." />
        <button className="btn-primary w-full h-12" disabled={busy}>{busy ? <Spinner /> : 'Criar conta'}</button>
      </form>
      <p className="mt-6 text-center text-sm text-ink-soft">
        Já tem conta? <Link to={`/entrar${next !== '/' ? `?next=${encodeURIComponent(next)}` : ''}`} className="font-semibold text-accent-ink hover:underline">Entrar</Link>
      </p>
    </AuthShell>
  )
}

export function Forgot() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const { busy, error, submit } = useSubmit(() => apiPost('auth/forgot', { email }), () => setSent(true))
  return (
    <AuthShell title="Esqueci minha senha" lead="Informe o e-mail da sua conta. Enviamos um link para você criar uma nova senha.">
      {sent ? (
        <div className="card p-6 space-y-3 animate-rise">
          <MailCheck className="size-8 text-success" />
          <h2 className="text-lg font-bold">Confira seu e-mail</h2>
          <p className="text-sm text-ink-soft">
            Se existir uma conta com <strong className="text-ink">{email}</strong>, enviamos um link que vale por 1 hora e funciona uma vez só. Não chegou? Veja o spam ou peça um link à administração do site.
          </p>
          <Link to="/entrar" className="btn-primary">Voltar para entrar</Link>
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {error && <Alert>{error}</Alert>}
          <Field label="E-mail" name="email" type="email" autoComplete="email" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />
          <button className="btn-primary w-full h-12" disabled={busy}>{busy ? <Spinner /> : 'Enviar link'}</button>
          <p className="text-center text-sm text-ink-soft">Lembrou? <Link to="/entrar" className="font-semibold text-accent-ink hover:underline">Entrar</Link></p>
        </form>
      )}
    </AuthShell>
  )
}

export function ResetPassword() {
  const [params] = useSearchParams()
  const token = (params.get('token') ?? '').toLowerCase()
  const nav = useNavigate()
  const setUser = useSetUser()
  const [password, setPassword] = useState('')
  const check = useQuery({ queryKey: ['reset', token], queryFn: () => apiGet<{ email: string }>('auth/reset', { token }), retry: false })
  const { busy, error, fields, submit } = useSubmit(
    () => apiPost('auth/reset', { token, password }),
    () => {
      setUser(null)
      nav('/entrar?ok=senha', { replace: true })
    },
  )
  if (check.isLoading) return <PageLoader />
  if (check.isError)
    return (
      <AuthShell title="Link inválido ou vencido" lead="Este link de redefinição não funciona mais. Ele vale por pouco tempo e só pode ser usado uma vez.">
        <div className="flex gap-3">
          <Link to="/esqueci-senha" className="btn-primary">Pedir um novo link</Link>
          <Link to="/entrar" className="btn-ghost">Entrar</Link>
        </div>
      </AuthShell>
    )
  return (
    <AuthShell title="Criar nova senha" lead={`Conta de ${check.data!.email}.`}>
      <form onSubmit={submit} className="space-y-4">
        {error && !fields.password && <Alert>{error}</Alert>}
        <input type="email" name="username" value={check.data!.email} autoComplete="username" readOnly hidden />
        <Field label="Nova senha" name="password" type="password" autoComplete="new-password" required minLength={8} autoFocus value={password} onChange={(e) => setPassword(e.target.value)} error={fields.password} help="Mínimo de 8 caracteres." />
        <button className="btn-primary w-full h-12" disabled={busy}>{busy ? <Spinner /> : 'Salvar nova senha'}</button>
      </form>
    </AuthShell>
  )
}
