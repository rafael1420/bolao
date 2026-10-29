import { useQuery } from '@tanstack/react-query'
import { useOutletContext, useParams } from 'react-router-dom'
import { apiGet } from './api'
import type { GroupInfo } from './types'

export function useGroupId() {
  return Number(useParams().id)
}

export function useGroupQuery(id: number) {
  return useQuery({ queryKey: ['group', id], queryFn: () => apiGet<GroupInfo>('group', { id }) })
}

/** Dados do bolão já carregados pela página (GroupPage), para as abas. */
export const useGroup = () => useOutletContext<GroupInfo>()
