// Cliente da API do servidor Node (/api/rota). GET lê; POST manda JSON com o cabeçalho X-Requested-With,
// que o servidor exige (outro site não consegue mandar esse cabeçalho).

const BASE = `${import.meta.env.BASE_URL}api/`


export class ApiError extends Error {
  status: number
  fields: Record<string, string>
  constructor(message: string, status: number, fields: Record<string, string> = {}) {
    super(message)
    this.status = status
    this.fields = fields
  }
}

type Params = Record<string, string | number | undefined | null>

function url(route: string, params?: Params) {
  const q = new URLSearchParams()
  for (const [k, v] of Object.entries(params ?? {})) {
    if (v !== undefined && v !== null && v !== '') q.set(k, String(v))
  }
  const qs = q.toString()
  return `${BASE}${route}${qs ? `?${qs}` : ''}`
}

async function request<T>(method: 'GET' | 'POST', route: string, params?: Params, body?: unknown): Promise<T> {
  let res: Response
  try {
    res = await fetch(url(route, params), {
      method,
      credentials: 'same-origin',
      headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-Requested-With': 'bolao' } : undefined,
      body: method === 'POST' ? JSON.stringify(body ?? {}) : undefined,
    })
  } catch {
    throw new ApiError('Sem conexão. Confira a internet e tente de novo.', 0)
  }
  let data: any = null
  try {
    data = await res.json()
  } catch {
    /* resposta vazia ou HTML de erro do servidor */
  }
  if (!res.ok) {
    throw new ApiError(data?.error ?? 'Algo deu errado. Tente de novo em instantes.', res.status, data?.fields ?? {})
  }
  return data as T
}

export const apiGet = <T,>(route: string, params?: Params) => request<T>('GET', route, params)
export const apiPost = <T,>(route: string, body?: unknown) => request<T>('POST', route, undefined, body)
