import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { App } from './App'
import { Session } from './session'
import { ApiError } from './api'
import type { RequestDetail } from './api'
import { a, b, deferred, detail, mockedApi, x } from './test/fixtures'

afterEach(() => vi.restoreAllMocks())
function setup(path = '/requests/new', user = a) {
  window.history.replaceState(null, '', path)
  const api = mockedApi(); api.me.mockResolvedValue({ data: user })
  const session = new Session(api, path => window.history.replaceState(null, '', path))
  return { api, session }
}
async function form() {
  const setupResult = setup(); render(<App session={setupResult.session} />)
  await screen.findByLabelText(/^タイトル/)
  fireEvent.change(screen.getByLabelText(/^タイトル/), { target: { value: '  架空の相談😀  ' } })
  fireEvent.change(screen.getByLabelText(/^内容/), { target: { value: '内容\n次行' } })
  return setupResult
}
describe('create and read-only detail with mock API', () => {
  it('guards duplicate submit synchronously, normalizes and navigates using returned ID, erasing the draft', async () => {
    const { api, session } = await form()
    const pending = deferred<{ data: RequestDetail }>(); api.create.mockReturnValue(pending.promise)
    const element = screen.getByRole('button', { name: '登録する' }).closest('form')!
    fireEvent.submit(element); fireEvent.submit(element)
    expect(api.create).toHaveBeenCalledTimes(1)
    expect(api.create).toHaveBeenCalledWith({ title: '架空の相談😀', body: '内容\n次行', category: 'inquiry' })
    api.detail.mockResolvedValue({ data: detail('42') })
    await act(async () => pending.resolve({ data: detail('42') }))
    await screen.findByRole('heading', { name: '依頼詳細' })
    expect(window.location.pathname).toBe('/requests/42')
    expect(session.snapshot().draft.body).toBe('')
    expect(screen.queryByLabelText(/^タイトル/)).toBeNull()
    expect(api.detail).toHaveBeenCalledWith('42', expect.any(AbortSignal))
  })
  it('retains input on 422, associates field errors and focuses summary', async () => {
    const { api } = await form()
    api.create.mockRejectedValue(new ApiError(422, { title: ['タイトルを確認してください。'] }))
    fireEvent.click(screen.getByRole('button', { name: '登録する' }))
    await screen.findByText('タイトルを確認してください。')
    expect(screen.getByLabelText(/^タイトル/)).toHaveValue('  架空の相談😀  ')
    expect(screen.getByLabelText(/^タイトル/)).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByRole('alert')).toHaveFocus()
    const user = userEvent.setup()
    await user.click(screen.getByLabelText(/^タイトル/)); await user.keyboard('修正')
    expect(screen.getByLabelText(/^タイトル/)).toHaveFocus()
  })
  it('focuses local validation and does not POST invalid boundaries', async () => {
    const { api } = await form()
    fireEvent.change(screen.getByLabelText(/^タイトル/), { target: { value: '😀'.repeat(101) } })
    fireEvent.click(screen.getByRole('button', { name: '登録する' }))
    expect(screen.getByRole('alert')).toHaveFocus(); expect(api.create).not.toHaveBeenCalled()
  })
  it.each([0, 503])('does not retry unknown outcome %s even after another submit or tab revalidation', async status => {
    const { api, session } = await form(); api.create.mockRejectedValue(new ApiError(status))
    fireEvent.click(screen.getByRole('button', { name: '登録する' }))
    await screen.findByText(/同じ文面の依頼があるだけでは/)
    await act(async () => { await session.create(); await session.resume(); await session.create() })
    expect(api.create).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('button', { name: '登録する' })).toBeDisabled()
  })
  it.each([401, 419])('clears draft and errors on POST expiry %s without a discard prompt', async status => {
    const { api, session } = await form(); const confirm = vi.spyOn(window, 'confirm')
    api.create.mockRejectedValue(new ApiError(status))
    fireEvent.click(screen.getByRole('button', { name: '登録する' }))
    await screen.findByRole('heading', { name: 'ログイン' })
    expect(session.snapshot()).toMatchObject({ user: null, detail: null, draft: { title: '', body: '' }, fields: {} })
    expect(confirm).not.toHaveBeenCalled()
  })
  it('asks before navigation, preserves on cancel, and clears after approval', async () => {
    const { session } = await form(); const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const user = userEvent.setup()
    await user.click(screen.getByRole('link', { name: '一覧へ戻る' }))
    expect(window.location.pathname).toBe('/requests/new'); expect(session.dirty()).toBe(true)
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(true)
    confirm.mockReturnValue(true); await user.click(screen.getByRole('link', { name: '一覧へ戻る' }))
    await screen.findByRole('heading', { name: '依頼一覧' }); expect(session.dirty()).toBe(false)
  })
  it('rechecks same identity before restoring a tab draft and erases it after account change', async () => {
    const { api, session } = await form()
    act(() => session.suspend()); expect(screen.queryByLabelText(/^内容/)).toBeNull()
    await act(async () => session.resume()); expect(session.dirty()).toBe(true)
    api.me.mockResolvedValue({ data: b })
    await act(async () => session.resume()); expect(session.snapshot().draft.body).toBe('')
  })
  it('rejects IT direct new route without treating new as ID', async () => {
    const { api, session } = setup('/requests/new', x); render(<App session={session} />)
    await screen.findByText('この操作は利用できません。')
    expect(screen.queryByLabelText(/^タイトル/)).toBeNull()
    await session.create(); expect(api.create).not.toHaveBeenCalled(); expect(api.detail).not.toHaveBeenCalled()
  })
  it('renders completed details and HTML-like body as text, with no workflow or comment controls', async () => {
    const { api, session } = setup('/requests/1')
    api.detail.mockResolvedValue({ data: { ...detail(), status: 'completed', body: '<script>window.__xss=1</script>\n次行' } })
    render(<App session={session} />)
    await screen.findByText('完了した依頼です。')
    expect(document.querySelector('.request-body')?.textContent).toBe('<script>window.__xss=1</script>\n次行')
    expect(document.querySelector('.request-body script')).toBeNull()
    expect(screen.queryByRole('button', { name: /変更|投稿/ })).toBeNull()
  })
  it.each([404, 503])('handles detail %s with no stale body and explicit reload', async status => {
    const { api, session } = setup('/requests/1'); api.detail.mockRejectedValue(new ApiError(status))
    render(<App session={session} />)
    await screen.findByRole('alert')
    expect(document.querySelector('.request-body')).toBeNull()
    if (status === 404) expect(screen.getByRole('alert')).toHaveTextContent('対象が見つかりません。')
    api.detail.mockResolvedValue({ data: detail() }); fireEvent.click(screen.getByRole('button', { name: '再読込' }))
    await waitFor(() => expect(document.querySelector('.request-body')).not.toBeNull())
  })
  it('discards late detail success and expiry from another request or account', async () => {
    const { api, session } = setup('/requests/1')
    const pending = deferred<{ data: RequestDetail }>(); api.detail.mockReturnValueOnce(pending.promise)
    const first = session.open('/requests/1', '')
    await waitFor(() => expect(api.detail).toHaveBeenCalled())
    api.detail.mockResolvedValue({ data: detail('2') }); await session.open('/requests/2', '')
    pending.resolve({ data: detail('1') }); await first
    expect(session.snapshot().detail?.id).toBe('2')
    const old = deferred<{ data: RequestDetail }>(); api.detail.mockReturnValueOnce(old.promise)
    const reload = session.loadDetail(); await session.logout()
    api.me.mockResolvedValue({ data: b }); await session.login('b@example.test', 'fixture')
    old.reject(new ApiError(401)); await reload
    expect(session.snapshot().user).toEqual(b); expect(session.snapshot().detail).toBeNull()
  })
  it('clears detail on expiry and ignores a late creation response after an auth boundary', async () => {
    const { api, session } = setup('/requests/1'); await session.open('/requests/1', '')
    api.detail.mockRejectedValue(new ApiError(419)); await session.loadDetail()
    expect(session.snapshot().detail).toBeNull(); expect(session.snapshot().phase).toBe('guest')
    api.me.mockResolvedValue({ data: a }); await session.open('/requests/new', '')
    session.editDraft({ title: '題', body: '内容' })
    const pending = deferred<{ data: RequestDetail }>(); api.create.mockReturnValue(pending.promise)
    const creating = session.create(); session.conceal(); pending.resolve({ data: detail('42') }); await creating
    expect(session.snapshot().detail).toBeNull(); expect(session.snapshot().draft.body).toBe('')
    expect(session.snapshot().busy).toBe(false)
  })
})
