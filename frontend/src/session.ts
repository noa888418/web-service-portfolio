import { ApiError } from './api'
import type { Api, CurrentUser, Fields, Listing } from './api'

export interface ViewState {
  phase: 'checking' | 'guest' | 'authenticated' | 'uncertain' | 'notFound'
  user: CurrentUser | null; listing: Listing | null; page: number
  busy: boolean; loadingList: boolean; csrfReady: boolean
  notice: string; fields: Fields; retryAt: number; listRestricted: boolean
}
const initial: ViewState = {
  phase: 'checking', user: null, listing: null, page: 1, busy: false,
  loadingList: false, csrfReady: false, notice: '', fields: {}, retryAt: 0, listRestricted: false,
}
export function pageNumber(search: string): number | null {
  const params = new URLSearchParams(search)
  if ([...params.keys()].some(key => key !== 'page') || params.getAll('page').length > 1) return null
  const value = params.get('page') ?? '1'
  return /^[1-9][0-9]*$/.test(value) && Number(value) <= 2147483647 ? Number(value) : null
}

// A boundary invalidates every previous read, including fetches that ignore AbortSignal.
// POSTs are never retried; aborting/hiding a view is not proof of server rollback.
export class Session {
  private state: ViewState = { ...initial }
  private listeners = new Set<() => void>()
  private generation = 0
  private read = new AbortController()
  private listRead = new AbortController()
  private listGeneration = 0
  private mutation = false
  constructor(private api: Api, private navigate: (path: string, replace: boolean) => void) {}
  snapshot = () => this.state
  subscribe = (listener: () => void) => { this.listeners.add(listener); return () => { this.listeners.delete(listener) } }
  private update(values: Partial<ViewState>) {
    this.state = { ...this.state, ...values }
    this.listeners.forEach(listener => listener())
  }
  private boundary() {
    this.read.abort(); this.listRead.abort()
    this.read = new AbortController(); this.listGeneration++
    this.update({ ...initial, page: this.state.page })
    return ++this.generation
  }
  conceal = () => {
    this.boundary()
    this.update({ phase: 'uncertain', busy: this.mutation, notice: '認証状態を確認してから表示します。' })
  }
  async open(path = window.location.pathname, search = window.location.search) {
    if (this.mutation) return
    if (!['/', '/login', '/requests'].includes(path)) {
      this.boundary(); this.update({ phase: 'notFound' }); return
    }
    const page = path === '/requests' ? pageNumber(search) : 1
    if (page === null) {
      this.boundary(); this.update({ phase: 'notFound', notice: 'ページ番号が正しくありません。' }); return
    }
    await this.check(page)
  }
  async check(page = this.state.page) {
    if (this.mutation) return
    const id = this.boundary()
    this.update({ busy: true, page })
    try {
      const { data } = await this.api.me(this.read.signal)
      if (id !== this.generation) return
      this.update({ phase: 'authenticated', user: data, busy: false })
      this.navigate(`/requests?page=${page}`, true)
      await this.load(page)
    } catch (error) {
      if (id !== this.generation) return
      if (error instanceof ApiError && error.status === 401) await this.guest(id, '')
      else this.update({ phase: 'uncertain', busy: false, notice: '認証状態を確認できません。接続と起動状態を確認して再確認してください。' })
    }
  }
  private async guest(id: number, notice: string, extra: Partial<ViewState> = {}) {
    if (id !== this.generation) return
    this.navigate('/login', true)
    this.update({ phase: 'guest', user: null, listing: null, loadingList: false,
      busy: true, csrfReady: false, notice, fields: {}, ...extra })
    try {
      await this.api.csrf()
      if (id === this.generation) this.update({ csrfReady: true })
    } catch {
      if (id === this.generation) this.update({ notice: 'ログインの準備に失敗しました。起動状態を確認して再確認してください。' })
    } finally {
      if (id === this.generation) this.update({ busy: false })
    }
  }
  async login(email: string, password: string) {
    if (this.mutation || this.state.busy || !this.state.csrfReady || Date.now() < this.state.retryAt) return
    this.mutation = true
    const id = this.boundary()
    this.update({ phase: 'guest', busy: true })
    try {
      await this.api.login(email.trim().replace(/[A-Z]/g, letter => letter.toLowerCase()), password)
      const { data } = await this.api.me(this.read.signal)
      if (id !== this.generation) return
      this.update({ phase: 'authenticated', user: data, busy: false, page: 1 })
      this.navigate('/requests?page=1', true)
      await this.load(1)
    } catch (error) {
      if (id !== this.generation) return
      const failure = error instanceof ApiError ? error : new ApiError(0)
      if (failure.status === 401) await this.guest(id, 'メールアドレスまたはパスワードを確認してください。')
      else if (failure.status === 422) await this.guest(id, '入力内容を確認してください。', { fields: failure.fields })
      else if (failure.status === 429) await this.guest(id, 'ログインの試行回数が上限に達しました。', { retryAt: Date.now() + failure.retryAfter * 1000 })
      else if (failure.status === 419) await this.guest(id, '認証状態が変わりました。パスワードを再入力してください。')
      else if (failure.status === 403) await this.guest(id, 'この操作は利用できません。入力内容を確認してください。')
      else this.update({ phase: 'uncertain', notice: 'ログインの結果を確認できません。再送せず認証状態を確認してください。' })
    } finally {
      this.mutation = false
      this.update({ busy: false })
    }
  }
  async logout() {
    if (this.mutation || this.state.phase !== 'authenticated') return
    this.mutation = true
    const id = this.boundary()
    this.update({ busy: true }) // Conceal all user data before even starting the POST.
    try {
      await this.api.logout()
      if (id === this.generation) await this.guest(id, 'ログアウトしました。')
    } catch {
      if (id === this.generation) this.update({ phase: 'uncertain',
        notice: 'ログアウトの完了を確認できません。認証状態を確認し、ログイン中なら改めてログアウトしてください。' })
    } finally {
      this.mutation = false; this.update({ busy: false })
    }
  }
  async load(page = this.state.page) {
    if (!this.state.user || this.state.phase !== 'authenticated') return
    const id = this.generation
    const listId = ++this.listGeneration
    this.listRead.abort(); this.listRead = new AbortController()
    this.update({ listing: null, page, loadingList: true, notice: '', listRestricted: false })
    try {
      const listing = await this.api.requests(page, this.listRead.signal)
      if (id === this.generation && listId === this.listGeneration) this.update({ listing })
    } catch (error) {
      if (id !== this.generation || listId !== this.listGeneration) return
      const status = error instanceof ApiError ? error.status : 0
      if (status === 401 || status === 419) {
        const next = this.boundary()
        await this.guest(next, 'セッションが終了しました。再度ログインしてください。')
      } else if (status === 403 || status === 404) {
        this.update({ listRestricted: true, notice: status === 403 ? 'この操作は利用できません。認証状態を再確認してください。' : '対象が見つかりません。認証状態を再確認してください。' })
      } else this.update({ notice: status === 422 ? 'ページ番号を確認してください。' : '一覧を取得できませんでした。接続と起動状態を確認して再読込してください。' })
    } finally {
      if (id === this.generation && listId === this.listGeneration) this.update({ loadingList: false })
    }
  }
  async go(page: number) {
    if (this.state.loadingList || this.mutation) return
    this.navigate(`/requests?page=${page}`, false)
    await this.load(page)
  }
}
