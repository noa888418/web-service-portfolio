import { expect, it, vi } from 'vitest'
import { Session } from './session'
import { a, mockedApi } from './test/fixtures'
it('maps a direct detail comment_page to API page without changing API contract', async () => {
  const api = mockedApi(); api.me.mockResolvedValue({ data: a })
  const session = new Session(api, () => {})
  await session.open('/requests/1', '?comment_page=2')
  expect(api.comments).toHaveBeenCalledWith('1', 2, expect.any(AbortSignal))
  expect(session.snapshot().commentPage).toBe(2)
})
it('keeps the draft while replacing the page URL for reload', async () => {
  const api = mockedApi(); api.me.mockResolvedValue({ data: a })
  const navigate = vi.fn(); const session = new Session(api, navigate)
  await session.open('/requests/1')
  session.editComment('未送信の架空コメント')
  await session.loadComments(2)
  expect(navigate).toHaveBeenLastCalledWith('/requests/1?comment_page=2', true)
  expect(session.snapshot().commentDraft).toBe('未送信の架空コメント')
})
it.each(['?comment_page=0', '?comment_page=2&comment_page=3', '?page=2', '?comment_page=2147483648', '?comment_page=abc'])('rejects invalid detail query %s', async query => {
  const api = mockedApi(); const session = new Session(api, () => {})
  await session.open('/requests/1', query)
  expect(session.snapshot().phase).toBe('notFound'); expect(api.me).not.toHaveBeenCalled()
})
