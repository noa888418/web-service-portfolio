import { ApiError } from './api'
import type { Api, Candidates, CurrentUser, Fields, Listing, RequestDetail, RequestInput, UserRef } from './api'
import { blankDraft, validateRequest } from './request-input'
import { conflictMessage, transitions } from './workflow'
import type { Status } from './workflow'

export interface ViewState {
  phase: 'checking' | 'guest' | 'authenticated' | 'uncertain' | 'notFound'
  user: CurrentUser | null; listing: Listing | null; page: number
  busy: boolean; loadingList: boolean; csrfReady: boolean
  notice: string; fields: Fields; retryAt: number; listRestricted: boolean
  route: 'list' | 'new' | 'detail'; requestId: string; detail: RequestDetail | null
  draft: RequestInput; submission: 'idle' | 'sending' | 'unknown' | 'forbidden'
  candidates: Candidates | null; candidatePage: number; loadingCandidates: boolean
  selectedAssignee: UserRef | null; selectedStatus: Status | ''; completionConfirmed: boolean
  workflowReady: boolean; workflowNotice: string; workflowResult: string
}
const workflowInitial = { candidates: null, candidatePage: 1, loadingCandidates: false,
  selectedAssignee: null, selectedStatus: '' as const, completionConfirmed: false,
  workflowReady: false, workflowNotice: '', workflowResult: '' }
const initial: ViewState = {
  phase: 'checking', user: null, listing: null, page: 1, busy: false,
  loadingList: false, csrfReady: false, notice: '', fields: {}, retryAt: 0, listRestricted: false,
  route: 'list', requestId: '', detail: null, draft: blankDraft(), submission: 'idle',
  ...workflowInitial,
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
  private candidateRead = new AbortController()
  private candidateGeneration = 0
  private suspended: ViewState | null = null
  path = '/requests?page=1'
  constructor(private api: Api, private navigate: (path: string, replace: boolean) => void) {}
  snapshot = () => this.state
  subscribe = (listener: () => void) => { this.listeners.add(listener); return () => { this.listeners.delete(listener) } }
  private update(values: Partial<ViewState>) {
    this.state = { ...this.state, ...values }
    this.listeners.forEach(listener => listener())
  }
  private boundary() {
    this.suspended = null
    this.candidateRead.abort(); this.candidateGeneration++
    this.read.abort(); this.listRead.abort()
    this.read = new AbortController(); this.listGeneration++
    this.update({ ...initial, page: this.state.page })
    return ++this.generation
  }
  conceal = () => {
    const submitting = this.state.submission === 'sending'
    this.boundary()
    this.update({ phase: 'uncertain', busy: this.mutation, notice: submitting ? '登録結果を確認できません。再送せず認証状態と一覧を確認してください。' : '認証状態を確認してから表示します。' })
  }
  suspend = () => {
    const saved = this.state
    this.conceal()
    if (saved.phase === 'authenticated' && saved.route === 'new' && !saved.busy) this.suspended = saved
  }
  dirty = () => {
    const state = this.suspended ?? this.state
    return state.route === 'new' && (state.draft.title !== '' || state.draft.body !== '' || state.draft.category !== 'inquiry')
  }
  canLeave = () => !this.mutation && (!this.dirty() || window.confirm('入力内容を破棄して移動しますか？'))
  async visit(path: string) {
    if (!this.canLeave()) return
    this.navigate(path, false)
    const url = new URL(path, window.location.origin)
    await this.open(url.pathname, url.search)
  }
  // Tab return revalidates identity before restoring an in-memory draft. No persistence.
  async resume() {
    if (this.mutation) return
    const saved = this.suspended ?? this.state
    const restoring = saved.phase === 'authenticated' && saved.route === 'new'
    const pending = this.open()
    const id = this.generation
    await pending
    if (restoring && id === this.generation && this.state.user?.id === saved.user?.id && this.state.route === 'new' && this.state.user?.role === 'employee') {
      this.update({ draft: saved.draft, fields: saved.fields, submission: saved.submission, notice: saved.notice })
    }
  }
  async open(path = window.location.pathname, search = window.location.search) {
    if (this.mutation) return
    const match = /^\/requests\/([1-9][0-9]*)$/.exec(path)
    const detailId = match && match[1].length <= 19 && BigInt(match[1]) <= 9223372036854775807n ? match[1] : ''
    const route = path === '/requests/new' ? 'new' : detailId ? 'detail' : 'list'
    if ((!['/', '/login', '/requests', '/requests/new'].includes(path) && !detailId) || (route !== 'list' && search)) {
      this.boundary(); this.update({ phase: 'notFound' }); return
    }
    const page = path === '/requests' ? pageNumber(search) : 1
    if (page === null) {
      this.boundary(); this.update({ phase: 'notFound', notice: 'ページ番号が正しくありません。' }); return
    }
    this.path = path + search
    await this.check(page, route, detailId)
  }
  async check(page = this.state.page, route = this.state.route, requestId = this.state.requestId) {
    if (this.mutation) return
    const id = this.boundary()
    this.update({ busy: true, page, route, requestId })
    try {
      const { data } = await this.api.me(this.read.signal)
      if (id !== this.generation) return
      this.update({ phase: 'authenticated', user: data, busy: false })
      if (route === 'list') {
        this.path = `/requests?page=${page}`
        this.navigate(this.path, true)
        await this.load(page)
      } else if (route === 'detail') await this.loadDetail()
      else if (data.role !== 'employee') this.update({ submission: 'forbidden', notice: 'この操作は利用できません。' })
    } catch (error) {
      if (id !== this.generation) return
      if (error instanceof ApiError && [401, 419].includes(error.status)) await this.guest(id, '')
      else this.update({ phase: 'uncertain', busy: false, notice: '認証状態を確認できません。接続と起動状態を確認して再確認してください。' })
    }
  }
  private async guest(id: number, notice: string, extra: Partial<ViewState> = {}) {
    if (id !== this.generation) return
    this.navigate('/login', true)
    this.path = '/login'
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
      this.path = '/requests?page=1'
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
    this.path = `/requests?page=${page}`
    await this.load(page)
  }
  editDraft(values: Partial<RequestInput>) {
    if (this.state.phase !== 'authenticated' || this.state.route !== 'new' || this.state.user?.role !== 'employee' || this.state.submission !== 'idle' || this.mutation) return
    this.update({ draft: { ...this.state.draft, ...values }, fields: {}, notice: '' })
  }
  async create() {
    if (this.mutation || this.state.phase !== 'authenticated' || this.state.route !== 'new' || this.state.user?.role !== 'employee' || this.state.submission !== 'idle') return
    const { value, fields } = validateRequest(this.state.draft)
    this.update({ fields, notice: Object.keys(fields).length ? '入力内容を確認してください。' : '' })
    if (Object.keys(fields).length) return
    const id = this.generation
    this.mutation = true
    this.update({ busy: true, submission: 'sending' })
    try {
      const { data } = await this.api.create(value)
      if (id !== this.generation) return
      if (typeof data.id !== 'string' || !/^[1-9][0-9]*$/.test(data.id) || data.id.length > 19 || BigInt(data.id) > 9223372036854775807n) throw new ApiError(0)
      this.update({ draft: blankDraft(), fields: {}, submission: 'idle', route: 'detail', requestId: data.id })
      this.path = `/requests/${data.id}`
      this.navigate(this.path, true)
      await this.loadDetail(true)
    } catch (error) {
      if (id !== this.generation) return
      const failure = error instanceof ApiError ? error : new ApiError(0)
      if ([401, 419].includes(failure.status)) await this.guest(this.boundary(), 'セッションが終了しました。再度ログインしてください。')
      else if (failure.status === 422) this.update({ submission: 'idle', fields: failure.fields, notice: '入力内容を確認してください。' })
      else if (failure.status === 403) this.update({ draft: blankDraft(), submission: 'forbidden', notice: 'この操作は利用できません。' })
      else this.update({ submission: 'unknown', notice: '登録結果を確認できません。再送せず一覧を確認してください。同じ文面の依頼があるだけでは登録成功と判断できません。確認できない場合は作成者へ相談してください。' })
    } finally {
      this.mutation = false
      this.update({ busy: false })
    }
  }
  async loadDetail(internal = false, workflowNotice = '', refreshCandidates = true) {
    if ((this.mutation && !internal) || this.state.phase !== 'authenticated' || this.state.route !== 'detail') return
    const id = this.generation, readId = ++this.listGeneration
    this.listRead.abort(); this.listRead = new AbortController()
    this.candidateRead.abort(); this.candidateGeneration++
    this.update({ ...workflowInitial, workflowNotice, detail: null, loadingList: true, notice: '' })
    try {
      const { data } = await this.api.detail(this.state.requestId, this.listRead.signal)
      if (id !== this.generation || readId !== this.listGeneration) return
      this.update({ detail: data })
      if (refreshCandidates && this.manageable()) await this.loadCandidates(1, true)
    } catch (error) {
      if (id !== this.generation || readId !== this.listGeneration) return
      const status = error instanceof ApiError ? error.status : 0
      if ([401, 419].includes(status)) await this.guest(this.boundary(), 'セッションが終了しました。再度ログインしてください。')
      else this.update({ notice: status === 404 ? '対象が見つかりません。' : status === 403 ? 'この操作は利用できません。' : '詳細を取得できませんでした。接続と起動状態を確認して再読込してください。' })
    } finally {
      if (id === this.generation && readId === this.listGeneration) this.update({ loadingList: false })
    }
  }
  private manageable() {
    return this.state.phase === 'authenticated' && this.state.route === 'detail' && this.state.user?.role === 'it_staff' && !!this.state.detail && this.state.detail.status !== 'completed'
  }
  async loadCandidates(page = this.state.candidatePage, internal = false) {
    if (!this.manageable() || (this.mutation && !internal) || !Number.isInteger(page) || page < 1 || page > 2147483647) return
    const id = this.generation, candidateId = ++this.candidateGeneration
    this.candidateRead.abort(); this.candidateRead = new AbortController()
    this.update({ candidates: null, candidatePage: page, loadingCandidates: true, workflowReady: false })
    try {
      const candidates = await this.api.candidates(this.state.requestId, page, this.candidateRead.signal)
      if (id === this.generation && candidateId === this.candidateGeneration) this.update({ candidates, workflowReady: true })
    } catch (error) {
      if (id !== this.generation || candidateId !== this.candidateGeneration) return
      const failure = error instanceof ApiError ? error : new ApiError(0)
      this.update({ selectedAssignee: null, selectedStatus: '', completionConfirmed: false })
      if ([401, 419].includes(failure.status)) await this.guest(this.boundary(), 'セッションが終了しました。再度ログインしてください。')
      else if ([403, 404].includes(failure.status)) this.update({ detail: null, workflowNotice: '', notice: failure.status === 404 ? '対象が見つかりません。' : 'この操作は利用できません。' })
      // Bound recovery to one detail GET; never recurse if completion races again.
      else if (failure.status === 409) await this.loadDetail(true, conflictMessage(failure.code), false)
      else this.update({ workflowNotice: '担当候補を取得できませんでした。更新せず、最新情報を確認してください。' })
    } finally {
      if (id === this.generation && candidateId === this.candidateGeneration) this.update({ loadingCandidates: false })
    }
  }
  private canUpdate() { return this.manageable() && this.state.workflowReady && !this.state.loadingList && !this.state.loadingCandidates && !this.mutation }
  selectAssignee(id: string) {
    if (!this.canUpdate()) return
    const candidate = this.state.candidates?.data.find(candidate => candidate.id === id)
    if (candidate) this.update({ selectedAssignee: candidate, workflowResult: '' })
  }
  selectStatus(status: Status | '') {
    if (!this.canUpdate() || !this.state.detail?.assignee) return
    if (status === '' || transitions[this.state.detail.status].includes(status)) this.update({ selectedStatus: status, completionConfirmed: false, workflowResult: '' })
  }
  confirmCompletion(confirmed: boolean) {
    if (this.canUpdate() && this.state.selectedStatus === 'completed') this.update({ completionConfirmed: confirmed })
  }
  async changeAssignee(clear = false) {
    if (!this.canUpdate() || !this.state.detail) return
    if (clear ? this.state.detail.status !== 'open' || !this.state.detail.assignee : !this.state.selectedAssignee) return
    const value = clear ? null : this.state.selectedAssignee!.id
    await this.changeWorkflow('assignee', value)
  }
  async changeStatus() {
    const { detail, selectedStatus, completionConfirmed } = this.state
    if (!this.canUpdate() || !detail?.assignee || !selectedStatus || !transitions[detail.status].includes(selectedStatus) || (selectedStatus === 'completed' && !completionConfirmed)) return
    await this.changeWorkflow('status', selectedStatus)
  }
  private async changeWorkflow(kind: 'assignee' | 'status', value: string | null) {
    const row = this.state.detail!, id = this.generation
    this.mutation = true
    this.candidateRead.abort(); this.candidateGeneration++
    this.listRead.abort(); this.listGeneration++
    this.update({ busy: true, workflowReady: false, selectedAssignee: null, selectedStatus: '', completionConfirmed: false, workflowNotice: '', workflowResult: '' })
    try {
      const { data } = kind === 'assignee' ? await this.api.assignee(row.id, value, row.version) : await this.api.status(row.id, value as Status, row.version)
      if (id !== this.generation) return
      if (data.id !== row.id || !Number.isInteger(data.version) || data.version < 1 || data.version > 2147483647) throw new ApiError(0)
      this.update({ ...workflowInitial, detail: data, workflowResult: kind === 'assignee' ? '担当者を変更しました。' : '状態を変更しました。' })
      if (this.manageable()) await this.loadCandidates(1, true)
    } catch (error) {
      if (id !== this.generation) return
      const failure = error instanceof ApiError ? error : new ApiError(0)
      if ([401, 419].includes(failure.status)) await this.guest(this.boundary(), 'セッションが終了しました。再度ログインしてください。')
      else if ([403, 404].includes(failure.status)) this.update({ ...workflowInitial, detail: null, notice: failure.status === 404 ? '対象が見つかりません。' : 'この操作は利用できません。' })
      else if (failure.status === 409 || failure.status === 422) {
        await this.loadDetail(true, failure.status === 409 ? conflictMessage(failure.code) : '指定した担当者や入力を受け付けられませんでした。最新の候補と状態を確認して選び直してください。')
      } else this.update({ candidates: null, workflowNotice: '更新結果を確認できません。自動再送はしません。「最新情報を確認」で現在の担当・状態を確認してから、必要な操作を選び直してください。' })
    } finally {
      this.mutation = false
      this.update({ busy: false })
    }
  }
}
