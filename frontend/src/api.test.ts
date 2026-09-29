import { afterEach, expect, it, vi } from 'vitest'
import { api, ApiError } from './api'
afterEach(() => { vi.unstubAllGlobals(); document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/' })
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
