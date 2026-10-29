import { useQuery, useQueryClient } from '@tanstack/react-query'
import { apiGet } from './api'
import type { Session, User } from './types'

export function useSession() {
  return useQuery({
    queryKey: ['session'],
    queryFn: () => apiGet<Session>('session'),
    staleTime: 5 * 60_000,
  })
}

export function useUser(): User | null {
  return useSession().data?.user ?? null
}

/** Atualiza o usuário guardado (depois de entrar, sair ou trocar o nome). */
export function useSetUser() {
  const qc = useQueryClient()
  return (user: User | null) => {
    qc.setQueryData<Session>(['session'], (s) => (s ? { ...s, user } : s))
    if (!user) qc.removeQueries({ predicate: (q) => q.queryKey[0] !== 'session' })
  }
}
