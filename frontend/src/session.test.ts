import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from './api'
import type { Listing } from './api'
import { Session, pageNumber } from './session'
import { a, b, deferred, listing, mockedApi } from './test/fixtures'

afterEach(() => vi.useRealTimers())
function setup() { const api = mockedApi(); const navigate = vi.fn(); return { api, navigate, session: new Session(api, navigate) } }
describe('session boundaries (mock API)', () => {
  it('checks me before exposing protected data and prepares CSRF for an unauthenticated visitor', async () => {
    const { api, session, navigate } = setup()
    await session.open('/requests', '?page=2')
    expect(session.snapshot()).toMatchObject({ phase: 'guest', user: null, listing: null, csrfReady: true })
    expect(navigate).toHaveBeenCalledWith('/login', true)
    expect(api.requests).not.toHaveBeenCalled()
  })
  it('normalizes email, leaves password unchanged, confirms me, then loads the list', async () => {
    const { api, session } = setup(); await session.check()
    api.me.mockResolvedValue({ data: a })
    await session.login('  A@EXAMPLE.TEST  ', ' fictional input ')
    expect(api.login).toHaveBeenCalledWith('a@example.test', ' fictional input ')
    expect(api.me.mock.invocationCallOrder.at(-1)).toBeGreaterThan(api.login.mock.invocationCallOrder[0])
    expect(session.snapshot()).toMatchObject({ phase: 'authenticated', user: a })
  })
  it('prevents duplicate login and does not retry a failed mutation', async () => {
    const { api, session } = setup(); await session.check()
    const pending = deferred<void>(); api.login.mockReturnValue(pending.promise)
    const first = session.login('a@example.test', 'fixture'); await session.login('a@example.test', 'fixture')
    expect(api.login).toHaveBeenCalledTimes(1)
    pending.reject(new ApiError(0)); await first
    expect(session.snapshot()).toMatchObject({ phase: 'uncertain', user: null, listing: null, busy: false })
    expect(api.login).toHaveBeenCalledTimes(1)
  })
  it.each([401, 403, 419, 422, 429])('handles login HTTP %s without re-sending', async status => {
    const { api, session } = setup(); await session.check()
    api.login.mockRejectedValue(new ApiError(status, { email: ['Invalid'] }, 3))
    await session.login('a@example.test', 'fixture')
    expect(session.snapshot()).toMatchObject({ phase: 'guest', user: null, listing: null, csrfReady: true })
    expect(session.snapshot().notice).not.toBe('')
    expect(api.login).toHaveBeenCalledTimes(1)
    if (status === 422) expect(session.snapshot().fields.email).toEqual(['Invalid'])
  })
  it('honors Retry-After and only permits a manual login after the boundary', async () => {
    vi.useFakeTimers(); const { api, session } = setup(); await session.check()
    api.login.mockRejectedValue(new ApiError(429, {}, 3))
    await session.login('a@example.test', 'fixture')
    await session.login('a@example.test', 'fixture'); expect(api.login).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(3000); expect(api.login).toHaveBeenCalledTimes(1)
    await session.login('a@example.test', 'fixture'); expect(api.login).toHaveBeenCalledTimes(2)
  })
  it('hides protected information before logout; failure stays uncertain and is not success', async () => {
    const { api, session } = setup(); api.me.mockResolvedValue({ data: a }); await session.check()
    const pending = deferred<void>(); api.logout.mockReturnValue(pending.promise)
    const first = session.logout(); await session.logout()
    expect(session.snapshot()).toMatchObject({ user: null, listing: null, busy: true })
    pending.reject(new ApiError(503)); await first
    expect(session.snapshot().phase).toBe('uncertain')
    expect(session.snapshot().notice).toContain('完了を確認できません')
    expect(api.logout).toHaveBeenCalledTimes(1)
    await session.check(); expect(session.snapshot().user).toEqual(a) // Explicit GET reconciliation.
  })
  it('logout success erases all data and initializes a fresh login form', async () => {
    const { api, session } = setup(); api.me.mockResolvedValue({ data: a }); await session.check()
    await session.logout()
    expect(session.snapshot()).toMatchObject({ phase: 'guest', user: null, listing: null, csrfReady: true })
  })
  it.each([401, 419])('clears user and requests on list HTTP %s', async status => {
    const { api, session } = setup(); api.me.mockResolvedValue({ data: a }); await session.check()
    api.requests.mockRejectedValue(new ApiError(status)); await session.load()
    expect(session.snapshot()).toMatchObject({ phase: 'guest', user: null, listing: null })
  })
  it('rejects old list success and error after account switching even if cancellation is ignored', async () => {
    const { api, session } = setup(); api.me.mockResolvedValue({ data: a }); await session.check()
    const old = deferred<Listing>(); api.requests.mockReturnValueOnce(old.promise)
    const load = session.load(); await session.logout()
    api.me.mockResolvedValue({ data: b }); api.requests.mockResolvedValue(listing('Bの依頼'))
    await session.login('b@example.test', 'fixture')
    old.resolve(listing('Aの古い依頼')); await load
    expect(session.snapshot().user).toEqual(b); expect(session.snapshot().listing?.data[0].title).toBe('Bの依頼')
    const obsolete = deferred<Listing>(); api.requests.mockReturnValueOnce(obsolete.promise)
    const second = session.load(); session.conceal(); await session.check()
    obsolete.reject(new ApiError(401)); await second
    expect(session.snapshot().user).toEqual(b)
  })
  it('conceals on tab suspension and ignores a late me response', async () => {
    const { api, session } = setup(); const pending = deferred<{ data: typeof a }>(); api.me.mockReturnValue(pending.promise)
    const first = session.check(); session.conceal(); pending.resolve({ data: a }); await first
    expect(session.snapshot()).toMatchObject({ phase: 'uncertain', user: null, listing: null })
  })
  it('ignores old pagination responses, and separates error and zero results', async () => {
    const { api, session } = setup(); api.me.mockResolvedValue({ data: a }); await session.check()
    const old = deferred<Listing>(); api.requests.mockReturnValueOnce(old.promise)
    const pending = session.load(1); api.requests.mockResolvedValue({ ...listing('', 2), data: [] })
    await session.load(2); old.resolve(listing('old')); await pending
    expect(session.snapshot().listing?.data).toEqual([]); expect(session.snapshot().page).toBe(2)
    api.requests.mockRejectedValue(new ApiError(0)); await session.load()
    expect(session.snapshot().listing).toBeNull(); expect(session.snapshot().notice).toContain('取得できません')
  })
  it('does not treat failure of the initial me or CSRF request as logged out success', async () => {
    const { api, session } = setup(); api.me.mockRejectedValue(new ApiError(503)); await session.check()
    expect(session.snapshot().phase).toBe('uncertain')
    api.me.mockRejectedValue(new ApiError(401)); api.csrf.mockRejectedValue(new ApiError(0)); await session.check()
    expect(session.snapshot().csrfReady).toBe(false)
    await session.login('a@example.test', 'fixture'); expect(api.login).not.toHaveBeenCalled()
  })
  it.each(['?page=0', '?page=01', '?page=-1', '?page=1&page=2', '?page=2147483648', '?sort=asc'])('rejects malformed route query %s', search => {
    expect(pageNumber(search)).toBeNull()
  })
})
