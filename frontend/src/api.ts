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
export interface RequestDetail extends RequestSummary { body: string; updated_at: string }
export interface RequestInput { title: string; category: RequestSummary['category']; body: string }
export interface Candidates { data: UserRef[]; meta: Listing['meta'] }
export const workflowCodes = ['stale_version', 'request_completed', 'no_change', 'invalid_transition', 'invalid_assignee_state'] as const
export type Fields = Partial<Record<'email' | 'password' | 'title' | 'category' | 'body', string[]>>
export class ApiError extends Error {
  constructor(public status: number, public fields: Fields = {}, public retryAfter = 0, public code = '') {
    super('API request failed') // Never capture body, credentials or Cookies in errors.
  }
}
export interface Api {
  csrf(): Promise<void>
  login(email: string, password: string): Promise<void>
  me(signal?: AbortSignal): Promise<{ data: CurrentUser }>
  logout(): Promise<void>
  requests(page: number, signal?: AbortSignal): Promise<Listing>
  detail(id: string, signal?: AbortSignal): Promise<{ data: RequestDetail }>
  create(input: RequestInput): Promise<{ data: RequestDetail }>
  candidates(id: string, page: number, signal?: AbortSignal): Promise<Candidates>
  assignee(id: string, assigneeId: string | null, version: number): Promise<{ data: RequestDetail }>
  status(id: string, status: RequestSummary['status'], version: number): Promise<{ data: RequestDetail }>
}
async function request<T>(path: string, body?: object, signal?: AbortSignal, expectedStatus?: number, method?: 'PATCH'): Promise<T> {
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
      method: method ?? (body === undefined ? 'GET' : 'POST'), headers,
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
        if (data?.error?.fields?.title) fields.title = ['タイトルは改行なしの1～100文字で入力してください。']
        if (data?.error?.fields?.category) fields.category = ['種別を選択してください。']
        if (data?.error?.fields?.body) fields.body = ['内容は1～5000文字で入力してください。']
      }
      const retry = Number(response.headers.get('Retry-After'))
      const code = workflowCodes.find(code => code === data?.error?.code) ?? ''
      throw new ApiError(response.status, fields, Number.isFinite(retry) && retry > 0 ? Math.ceil(retry) : 60, code)
    }
    if ((expectedStatus !== undefined && response.status !== expectedStatus) || (response.status !== 204 && !json)) throw new ApiError(0)
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
  detail: (id, signal) => request(`/api/requests/${encodeURIComponent(id)}`, undefined, signal),
  create: ({ title, category, body }) => request('/api/requests', { title, category, body }, undefined, 201),
  candidates: (id, page, signal) => request(`/api/requests/${encodeURIComponent(id)}/assignee-candidates?page=${page}`, undefined, signal),
  assignee: (id, assigneeId, version) => request(`/api/requests/${encodeURIComponent(id)}/assignee`, { assignee_id: assigneeId, expected_version: version }, undefined, 200, 'PATCH'),
  status: (id, status, version) => request(`/api/requests/${encodeURIComponent(id)}/status`, { status, expected_version: version }, undefined, 200, 'PATCH'),
}
