import { StrictMode, lazy } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, RouterProvider } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import './styles.css'
import { AppLayout } from './components/Layout'
import { ToastProvider } from './components/Toast'
import { ApiError } from './lib/api'
import Dashboard from './pages/Dashboard'
import GroupPage from './pages/group/GroupPage'
import RoundTab from './pages/group/RoundTab'
import { Login, Register, Forgot, ResetPassword } from './pages/Auth'
import NotFound from './pages/NotFound'

const GamesTab = lazy(() => import('./pages/group/GamesTab'))
const RankingTab = lazy(() => import('./pages/group/RankingTab'))
const MembersTab = lazy(() => import('./pages/group/MembersTab'))
const RulesTab = lazy(() => import('./pages/group/RulesTab'))
const SettingsTab = lazy(() => import('./pages/group/SettingsTab'))
const CreateGroup = lazy(() => import('./pages/CreateGroup'))
const Join = lazy(() => import('./pages/Join'))
const Account = lazy(() => import('./pages/Account'))
const Admin = lazy(() => import('./pages/admin/Admin'))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 20_000,
      refetchOnWindowFocus: true,
      retry: (count, err) => !(err instanceof ApiError && err.status >= 400 && err.status < 500) && count < 2,
    },
  },
})

const router = createBrowserRouter(
  [
    { path: '/entrar', element: <Login /> },
    { path: '/cadastro', element: <Register /> },
    { path: '/esqueci-senha', element: <Forgot /> },
    { path: '/nova-senha', element: <ResetPassword /> },
    {
      element: <AppLayout />,
      children: [
        { index: true, element: <Dashboard /> },
        { path: 'novo', element: <CreateGroup /> },
        { path: 'codigo', element: <Join /> },
        { path: 'convite/:code', element: <Join /> },
        { path: 'conta', element: <Account /> },
        { path: 'admin/:tab?', element: <Admin /> },
        {
          path: 'bolao/:id',
          element: <GroupPage />,
          children: [
            { index: true, element: <RoundTab /> },
            { path: 'jogos', element: <GamesTab /> },
            { path: 'ranking', element: <RankingTab /> },
            { path: 'participantes', element: <MembersTab /> },
            { path: 'regras', element: <RulesTab /> },
            { path: 'configurar', element: <SettingsTab /> },
          ],
        },
        { path: '*', element: <NotFound /> },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL.replace(/\/$/, '') || '/' },
)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>
  </StrictMode>,
)
