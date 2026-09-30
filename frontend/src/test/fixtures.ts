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
  return { csrf: vi.fn<Api['csrf']>().mockResolvedValue(), login: vi.fn<Api['login']>().mockResolvedValue(),
    me: vi.fn<Api['me']>().mockRejectedValue(new ApiError(401)), logout: vi.fn<Api['logout']>().mockResolvedValue(),
    requests: vi.fn<Api['requests']>().mockResolvedValue(listing()),
    detail: vi.fn<Api['detail']>().mockResolvedValue({ data: detail() }),
    create: vi.fn<Api['create']>().mockResolvedValue({ data: detail() }) }
}
export function detail(id = '1') { return { ...listing().data[0], id, body: '架空の内容\n2行目', updated_at: '2026-09-29T00:30:00.000000Z' } }
export function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (error: unknown) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
