import { vi } from 'vitest'
import { ApiError } from '../api'
import type { Api, CurrentUser, Listing } from '../api'
export const a: CurrentUser = { id: '1', display_name: '社員A', role: 'employee' }
export const b: CurrentUser = { id: '2', display_name: '社員B', role: 'employee' }
export const x: CurrentUser = { id: '3', display_name: 'IT担当者X', role: 'it_staff' }
export function listing(title = '端末の相談', page = 1, total = 21): Listing {
  return { data: [{ id: String(page), title, category: 'inquiry', status: 'open',
    requester: a, assignee: null, version: 1, created_at: '2026-09-29T00:30:00.000000Z' }],
    meta: { current_page: page, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } }
}
export function mockedApi() {
  const comments = { data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }
  return { csrf: vi.fn<Api['csrf']>().mockResolvedValue(), login: vi.fn<Api['login']>().mockResolvedValue(),
    me: vi.fn<Api['me']>().mockRejectedValue(new ApiError(401)), logout: vi.fn<Api['logout']>().mockResolvedValue(),
    comments: vi.fn<Api['comments']>().mockResolvedValue(comments),
    comment: vi.fn<Api['comment']>().mockResolvedValue({ data: { id: '1', body: '追加情報', author: a, created_at: '2026-09-30T00:00:00.000000Z' } }),
    requests: vi.fn<Api['requests']>().mockResolvedValue(listing()),
    detail: vi.fn<Api['detail']>().mockResolvedValue({ data: detail() }),
    create: vi.fn<Api['create']>().mockResolvedValue({ data: detail() }),
    candidates: vi.fn<Api['candidates']>().mockResolvedValue({ data: [x], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } }),
    assignee: vi.fn<Api['assignee']>().mockResolvedValue({ data: { ...detail(), assignee: x, version: 2 } }),
    status: vi.fn<Api['status']>().mockResolvedValue({ data: { ...detail(), assignee: x, status: 'in_progress', version: 3 } }) }
}
export function detail(id = '1') { return { ...listing().data[0], id, body: '架空の内容\n2行目', updated_at: '2026-09-29T00:30:00.000000Z' } }
export function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
