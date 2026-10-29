// Cliente da API PHP (api/index.php?r=rota). GET lê; POST manda JSON com o token CSRF.

const BASE = `${import.meta.env.BASE_URL}api/index.php`

let csrf = ''
export const setCsrf = (token: string) => {
  csrf = token
}

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
  const q = new URLSearchParams({ r: route })
  for (const [k, v] of Object.entries(params ?? {})) {
    if (v !== undefined && v !== null && v !== '') q.set(k, String(v))
  }
  return `${BASE}?${q}`
}

async function request<T>(method: 'GET' | 'POST', route: string, params?: Params, body?: unknown, retry = true): Promise<T> {
  let res: Response
  try {
    res = await fetch(url(route, params), {
      method,
      credentials: 'same-origin',
      headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : undefined,
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
  if (res.status === 419 && data?.csrf && retry) {
    setCsrf(data.csrf)
    return request<T>(method, route, params, body, false)
  }
  if (!res.ok) {
    throw new ApiError(data?.error ?? 'Algo deu errado. Tente de novo em instantes.', res.status, data?.fields ?? {})
  }
  if (data?.csrf) setCsrf(data.csrf)
  return data as T
}

export const apiGet = <T,>(route: string, params?: Params) => request<T>('GET', route, params)
export const apiPost = <T,>(route: string, body?: unknown) => request<T>('POST', route, undefined, body)
