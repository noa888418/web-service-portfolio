import { describe, expect, it } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { App } from './App'
import { ApiError } from './api'
import type { Candidates, RequestDetail } from './api'
import { Session } from './session'
import { transitions } from './workflow'
import { a, b, deferred, detail, mockedApi, x } from './test/fixtures'

const y = { id: '4', display_name: 'IT担当者Y' }
function candidates(page = 1): Candidates {
  return { data: page === 1 ? [x, y, ...Array.from({ length: 18 }, (_, i) => ({ id: String(i + 5), display_name: `候補${i}` }))] : [{ id: '23', display_name: '最終候補' }], meta: { current_page: page, per_page: 20, total: 21, last_page: 2 } }
}
function setup(row: RequestDetail = { ...detail(), assignee: y }) {
  window.history.replaceState(null, '', '/requests/1')
  const api = mockedApi(); api.me.mockResolvedValue({ data: x }); api.detail.mockResolvedValue({ data: row })
  api.candidates.mockImplementation((_id, page) => Promise.resolve(candidates(page)))
  const session = new Session(api, path => window.history.replaceState(null, '', path))
  return { api, session }
}
describe('workflow UI and state with mock APIs', () => {
  it('loads candidates only after authorized detail, pages 20/1, retains selection and does not infer inactive status', async () => {
    const { api, session } = setup({ ...detail(), assignee: { id: '99', display_name: 'ページ外の現担当' } })
    render(<App session={session} />); await screen.findByLabelText('IT担当者Y（#4）')
    const user = userEvent.setup()
    expect(screen.getAllByRole('radio')).toHaveLength(20)
    expect(api.candidates.mock.invocationCallOrder[0]).toBeGreaterThan(api.detail.mock.invocationCallOrder[0])
    await user.click(screen.getByLabelText('IT担当者Y（#4）'))
    await user.click(screen.getByRole('button', { name: '候補の次へ' }))
    await screen.findByLabelText('最終候補（#23）')
    expect(screen.getAllByRole('radio')).toHaveLength(1)
    expect(screen.getByText('選択中：IT担当者Y（#4）')).toBeInTheDocument()
    expect(screen.getByText('ページ外の現担当')).toBeInTheDocument()
    expect(screen.getByLabelText('変更先の状態')).toBeEnabled()
    await user.click(screen.getByRole('button', { name: '候補の前へ' }))
    expect(await screen.findByLabelText('IT担当者Y（#4）')).toBeChecked()
  })
  it.each(['employee', 'completed'])('does not fetch or expose workflow for %s', async kind => {
    const { api, session } = setup(kind === 'completed' ? { ...detail(), status: 'completed', assignee: y } : detail())
    if (kind === 'employee') api.me.mockResolvedValue({ data: a })
    render(<App session={session} />); await screen.findByText('端末の相談')
    expect(api.candidates).not.toHaveBeenCalled(); expect(screen.queryByRole('heading', { name: '担当・状態の変更' })).toBeNull()
    await session.changeAssignee(); await session.changeStatus()
    expect(api.assignee).not.toHaveBeenCalled(); expect(api.status).not.toHaveBeenCalled()
  })
  it.each(Object.keys(transitions) as Array<keyof typeof transitions>)('offers only permitted next states for %s', async status => {
    const { session } = setup({ ...detail(), status, assignee: y })
    render(<App session={session} />); await screen.findByText('端末の相談')
    if (status === 'completed') { expect(screen.queryByLabelText('変更先の状態')).toBeNull(); return }
    const select = await screen.findByLabelText('変更先の状態') as HTMLSelectElement
    expect(Array.from(select.options).map(option => option.value)).toEqual(['', ...transitions[status]])
    expect(!!screen.queryByRole('button', { name: '担当を解除' })).toBe(status === 'open')
  })
  it('disables starting without assignee and requires explicit completion confirmation', async () => {
    const { api, session } = setup(detail()); render(<App session={session} />)
    await screen.findByLabelText('IT担当者Y（#4）')
    expect(screen.getByLabelText('変更先の状態')).toBeDisabled()
    expect(screen.getByText(/担当者が未割当のため/)).toBeInTheDocument()
    api.detail.mockResolvedValue({ data: { ...detail(), assignee: y, status: 'waiting_confirmation' } })
    await act(async () => session.loadDetail())
    const user = userEvent.setup(); await user.selectOptions(screen.getByLabelText('変更先の状態'), 'completed')
    expect(screen.getByRole('button', { name: '完了にする' })).toBeDisabled()
    await session.changeStatus(); expect(api.status).not.toHaveBeenCalled()
    api.status.mockResolvedValue({ data: { ...detail(), assignee: y, status: 'completed', version: 19 } })
    const calls = api.candidates.mock.calls.length
    await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: '完了にする' }))
    await screen.findByText('完了した依頼です。')
    expect(api.status).toHaveBeenCalledWith('1', 'completed', 1)
    expect(api.candidates).toHaveBeenCalledTimes(calls)
    expect(session.snapshot().detail?.version).toBe(19)
    expect(screen.queryByRole('radio')).toBeNull()
    expect(screen.getByText('状態を変更しました。')).toHaveFocus()
  })
  it('shares a synchronous mutation guard across assignee/status/clear and uses returned version', async () => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    session.selectAssignee(x.id); session.selectStatus('in_progress')
    const pending = deferred<{ data: RequestDetail }>(); api.assignee.mockReturnValue(pending.promise)
    const first = session.changeAssignee()
    await session.changeAssignee(); await session.changeAssignee(true); await session.changeStatus(); await session.loadDetail()
    expect(api.assignee).toHaveBeenCalledTimes(1); expect(api.status).not.toHaveBeenCalled()
    expect(api.assignee).toHaveBeenCalledWith('1', '3', 1)
    pending.resolve({ data: { ...detail(), assignee: x, version: 37 } }); await first
    expect(session.snapshot()).toMatchObject({ selectedAssignee: null, selectedStatus: '', detail: { version: 37 } })
    session.selectStatus('in_progress'); await session.changeStatus()
    expect(api.status).toHaveBeenCalledWith('1', 'in_progress', 37)
  })
  it('allows clear only in open', async () => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    api.assignee.mockResolvedValue({ data: { ...detail(), version: 2 } }); await session.changeAssignee(true)
    expect(api.assignee).toHaveBeenCalledWith('1', null, 1)
    api.detail.mockResolvedValue({ data: { ...detail(), assignee: y, status: 'in_progress' } }); await session.loadDetail()
    await session.changeAssignee(true); expect(api.assignee).toHaveBeenCalledTimes(1)
  })
  it.each(['stale_version', 'request_completed', 'no_change', 'invalid_transition', 'invalid_assignee_state', 'unknown'])('explains 409 %s, resets choices and retrieves latest without retry', async code => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    session.selectAssignee(x.id)
    api.assignee.mockRejectedValue(new ApiError(409, {}, 0, code))
    api.detail.mockResolvedValue({ data: { ...detail(), assignee: y, version: 2, status: code === 'request_completed' ? 'completed' : 'in_progress' } })
    await session.changeAssignee()
    expect(api.assignee).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(2)
    expect(session.snapshot()).toMatchObject({ selectedAssignee: null, selectedStatus: '', completionConfirmed: false, detail: { version: 2 } })
    expect(session.snapshot().workflowNotice).toContain('選び直して')
    const expected = { stale_version: '古く', request_completed: '完了', no_change: '同じ', invalid_transition: '状態へ', invalid_assignee_state: '担当者の条件', unknown: '条件では' }[code]!
    expect(session.snapshot().workflowNotice).toContain(expected)
    expect(api.candidates).toHaveBeenCalledTimes(code === 'request_completed' ? 1 : 2)
  })
  it('422 refreshes candidates and requires reselection', async () => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    session.selectAssignee(y.id); api.assignee.mockRejectedValue(new ApiError(422))
    api.candidates.mockResolvedValue({ ...candidates(), data: [x] }); await session.changeAssignee()
    expect(session.snapshot().selectedAssignee).toBeNull(); expect(session.snapshot().workflowNotice).toContain('選び直して')
    await session.changeAssignee(); expect(api.assignee).toHaveBeenCalledTimes(1)
  })
  it.each([503, 0])('blocks all updates after uncertain %s until explicit successful refresh', async status => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    session.selectAssignee(x.id); api.assignee.mockRejectedValue(new ApiError(status)); await session.changeAssignee()
    expect(api.detail).toHaveBeenCalledTimes(1)
    expect(session.snapshot().workflowReady).toBe(false)
    session.selectStatus('in_progress'); await session.changeStatus(); await session.changeAssignee(true)
    expect(api.status).not.toHaveBeenCalled(); expect(api.assignee).toHaveBeenCalledTimes(1)
    api.detail.mockRejectedValueOnce(new ApiError(503)); await session.loadDetail()
    expect(session.snapshot().detail).toBeNull(); expect(session.snapshot().workflowReady).toBe(false)
    await session.loadDetail(); expect(session.snapshot().workflowReady).toBe(true)
    expect(api.assignee).toHaveBeenCalledTimes(1)
  })
  it('candidate failure clears selection and blocks both mutations', async () => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    session.selectAssignee(y.id); api.candidates.mockRejectedValue(new ApiError(503)); await session.loadCandidates(2)
    session.selectStatus('in_progress'); await session.changeStatus(); await session.changeAssignee(true)
    expect(session.snapshot()).toMatchObject({ workflowReady: false, selectedAssignee: null, candidates: null })
    expect(api.status).not.toHaveBeenCalled(); expect(api.assignee).not.toHaveBeenCalled()
  })
  it('failed recovery after 409 never leaves a usable old version', async () => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    session.selectStatus('in_progress'); api.status.mockRejectedValue(new ApiError(409, {}, 0, 'stale_version'))
    api.detail.mockRejectedValue(new ApiError(503)); await session.changeStatus()
    expect(session.snapshot()).toMatchObject({ detail: null, workflowReady: false, selectedStatus: '' })
  })
  it.each([401, 419])('erases detail/candidates/selections/errors on PATCH %s', async status => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    session.selectStatus('in_progress'); api.status.mockRejectedValue(new ApiError(status)); await session.changeStatus()
    expect(session.snapshot()).toMatchObject({ phase: 'guest', detail: null, candidates: null, selectedStatus: '', selectedAssignee: null, workflowNotice: '' })
  })
  it.each([401, 419])('erases protected data on candidate GET %s', async status => {
    const { api, session } = setup(); api.candidates.mockRejectedValue(new ApiError(status)); await session.open('/requests/1', '')
    expect(session.snapshot()).toMatchObject({ phase: 'guest', detail: null, candidates: null })
  })
  it('bounds recovery when candidates report completion and does not loop or expose controls', async () => {
    const { api, session } = setup(); api.candidates.mockRejectedValue(new ApiError(409, {}, 0, 'request_completed'))
    await session.open('/requests/1', '')
    expect(api.candidates).toHaveBeenCalledTimes(1); expect(api.detail).toHaveBeenCalledTimes(2)
    expect(session.snapshot().workflowReady).toBe(false)
  })
  it('discards old candidate pages, other-request results and expiry from a previous account', async () => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    const old = deferred<Candidates>(); api.candidates.mockReturnValueOnce(old.promise)
    const request = session.loadCandidates(2); await session.loadCandidates(1)
    old.resolve(candidates(2)); await request; expect(session.snapshot().candidatePage).toBe(1)
    const second = deferred<Candidates>(); api.candidates.mockReturnValueOnce(second.promise)
    const pending = session.loadCandidates(2)
    api.detail.mockResolvedValue({ data: detail('2') }); await session.open('/requests/2', '')
    second.resolve(candidates(2)); await pending; expect(session.snapshot().detail?.id).toBe('2'); expect(session.snapshot().candidatePage).toBe(1)
    const third = deferred<Candidates>(); api.candidates.mockReturnValueOnce(third.promise)
    const previous = session.loadCandidates(2); await session.logout(); api.me.mockResolvedValue({ data: b }); await session.login('b@example.test', 'fixture')
    third.reject(new ApiError(401)); await previous; expect(session.snapshot().user).toEqual(b); expect(session.snapshot().candidates).toBeNull()
  })
  it('ignores late PATCH success after concealment and only GETs after explicit recheck', async () => {
    const { api, session } = setup(); await session.open('/requests/1', '')
    const old = deferred<{ data: RequestDetail }>(); api.assignee.mockReturnValue(old.promise)
    session.selectAssignee(x.id); const pending = session.changeAssignee(); session.conceal()
    old.resolve({ data: { ...detail(), assignee: x, version: 2 } }); await pending
    expect(session.snapshot()).toMatchObject({ detail: null, candidates: null, workflowNotice: '', busy: false })
    api.me.mockResolvedValue({ data: b }); await session.open('/requests/2', '')
    expect(api.assignee).toHaveBeenCalledTimes(1); expect(session.snapshot().selectedAssignee).toBeNull()
  })
  it('blocks duplicate form submits and status clicks while pending, then announces result', async () => {
    const { api, session } = setup(); render(<App session={session} />)
    await screen.findByLabelText('IT担当者X（#3）'); const user = userEvent.setup()
    await user.click(screen.getByLabelText('IT担当者X（#3）'))
    await user.selectOptions(screen.getByLabelText('変更先の状態'), 'in_progress')
    const pending = deferred<{ data: RequestDetail }>(); api.assignee.mockReturnValue(pending.promise)
    const form = screen.getByRole('button', { name: '担当を変更' }).closest('form')!
    fireEvent.submit(form); fireEvent.submit(form)
    expect(screen.getByRole('button', { name: '状態を変更' })).toBeDisabled()
    await act(async () => pending.resolve({ data: { ...detail(), assignee: x, version: 2 } }))
    await waitFor(() => expect(screen.getByText('担当者を変更しました。')).toHaveFocus())
    expect(api.assignee).toHaveBeenCalledTimes(1); expect(api.status).not.toHaveBeenCalled()
  })
})
