import { afterEach, expect, it, vi } from 'vitest'
import { api, ApiError } from './api'
afterEach(() => { vi.unstubAllGlobals(); document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/' })
it('sends only creation fields with CSRF and maps request field errors to static messages', async () => {
  const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ error: { fields: { title: ['private'], body: ['private'], category: ['private'], requester_id: ['private'] } } }), { status: 422, headers: { 'Content-Type': 'application/json' } }))
  vi.stubGlobal('fetch', fetch); document.cookie = 'XSRF-TOKEN=test%3D; path=/'
  const input = { title: '題', body: '内容', category: 'inquiry' as const, requester_id: '2' }
  await expect(api.create(input)).rejects.toMatchObject({ status: 422, fields: { title: ['タイトルは改行なしの1～100文字で入力してください。'], body: ['内容は1～5000文字で入力してください。'], category: ['種別を選択してください。'] } })
  expect(fetch).toHaveBeenCalledWith('/api/requests', expect.objectContaining({ method: 'POST', body: JSON.stringify({ title: '題', category: 'inquiry', body: '内容' }), headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'test=' }) }))
})
it('aborts a timed-out POST once without retrying', async () => {
  vi.useFakeTimers()
  try {
    const fetch = vi.fn((_url: string, options: RequestInit) => new Promise((_resolve, reject) => options.signal?.addEventListener('abort', () => reject(new Error('timeout')))))
    vi.stubGlobal('fetch', fetch)
    const failure = expect(api.create({ title: '題', body: '内容', category: 'bug' })).rejects.toMatchObject({ status: 0 })
    await vi.advanceTimersByTimeAsync(15000); await failure
    expect(fetch).toHaveBeenCalledTimes(1)
  } finally { vi.useRealTimers() }
})
it('only recognizes 201 JSON as creation success', async () => {
  const fetch = vi.fn().mockResolvedValue(new Response('{"data":{"id":"1"}}', { status: 200, headers: { 'Content-Type': 'application/json' } }))
  vi.stubGlobal('fetch', fetch)
  await expect(api.create({ title: '題', body: '内容', category: 'inquiry' })).rejects.toMatchObject({ status: 0 })
  expect(fetch).toHaveBeenCalledTimes(1)
})
it('uses relative URLs, cookies and decoded XSRF without persistent storage', async () => {
  const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {} }), { headers: { 'Content-Type': 'application/json' } }))
  vi.stubGlobal('fetch', fetch); document.cookie = 'XSRF-TOKEN=fixture%3D; path=/'
  await api.login('a@example.test', 'invalid-fixture')
  expect(fetch).toHaveBeenCalledWith('/login', expect.objectContaining({ method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'fixture=' } }))
  expect(localStorage.length).toBe(0); expect(sessionStorage.length).toBe(0)
})
it('rejects HTML error responses without rendering or retaining their body', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<script>private upstream data</script>', { status: 503, headers: { 'Content-Type': 'text/html' } })))
  await expect(api.me()).rejects.toMatchObject({ status: 503, message: 'API request failed' })
})
it('reads Retry-After and returns only known static field errors', async () => {
  const fetch = vi.fn().mockResolvedValueOnce(new Response('{}', { status: 429, headers: { 'Content-Type': 'application/json', 'Retry-After': '9' } }))
    .mockResolvedValueOnce(new Response(JSON.stringify({ error: { fields: { email: ['untrusted message'], role: ['hidden'] } } }), { status: 422, headers: { 'Content-Type': 'application/json' } }))
  vi.stubGlobal('fetch', fetch)
  await expect(api.login('a@example.test', 'fixture')).rejects.toMatchObject({ retryAfter: 9 })
  try { await api.login('a@example.test', 'fixture') } catch (error) {
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).fields).toEqual({ email: ['メールアドレスを確認してください。'] })
  }
})
