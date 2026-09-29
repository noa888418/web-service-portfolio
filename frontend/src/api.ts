export interface UserRef { id: string; display_name: string }
export interface CurrentUser extends UserRef { role: 'employee' | 'it_staff' }
export interface RequestSummary {
  id: string; title: string; category: 'inquiry' | 'bug' | 'improvement'
  status: 'open' | 'in_progress' | 'waiting_confirmation' | 'completed'
  requester: UserRef; assignee: UserRef | null; version: number; created_at: string
}
export interface Listing {
  data: RequestSummary[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}
export type Fields = Partial<Record<'email' | 'password', string[]>>
export class ApiError extends Error {
  constructor(public status: number, public fields: Fields = {}, public retryAfter = 0) {
    super('API request failed') // Never capture body, credentials or Cookies in errors.
  }
}
export interface Api {
  csrf(): Promise<void>
  login(email: string, password: string): Promise<void>
  me(signal?: AbortSignal): Promise<{ data: CurrentUser }>
  logout(): Promise<void>
  requests(page: number, signal?: AbortSignal): Promise<Listing>
}
async function request<T>(path: string, body?: object, signal?: AbortSignal): Promise<T> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), 15000)
  const abort = () => controller.abort()
  signal?.addEventListener('abort', abort, { once: true })
  if (signal?.aborted) controller.abort()
  try {
    const headers: Record<string, string> = { Accept: 'application/json' }
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json'
      const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))
      if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
    }
    const response = await fetch(path, {
      method: body === undefined ? 'GET' : 'POST', headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
    })
    const json = response.headers.get('Content-Type')?.includes('application/json')
    const data = json ? await response.json() : undefined
    if (!response.ok) {
      const fields: Fields = {}
      if (response.status === 422) {
        // Only known field names, and static text: never echo arbitrary upstream content.
        if (data?.error?.fields?.email) fields.email = ['メールアドレスを確認してください。']
        if (data?.error?.fields?.password) fields.password = ['パスワードは1～128文字で入力してください。']
      }
      const retry = Number(response.headers.get('Retry-After'))
      throw new ApiError(response.status, fields, Number.isFinite(retry) && retry > 0 ? Math.ceil(retry) : 60)
    }
    if (response.status !== 204 && !json) throw new ApiError(0)
    return data as T
  } catch (error) {
    throw error instanceof ApiError ? error : new ApiError(0)
  } finally {
    clearTimeout(timer)
    signal?.removeEventListener('abort', abort)
  }
}
export const api: Api = {
  csrf: () => request<void>('/sanctum/csrf-cookie'),
  login: (email, password) => request<void>('/login', { email, password }),
  me: signal => request('/api/me', undefined, signal),
  logout: () => request<void>('/logout', {}),
  requests: (page, signal) => request(`/api/requests?page=${page}`, undefined, signal),
}
