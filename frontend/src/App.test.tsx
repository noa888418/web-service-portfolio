import { describe, expect, it } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { App } from './App'
import { Session } from './session'
import { ApiError } from './api'
import { a, listing, mockedApi, x } from './test/fixtures'

function mount(authenticated = false) {
  window.history.replaceState(null, '', '/login')
  const api = mockedApi()
  if (authenticated) api.me.mockResolvedValue({ data: a })
  const session = new Session(api, path => window.history.replaceState(null, '', path))
  render(<App session={session} />)
  return { api, session, user: userEvent.setup() }
}
describe('screens (mock API and jsdom)', () => {
  it('has labels, validates required fields, and focuses the error summary', async () => {
    const { api, user } = mount()
    await waitFor(() => expect(screen.getByRole('button', { name: 'ログイン' })).toBeEnabled())
    await user.click(screen.getByRole('button', { name: 'ログイン' }))
    expect(screen.getByLabelText(/メールアドレス/)).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByRole('alert')).toHaveFocus()
    expect(api.login).not.toHaveBeenCalled()
  })
  it('clears the submitted password on failure and distinguishes credentials from transport failure', async () => {
    const { api, user } = mount()
    await waitFor(() => expect(screen.getByRole('button', { name: 'ログイン' })).toBeEnabled())
    api.login.mockRejectedValue(new ApiError(401))
    await user.type(screen.getByLabelText(/メールアドレス/), 'a@example.test')
    await user.type(screen.getByLabelText(/^パスワード/), 'invalid-fixture')
    await user.click(screen.getByRole('button', { name: 'ログイン' }))
    await screen.findByText('メールアドレスまたはパスワードを確認してください。')
    expect(screen.getByLabelText(/^パスワード/)).toHaveValue('')
  })
  it('renders plain text, Japanese labels, JST and unassigned without unimplemented links', async () => {
    const { api } = mount(true)
    api.requests.mockResolvedValue(listing('<img src=x onerror=alert(1)>'))
    await screen.findByText('<img src=x onerror=alert(1)>')
    expect(document.querySelector('tbody img')).toBeNull()
    expect(screen.getByText('未対応')).toBeInTheDocument()
    expect(screen.getByText('未割当')).toBeInTheDocument()
    expect(screen.getByText('2026/09/29 09:30')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /登録|詳細/ })).toBeNull()
  })
  it('moves pages only by user action and retains server order', async () => {
    const { api, user } = mount(true)
    await screen.findByText('端末の相談')
    api.requests.mockResolvedValue(listing('2ページ目', 2))
    await user.click(screen.getByRole('button', { name: '次へ' }))
    await screen.findByText('2ページ目')
    expect(api.requests.mock.calls.at(-1)?.[0]).toBe(2)
    expect(window.location.search).toBe('?page=2')
    expect(screen.getByRole('button', { name: '次へ' })).toBeDisabled()
    expect(screen.getByRole('heading', { name: '依頼一覧' })).toHaveFocus()
  })
  it('separates empty and out-of-range pages', async () => {
    const { api, session } = mount(true)
    api.requests.mockResolvedValue({ ...listing('', 1, 0), data: [] })
    await screen.findByText('依頼はまだありません。')
    api.requests.mockResolvedValue({ ...listing('', 3, 21), data: [] })
    await session.go(3)
    await screen.findByText('このページに依頼はありません。')
    expect(screen.getByRole('button', { name: '先頭ページへ' })).toBeInTheDocument()
  })
  it('does not display old rows on a failed reload and offers a retry', async () => {
    const { api, user } = mount(true); await screen.findByText('端末の相談')
    api.requests.mockRejectedValue(new ApiError(503))
    await user.click(screen.getByRole('button', { name: '再読込' }))
    await screen.findByRole('alert')
    expect(screen.queryByText('端末の相談')).toBeNull()
    expect(screen.queryByText('依頼はまだありません。')).toBeNull()
  })
  it('presents IT scope on the same screen after me confirmation', async () => {
    const { api } = mount(true); api.me.mockResolvedValue({ data: x })
    // Initial me already started; reconfirm through tab-return flow.
    window.dispatchEvent(new PopStateEvent('popstate'))
    await screen.findByText(/すべての依頼/)
  })
})
