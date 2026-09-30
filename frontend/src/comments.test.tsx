import { describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { App } from './App'
import { ApiError } from './api'
import type { Comments, Comment, CurrentUser } from './api'
import { Session } from './session'
import { validateComment } from './request-input'
import { a, b, x, detail, mockedApi, deferred } from './test/fixtures'

function rows(page = 1, total = 21): Comments {
  return { data: Array.from({ length: Math.min(20, Math.max(0, total - (page - 1) * 20)) }, (_, i) => ({
    id: String((page - 1) * 20 + i + 1), body: `本文${(page - 1) * 20 + i + 1}\n<script>window.bad=1</script>`,
    author: a, created_at: '2026-09-30T00:00:00.000000Z',
  })), meta: { current_page: page, per_page: 20, total, last_page: Math.max(1, Math.ceil(total / 20)) } }
}
function setup(user: CurrentUser = a) {
  window.history.replaceState(null, '', '/requests/1')
  const api = mockedApi(); api.me.mockResolvedValue({ data: user })
  const session = new Session(api, path => window.history.replaceState(null, '', path))
  return { api, session }
}
describe('comment normalization', () => {
  it.each([['日', true], ['😀'.repeat(2000), true], ['日'.repeat(2001), false], ['😀'.repeat(2001), false], ['', false], [' \u3000\r\n\u0085', false], ['a\0b', false], ['\ud800', false], ['\ufeff', true]])('checks codepoints and prohibited input (%#)', (body, valid) => {
    expect(validateComment(body).error === '').toBe(valid)
  })
  it('normalizes Unicode whitespace and CRLF/CR without interpreting markup', () => {
    expect(validateComment('　\r\n日\r\n😀\r<b>文字</b> \u0085')).toEqual({ body: '日\n😀\n<b>文字</b>', error: '' })
  })
})
describe('comments with mock APIs', () => {
  it('blocks duplicate form submissions and clears sent text before a slow refresh', async () => {
    const { api, session } = setup(); render(<App session={session} />); await screen.findByText('コメントはまだありません。')
    fireEvent.change(screen.getByLabelText(/コメント本文/), { target: { value: '送信する' } })
    const post = deferred<{ data: Comment }>(), refresh = deferred<{ data: ReturnType<typeof detail> }>()
    api.comment.mockReturnValueOnce(post.promise); api.detail.mockReturnValueOnce(refresh.promise)
    const form = screen.getByLabelText(/コメント本文/).closest('form')!
    fireEvent.submit(form); fireEvent.submit(form); fireEvent.click(screen.getByRole('button', { name: '投稿中…' }))
    expect(api.comment).toHaveBeenCalledTimes(1)
    await act(async () => post.resolve({ data: rows(1, 1).data[0] }))
    expect(session.snapshot().commentDraft).toBe(''); expect(session.snapshot().commentOutcome).toBe('refresh')
    expect(session.snapshot().commentNotice).toBe('投稿は成功しました。表示を更新しています…')
    await act(async () => refresh.resolve({ data: detail() }))
    expect(await screen.findByText('コメントを投稿しました。')).toHaveFocus()
  })
  it('preserves an IT draft when the same user completes the request', async () => {
    const { api, session } = setup(x); api.detail.mockResolvedValue({ data: { ...detail(), assignee: x, status: 'waiting_confirmation' } })
    await session.open(); session.editComment('下書き'); session.selectStatus('completed'); session.confirmCompletion(true)
    api.status.mockResolvedValue({ data: { ...detail(), assignee: x, status: 'completed', version: 9 } })
    await session.changeStatus(); await session.postComment()
    expect(session.snapshot().commentDraft).toBe('下書き'); expect(api.comment).not.toHaveBeenCalled()
  })
  it('does not request comments after detail 404, and erases a draft on later detail 404', async () => {
    const { api, session } = setup(); api.detail.mockRejectedValueOnce(new ApiError(404))
    await session.open(); expect(api.comments).not.toHaveBeenCalled()
    await session.loadDetail(); session.editComment('下書き')
    api.detail.mockRejectedValueOnce(new ApiError(404)); await session.loadDetail()
    expect(session.snapshot()).toMatchObject({ detail: null, commentDraft: '', comments: null })
  })
  it('keeps a completion-conflict draft hidden while the permission recheck fails', async () => {
    const { api, session } = setup(); render(<App session={session} />); await screen.findByText('コメントはまだありません。')
    fireEvent.change(screen.getByLabelText(/コメント本文/), { target: { value: '下書き' } })
    api.comment.mockRejectedValue(new ApiError(409, {}, 0, 'request_completed')); api.detail.mockRejectedValueOnce(new ApiError(503))
    await act(async () => session.postComment()); expect(screen.queryByRole('textbox')).toBeNull()
    expect(session.snapshot().commentDraft).toBe('下書き')
    api.detail.mockResolvedValue({ data: { ...detail(), status: 'completed' } })
    await act(async () => session.refreshComments()); expect(screen.getByLabelText(/未送信のコメント下書き/)).toHaveValue('下書き')
    expect(api.comment).toHaveBeenCalledTimes(1)
  })
  it('waits for authorized detail, pages old-first, keeps draft and renders text/JST', async () => {
    const { api, session } = setup(); const pending = deferred<{ data: ReturnType<typeof detail> }>()
    api.detail.mockReturnValueOnce(pending.promise); api.comments.mockImplementation((_id, page) => Promise.resolve(rows(page)))
    render(<App session={session} />)
    await waitFor(() => expect(api.detail).toHaveBeenCalled()); expect(api.comments).not.toHaveBeenCalled()
    await act(async () => pending.resolve({ data: detail() }))
    expect(document.querySelectorAll('.comment-list li')).toHaveLength(20)
    expect(document.querySelector('.comment-list script')).toBeNull()
    expect(screen.getAllByText('2026/09/30 09:00（JST）')).toHaveLength(20)
    fireEvent.change(screen.getByLabelText(/コメント本文/), { target: { value: '保持する下書き' } })
    await act(async () => session.loadComments(2))
    expect(screen.getByLabelText(/コメント本文/)).toHaveValue('保持する下書き')
    expect(document.querySelectorAll('.comment-list li')).toHaveLength(1)
    expect(api.comments).toHaveBeenLastCalledWith('1', 2, expect.any(AbortSignal))
  })
  it('distinguishes empty, out-of-range, loading and read failure', async () => {
    const { api, session } = setup(); render(<App session={session} />)
    await screen.findByText('コメントはまだありません。')
    api.comments.mockResolvedValueOnce(rows(3))
    await act(async () => session.loadComments(3)); expect(screen.getByText('このページにコメントはありません。')).toBeInTheDocument()
    const pending = deferred<Comments>(); api.comments.mockReturnValueOnce(pending.promise)
    act(() => { void session.loadComments(1) }); expect(screen.getByText('コメントを読み込んでいます…')).toBeInTheDocument()
    await act(async () => pending.reject(new ApiError(503)))
    expect(screen.getByRole('alert')).toHaveTextContent('コメントを取得できませんでした')
    expect(screen.queryByText('コメントはまだありません。')).toBeNull()
  })
  it('posts normalized body only, clears on 201, reloads last page without changing parent locally', async () => {
    const { api, session } = setup(); await session.open()
    const parent = session.snapshot().detail
    session.editComment('　日\r\n😀　'); api.comments.mockImplementation((_id, page) => Promise.resolve(rows(page)))
    await session.postComment()
    expect(api.comment).toHaveBeenCalledExactlyOnceWith('1', '日\n😀')
    expect(session.snapshot()).toMatchObject({ commentDraft: '', commentPage: 2, commentOutcome: 'success', detail: parent })
    expect(api.comments).toHaveBeenLastCalledWith('1', 2, expect.any(AbortSignal))
  })
  it('retains input and focuses 422 errors', async () => {
    const { api, session } = setup(); api.comment.mockRejectedValue(new ApiError(422, { body: ['コメントは1～2000文字で入力してください。'] }))
    render(<App session={session} />); await screen.findByText('コメントはまだありません。')
    fireEvent.change(screen.getByLabelText(/コメント本文/), { target: { value: '下書き' } })
    fireEvent.submit(screen.getByLabelText(/コメント本文/).closest('form')!)
    await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus())
    expect(screen.getByLabelText(/コメント本文/)).toHaveValue('下書き')
    expect(screen.getByLabelText(/コメント本文/)).toHaveAttribute('aria-invalid', 'true')
  })
  it.each(['detail', 'comments'] as const)('distinguishes successful POST then failed %s GET; retry only reads', async target => {
    const { api, session } = setup(); await session.open(); session.editComment('投稿する')
    api[target].mockRejectedValueOnce(new ApiError(503))
    await session.postComment()
    expect(session.snapshot()).toMatchObject({ commentDraft: '', commentOutcome: 'refresh' })
    expect(session.snapshot().commentNotice).toContain('投稿は成功しましたが表示更新に失敗しました')
    await session.postComment(); await session.refreshComments()
    expect(api.comment).toHaveBeenCalledTimes(1); expect(session.snapshot().commentOutcome).toBe('success')
  })
  it.each([0, 500, 503])('blocks unknown POST %i even after matching text, GET or discard', async status => {
    const { api, session } = setup(); await session.open(); session.editComment('本文1\n<script>window.bad=1</script>')
    api.comment.mockRejectedValue(new ApiError(status)); await session.postComment()
    api.comments.mockResolvedValue(rows(1, 1)); await session.refreshComments(); await session.postComment()
    expect(session.snapshot().commentOutcome).toBe('unknown'); expect(session.snapshot().commentNotice).toContain('同じ本文があるだけでは')
    session.discardComment(); session.editComment('再送'); await session.postComment()
    expect(api.comment).toHaveBeenCalledTimes(1); expect(session.snapshot().commentDraft).toBe('')
  })
  it('handles completion 409 with retained disabled draft and explicit discard, never replay', async () => {
    const { api, session } = setup(); render(<App session={session} />); await screen.findByText('コメントはまだありません。')
    fireEvent.change(screen.getByLabelText(/コメント本文/), { target: { value: '未送信' } })
    api.comment.mockRejectedValue(new ApiError(409, {}, 0, 'request_completed'))
    api.detail.mockResolvedValue({ data: { ...detail(), status: 'completed' } })
    await act(async () => session.postComment())
    expect(screen.getByLabelText(/未送信のコメント下書き/)).toHaveValue('未送信')
    expect(screen.getByLabelText(/未送信のコメント下書き/)).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'コメントを投稿' })).toBeNull()
    await session.postComment(); expect(api.comment).toHaveBeenCalledTimes(1)
    fireEvent.click(screen.getByRole('button', { name: 'コメント下書きを破棄' }))
    expect(screen.queryByRole('textbox')).toBeNull()
  })
  it('initial completed page has comments but no posting form', async () => {
    const { api, session } = setup(); api.detail.mockResolvedValue({ data: { ...detail(), status: 'completed' } })
    render(<App session={session} />); await screen.findByText('コメントはまだありません。')
    expect(screen.queryByRole('textbox')).toBeNull(); expect(api.comments).toHaveBeenCalledTimes(1)
  })
  it.each([401, 419, 404])('clears protected data/draft on comment %i', async status => {
    const { api, session } = setup(); await session.open(); session.editComment('機密ではない下書き')
    api.comments.mockRejectedValueOnce(new ApiError(status)); await session.loadComments()
    expect(session.snapshot()).toMatchObject({ comments: null, commentDraft: '', commentNotice: '', detail: null })
  })
  it.each([401, 419, 404])('clears protected data/draft on POST %i', async status => {
    const { api, session } = setup(); await session.open(); session.editComment('下書き')
    api.comment.mockRejectedValueOnce(new ApiError(status)); await session.postComment()
    expect(session.snapshot()).toMatchObject({ comments: null, commentDraft: '', commentNotice: '', detail: null })
  })
  it('shares mutation guard in both directions with assignee/status and duplicate Enter/click', async () => {
    const { api, session } = setup(x); await session.open(); session.editComment('投稿')
    session.selectAssignee(x.id); const pending = deferred<{ data: Comment }>(); api.comment.mockReturnValueOnce(pending.promise)
    const post = session.postComment(); await session.postComment(); await session.changeAssignee(); await session.changeStatus()
    expect(api.comment).toHaveBeenCalledTimes(1); expect(api.assignee).not.toHaveBeenCalled(); expect(api.status).not.toHaveBeenCalled()
    pending.resolve({ data: rows(1, 1).data[0] }); await post
    session.editComment('次の投稿'); session.selectAssignee(x.id)
    const patch = deferred<{ data: ReturnType<typeof detail> }>(); api.assignee.mockReturnValueOnce(patch.promise)
    const update = session.changeAssignee(); await session.postComment(); expect(api.comment).toHaveBeenCalledTimes(1)
    patch.resolve({ data: { ...detail(), assignee: x, version: 2 } }); await update
  })
  it('drops old page and request responses', async () => {
    const { api, session } = setup(); await session.open(); const late = deferred<Comments>()
    api.comments.mockReturnValueOnce(late.promise); const pending = session.loadComments(2)
    await session.loadComments(1); late.resolve(rows(2)); await pending
    expect(session.snapshot().commentPage).toBe(1); expect(session.snapshot().comments?.data).toEqual([])
    const other = deferred<Comments>(); api.comments.mockReturnValueOnce(other.promise); const previous = session.loadComments()
    api.detail.mockResolvedValue({ data: detail('2') }); await session.open('/requests/2', '')
    other.resolve(rows()); await previous; expect(session.snapshot().comments?.data).toEqual([])
  })
  it('drops a late POST after conceal and account switch', async () => {
    const { api, session } = setup(); await session.open(); session.editComment('以前の本文')
    const late = deferred<{ data: Comment }>(); api.comment.mockReturnValueOnce(late.promise)
    const post = session.postComment(); session.conceal(); late.resolve({ data: rows(1, 1).data[0] }); await post
    api.me.mockResolvedValue({ data: b }); await session.open('/requests', '')
    expect(session.snapshot()).toMatchObject({ user: b, commentDraft: '', comments: null, commentNotice: '' })
  })
  it.each(['same', 'other', '404', '401'])('tab return restores only authorized same identity/request (%s)', async kind => {
    const { api, session } = setup(); await session.open(); session.editComment('下書き')
    session.suspend(); expect(session.snapshot()).toMatchObject({ user: null, detail: null, comments: null, commentDraft: '' })
    if (kind === 'other') api.me.mockResolvedValue({ data: b })
    if (kind === '404') api.detail.mockRejectedValue(new ApiError(404))
    if (kind === '401') api.me.mockRejectedValue(new ApiError(401))
    await session.resume(); expect(session.snapshot().commentDraft).toBe(kind === 'same' ? '下書き' : '')
  })
  it('suspending a pending POST restores conservative unknown outcome after reauthorization', async () => {
    const { api, session } = setup(); await session.open(); session.editComment('送信中')
    const late = deferred<{ data: Comment }>(); api.comment.mockReturnValueOnce(late.promise)
    const post = session.postComment(); session.suspend(); late.resolve({ data: rows(1, 1).data[0] }); await post
    await session.resume(); expect(session.snapshot()).toMatchObject({ commentOutcome: 'unknown', commentDraft: '送信中' })
    await session.postComment(); expect(api.comment).toHaveBeenCalledTimes(1)
  })
  it('confirms ordinary departure and clears on logout regardless of draft', async () => {
    const { session } = setup(); await session.open(); session.editComment('下書き')
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    await session.visit('/requests/2'); expect(session.snapshot().requestId).toBe('1'); expect(confirm).toHaveBeenCalledTimes(1)
    await session.logout(); expect(session.snapshot().commentDraft).toBe(''); confirm.mockRestore()
  })
})
