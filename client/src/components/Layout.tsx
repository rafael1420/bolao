import { NavLink, Link, Outlet, Navigate, useLocation } from 'react-router-dom'
import { Suspense, useState } from 'react'
import { Home, PlusCircle, Ticket, User as UserIcon, Shield, Sun, Moon } from 'lucide-react'
import { useSession } from '../lib/session'
import { Avatar, Logo, Spinner } from './ui'

function useTheme() {
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'))
  const toggle = () => {
    const next = !dark
    const apply = () => {
      document.documentElement.classList.toggle('dark', next)
      setDark(next)
    }
    try {
      localStorage.setItem('bolao-tema', next ? 'dark' : 'light')
    } catch {
      /* navegador sem armazenamento: vale só nesta visita */
    }
    const d = document as Document & { startViewTransition?: (cb: () => void) => void }
    if (d.startViewTransition) d.startViewTransition(apply)
    else apply()
  }
  return { dark, toggle }
}

export function Wordmark() {
  return (
    <span className="font-extrabold tracking-tight text-[17px] leading-none">
      Conferência <span className="bg-gradient-to-r from-accent to-accent-2 bg-clip-text text-transparent">Bolão</span>
    </span>
  )
}

export function PageLoader() {
  return (
    <div className="grid place-items-center py-24 text-ink-faint">
      <Spinner className="size-7" />
    </div>
  )
}

export function ThemeButton() {
  const { dark, toggle } = useTheme()
  return (
    <button onClick={toggle} className="size-10 grid place-items-center rounded-xl hover:bg-surface-2 text-ink-soft hover:text-ink transition" aria-label={dark ? 'Usar tema claro' : 'Usar tema escuro'}>
      {dark ? <Sun className="size-5" /> : <Moon className="size-5" />}
    </button>
  )
}

/** Moldura das telas com login: topo, menu, barra inferior no celular. */
export function AppLayout() {
  const { data, isLoading } = useSession()
  const loc = useLocation()
  if (isLoading) return <PageLoader />
  const user = data?.user
  if (!user) return <Navigate to={`/entrar?next=${encodeURIComponent(loc.pathname + loc.search)}`} replace />

  const tabs = [
    { to: '/', label: 'Bolões', icon: Home, end: true },
    { to: '/novo', label: 'Novo', icon: PlusCircle },
    { to: '/codigo', label: 'Código', icon: Ticket },
    { to: '/conta', label: 'Conta', icon: UserIcon },
    ...(user.isAdmin ? [{ to: '/admin', label: 'Admin', icon: Shield }] : []),
  ]
  const onGroup = loc.pathname.startsWith('/bolao')

  return (
    <div className="min-h-dvh flex flex-col">
      <a href="#conteudo" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70] btn-primary">
        Pular para o conteúdo
      </a>
      <header className="sticky top-0 z-40 border-b border-line bg-glass backdrop-blur-xl pt-[env(safe-area-inset-top)]">
        <div className="mx-auto max-w-5xl px-4 h-16 flex items-center gap-4">
          <Link to="/" className="flex items-center gap-2.5 mr-auto" aria-label="Conferência Bolão, início">
            <Logo />
            <span className="hidden sm:inline">
              <Wordmark />
            </span>
          </Link>
          <nav className="hidden md:flex items-center gap-1" aria-label="Principal">
            {tabs.map((t) => (
              <NavLink
                key={t.to}
                to={t.to}
                end={t.end}
                className={({ isActive }) =>
                  `px-3.5 h-10 inline-flex items-center gap-2 rounded-xl text-sm font-semibold transition ${
                    isActive || (t.to === '/' && onGroup) ? 'bg-accent-soft text-accent-ink' : 'text-ink-soft hover:text-ink hover:bg-surface-2'
                  }`
                }
              >
                <t.icon className="size-4" />
                {t.to === '/' ? 'Meus bolões' : t.label}
              </NavLink>
            ))}
          </nav>
          <ThemeButton />
          <Link to="/conta" aria-label={`Sua conta, ${user.name}`} className="rounded-full">
            <Avatar name={user.name} size={34} />
          </Link>
        </div>
      </header>

      <main id="conteudo" className="flex-1 mx-auto w-full max-w-5xl px-4 pt-5 pb-[calc(100px+env(safe-area-inset-bottom))] md:pb-16">
        <Suspense fallback={<PageLoader />}>
          <Outlet />
        </Suspense>
      </main>

      <nav
        aria-label="Atalhos"
        className="md:hidden fixed bottom-0 inset-x-0 z-40 border-t border-line bg-glass backdrop-blur-xl pb-[env(safe-area-inset-bottom)]"
      >
        <div className="flex">
          {tabs.map((t) => (
            <NavLink
              key={t.to}
              to={t.to}
              end={t.end}
              className={({ isActive }) =>
                `flex-1 flex flex-col items-center justify-center gap-1 h-16 text-[11px] font-semibold transition ${
                  isActive || (t.to === '/' && onGroup) ? 'text-accent-ink' : 'text-ink-faint'
                }`
              }
            >
              {({ isActive }) => (
                <>
                  <span className={`grid place-items-center h-8 w-14 rounded-full transition ${isActive || (t.to === '/' && onGroup) ? 'bg-accent-soft' : ''}`}>
                    <t.icon className="size-5" />
                  </span>
                  {t.label}
                </>
              )}
            </NavLink>
          ))}
        </div>
      </nav>
    </div>
  )
}
