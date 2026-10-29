import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { KeyRound, LogOut, UserRound } from 'lucide-react'
import { apiPost, ApiError } from '../lib/api'
import { useSetUser, useUser } from '../lib/session'
import type { User } from '../lib/types'
import { Avatar, Field, Spinner } from '../components/ui'
import { useToast } from '../components/Toast'
import { Block } from './group/RulesTab'

export default function Account() {
  const user = useUser()!
  const setUser = useSetUser()
  const toast = useToast()
  const nav = useNavigate()
  const [name, setName] = useState(user.name)
  const [pw, setPw] = useState({ current: '', password: '' })
  const [busy, setBusy] = useState<string | null>(null)
  const [err, setErr] = useState<ApiError | null>(null)

  const run = (key: string, fn: () => Promise<void>) => async (e?: FormEvent) => {
    e?.preventDefault()
    setBusy(key)
    setErr(null)
    try {
      await fn()
    } catch (x) {
      setErr(x as ApiError)
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="max-w-3xl mx-auto space-y-5 animate-rise">
      <div className="flex items-center gap-4">
        <Avatar name={user.name} size={64} />
        <div className="min-w-0">
          <p className="text-sm font-semibold text-accent-ink">Sua conta</p>
          <h1 className="text-3xl font-black tracking-tight truncate">{user.name}</h1>
          <p className="text-sm text-ink-faint truncate">{user.email}</p>
        </div>
      </div>
      <div className="grid gap-4 md:grid-cols-2 items-start">
        <Block icon={<UserRound className="size-5" />} title="Seu nome">
          <form className="space-y-4" onSubmit={run('name', async () => {
            const r = await apiPost<{ user: User }>('account/name', { name })
            setUser(r.user)
            toast('Nome atualizado.')
          })}>
            <Field label="Nome" name="name" required maxLength={100} autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} error={err?.fields.name} help="Aparece no ranking dos seus bolões." />
            <button className="btn-primary" disabled={busy === 'name'}>{busy === 'name' ? <Spinner /> : 'Salvar nome'}</button>
          </form>
        </Block>
        <Block icon={<KeyRound className="size-5" />} title="Trocar senha">
          <form className="space-y-4" onSubmit={run('pw', async () => {
            await apiPost('account/password', pw)
            setPw({ current: '', password: '' })
            toast('Senha trocada. Os outros aparelhos saíram da conta.')
          })}>
            <input type="email" name="username" value={user.email} autoComplete="username" readOnly hidden />
            <Field label="Senha atual" name="current" type="password" required autoComplete="current-password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} error={err?.fields.current} />
            <Field label="Nova senha" name="password" type="password" required minLength={8} autoComplete="new-password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} error={err?.fields.password} help="Mínimo de 8 caracteres." />
            <button className="btn-primary" disabled={busy === 'pw'}>{busy === 'pw' ? <Spinner /> : 'Trocar senha'}</button>
          </form>
        </Block>
      </div>
      <button
        className="btn-ghost w-full sm:w-auto"
        onClick={run('out', async () => {
          await apiPost('auth/logout')
          setUser(null)
          nav('/entrar', { replace: true })
        })}
      >
        {busy === 'out' ? <Spinner /> : <LogOut className="size-4" />}Sair da conta
      </button>
    </div>
  )
}
