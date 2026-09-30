import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import type { FormEvent, ReactNode } from 'react'
import type { Fields, RequestSummary } from './api'
import type { Session, ViewState } from './session'
import { normalizeText } from './request-input'
import { WorkflowControls } from './WorkflowControls'
import { CommentFeedback, CommentsView } from './Comments'

const roles = { employee: '社員', it_staff: 'IT担当者' }
const categories = { inquiry: '問い合わせ', bug: '不具合', improvement: '改善要望' }
const statuses = { open: '未対応', in_progress: '対応中', waiting_confirmation: '確認待ち', completed: '完了' }
const dates = new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

function Alert({ message }: { message: string }) {
  const ref = useRef<HTMLDivElement>(null)
  useEffect(() => { if (message) ref.current?.focus() }, [message])
  return message ? <div className="alert" role="alert" tabIndex={-1} ref={ref}>{message}</div> : null
}
function Login({ state, session }: { state: ViewState; session: Session }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [fields, setFields] = useState<Fields>({})
  const [now, setNow] = useState(Date.now())
  useEffect(() => {
    if (!state.retryAt) return
    const timer = setInterval(() => setNow(Date.now()), 250)
    return () => clearInterval(timer)
  }, [state.retryAt])
  const wait = Math.max(0, Math.ceil((state.retryAt - now) / 1000))
  const errors = { ...state.fields, ...fields }
  async function submit(event: FormEvent) {
    event.preventDefault()
    if (state.busy || wait || !state.csrfReady) return
    const normalized = email.trim().replace(/[A-Z]/g, character => character.toLowerCase())
    const next: Fields = {}
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized) || normalized.length > 254) next.email = ['有効なメールアドレスを入力してください。']
    if (Array.from(password).length < 1 || Array.from(password).length > 128) next.password = ['パスワードは1～128文字で入力してください。']
    setFields(next)
    if (Object.keys(next).length) return
    const value = password
    setPassword('') // Do not retain a submitted password, including error paths.
    await session.login(normalized, value)
  }
  return <section className="login-card" aria-labelledby="page-title">
    <span className="eyebrow">ACCOUNT ACCESS</span>
    <h1 id="page-title">ログイン</h1>
    <p className="subtle">配布されたローカルデモ用アカウントを使用してください。</p>
    <Alert message={Object.keys(fields).length ? '入力内容を確認してください。' : state.notice} />
    {wait > 0 && <p role="status">あと{wait}秒お待ちください。時間経過後に手動で送信できます。</p>}
    <form onSubmit={submit} noValidate aria-busy={state.busy}>
      <label htmlFor="email">メールアドレス <span className="required">必須</span></label>
      <input id="email" name="email" type="email" autoComplete="username" maxLength={254} value={email}
        disabled={state.busy} onChange={e => setEmail(e.target.value)} aria-invalid={!!errors.email} aria-describedby="email-help email-error" />
      <small id="email-help">example.testのデモ用メールアドレス</small>
      <span className="field-error" id="email-error">{errors.email?.join(' ')}</span>
      <label htmlFor="password">パスワード <span className="required">必須</span></label>
      <input id="password" name="password" type="password" autoComplete="current-password" value={password}
        disabled={state.busy} onChange={e => setPassword(e.target.value)} aria-invalid={!!errors.password} aria-describedby="password-help password-error" />
      <small id="password-help">1～128文字。実在サービスのパスワードは入力しないでください。</small>
      <span className="field-error" id="password-error">{errors.password?.join(' ')}</span>
      <button className="primary full" disabled={state.busy || !state.csrfReady || wait > 0}>{state.busy ? '確認中…' : 'ログイン'}</button>
    </form>
    {!state.csrfReady && !state.busy && <button onClick={() => void session.check()}>認証状態を再確認</button>}
  </section>
}
function RouteLink({ to, session, children }: { to: string; session: Session; children: ReactNode }) {
  return <a href={to} onClick={event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault(); void session.visit(to)
  }}>{children}</a>
}
function RequestRow({ row, session }: { row: RequestSummary; session: Session }) {
  return <tr>
    <td data-label="ID" className="id">#{row.id}</td>
    <td data-label="タイトル" className="title-cell"><RouteLink to={`/requests/${row.id}`} session={session}>{row.title}</RouteLink></td>
    <td data-label="種別">{categories[row.category]}</td>
    <td data-label="依頼者">{row.requester.display_name}</td>
    <td data-label="担当者">{row.assignee?.display_name ?? '未割当'}</td>
    <td data-label="状態"><span className={`status ${row.status}`}>{statuses[row.status]}</span></td>
    <td data-label="作成日時（JST）"><time dateTime={row.created_at}>{dates.format(new Date(row.created_at))}</time></td>
  </tr>
}
function NewRequest({ state, session }: { state: ViewState; session: Session }) {
  const blocked = state.busy || state.submission !== 'idle'
  return <section className="request-card" aria-labelledby="page-title">
    <span className="eyebrow">NEW REQUEST</span><h1 id="page-title" tabIndex={-1}>依頼を登録</h1>
    <Alert message={state.notice} />
    {state.submission !== 'forbidden' && <form noValidate aria-busy={state.busy} onSubmit={event => { event.preventDefault(); void session.create() }}>
      <label htmlFor="title">タイトル <span className="required">必須</span></label>
      <input id="title" value={state.draft.title} disabled={blocked} onChange={event => session.editDraft({ title: event.target.value })} aria-invalid={!!state.fields.title} aria-describedby="title-help title-error" />
      <small id="title-help">改行なし・1～100文字。正規化後 {Array.from(normalizeText(state.draft.title)).length}文字</small>
      <span id="title-error" className="field-error">{state.fields.title?.join(' ')}</span>
      <label htmlFor="category">種別 <span className="required">必須</span></label>
      <select id="category" value={state.draft.category} disabled={blocked} onChange={event => session.editDraft({ category: event.target.value as RequestSummary['category'] })} aria-invalid={!!state.fields.category} aria-describedby="category-error">
        {Object.entries(categories).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
      </select><span id="category-error" className="field-error">{state.fields.category?.join(' ')}</span>
      <label htmlFor="body">内容 <span className="required">必須</span></label>
      <textarea id="body" rows={9} value={state.draft.body} disabled={blocked} onChange={event => session.editDraft({ body: event.target.value })} aria-invalid={!!state.fields.body} aria-describedby="body-help body-error" />
      <small id="body-help">1～5000文字・改行可。前後の空白を除去します。日本語・絵文字はコードポイントで数えます。正規化後 {Array.from(normalizeText(state.draft.body)).length}文字</small>
      <span id="body-error" className="field-error">{state.fields.body?.join(' ')}</span>
      <div className="actions"><button className="primary" disabled={blocked}>{state.submission === 'sending' ? '登録中…' : '登録する'}</button></div>
    </form>}
    <RouteLink session={session} to="/requests">一覧へ戻る</RouteLink>
  </section>
}
function RequestDetailView({ state, session }: { state: ViewState; session: Session }) {
  const row = state.detail
  const result = useRef<HTMLParagraphElement>(null)
  useEffect(() => { if (state.workflowResult) result.current?.focus() }, [state.workflowResult])
  return <section className="request-card" aria-labelledby="page-title" aria-busy={state.loadingList}>
    <div className="page-heading"><div><span className="eyebrow">REQUEST DETAIL</span><h1 id="page-title" tabIndex={-1}>依頼詳細</h1></div><button disabled={state.loadingList || state.busy} onClick={() => void session.loadDetail()}>再読込</button></div>
    <Alert message={state.notice} />
    <Alert message={state.workflowNotice} />
    <CommentFeedback state={state} session={session} />
    {state.workflowResult && <p className="result" role="status" tabIndex={-1} ref={result}>{state.workflowResult}</p>}
    {state.loadingList && <p role="status">詳細を読み込んでいます…</p>}
    {row && <>
      <h2 className="detail-title">{row.title}</h2>
      <dl className="detail-meta">
        <div><dt>ID</dt><dd>#{row.id}</dd></div>
        <div><dt>種別</dt><dd>{categories[row.category]}</dd></div>
        <div><dt>依頼者</dt><dd>{row.requester.display_name}</dd></div>
        <div><dt>担当者</dt><dd>{row.assignee?.display_name ?? '未割当'}</dd></div>
        <div><dt>状態</dt><dd><span className={`status ${row.status}`}>{statuses[row.status]}</span></dd></div>
        <div><dt>作成日時（JST）</dt><dd><time dateTime={row.created_at}>{dates.format(new Date(row.created_at))}</time></dd></div>
        <div><dt>更新日時（JST）</dt><dd><time dateTime={row.updated_at}>{dates.format(new Date(row.updated_at))}</time></dd></div>
      </dl>
      <h2>内容</h2><p className="request-body">{row.body}</p>
      {row.status === 'completed' && <p role="status">完了した依頼です。</p>}
      <WorkflowControls state={state} session={session} />
      <CommentsView state={state} session={session} />
    </>}
    <RouteLink session={session} to="/requests">一覧へ戻る</RouteLink>
  </section>
}
export function App({ session }: { session: Session }) {
  const state = useSyncExternalStore(session.subscribe, session.snapshot)
  const heading = useRef<HTMLHeadingElement>(null)
  const focusedRoute = useRef('')
  const routeKey = `${state.phase}:${state.route}:${state.page}:${state.requestId}`
  useEffect(() => {
    void session.open()
    const pop = () => {
      if (!session.canLeave()) { window.history.pushState(null, '', session.path); return }
      void session.open()
    }
    const visibility = () => {
      if (document.hidden) session.suspend()
      else void session.resume()
    }
    const unload = (event: BeforeUnloadEvent) => {
      if (session.dirty() || session.snapshot().submission === 'sending' || session.snapshot().commentOutcome === 'sending') { event.preventDefault(); event.returnValue = '' }
    }
    const pageshow = (event: PageTransitionEvent) => { if (event.persisted) void session.open() }
    window.addEventListener('popstate', pop)
    document.addEventListener('visibilitychange', visibility)
    window.addEventListener('pageshow', pageshow)
    window.addEventListener('beforeunload', unload)
    return () => {
      window.removeEventListener('popstate', pop)
      document.removeEventListener('visibilitychange', visibility)
      window.removeEventListener('pageshow', pageshow)
      window.removeEventListener('beforeunload', unload)
      session.conceal()
    }
  }, [session])
  useEffect(() => {
    if (focusedRoute.current === routeKey) return
    focusedRoute.current = routeKey
    if (!state.notice) (heading.current ?? document.getElementById('page-title'))?.focus()
  }, [routeKey, state.notice])
  return <>
    <a className="skip" href="#main">本文へ移動</a>
    <header className="topbar"><div className="brand"><span className="brand-mark" aria-hidden="true">IT</span><div>社内IT依頼・改善管理<span className="brand-caption">LOCAL DEMO</span></div></div>
      {state.user && <div className="identity"><span>{state.user.display_name}<small>{roles[state.user.role]}</small></span><button onClick={() => { if (session.canLeave()) void session.logout() }} disabled={state.busy}>ログアウト</button></div>}
    </header>
    <main id="main">
      <aside className="demo-notice"><strong>架空データ専用</strong><span>実在の個人情報、パスワード、APIキー、社内の機密情報を入力しないでください。<br />ローカル検証用・外部公開していません。</span></aside>
      {state.phase === 'guest' && <Login session={session} state={state} />}
      {state.phase === 'checking' && <p className="empty" role="status" aria-busy="true">認証状態を確認しています…</p>}
      {state.phase === 'uncertain' && <section className="empty"><h1 ref={heading} tabIndex={-1}>認証状態の確認</h1><Alert message={state.notice} /><button disabled={state.busy} onClick={() => void session.resume()}>認証状態を再確認</button></section>}
      {state.phase === 'notFound' && <section className="empty"><h1>ページが見つかりません</h1><p>{state.notice}</p><a href="/requests">依頼一覧へ</a></section>}
      {state.phase === 'authenticated' && state.route === 'new' && <NewRequest state={state} session={session} />}
      {state.phase === 'authenticated' && state.route === 'detail' && <RequestDetailView state={state} session={session} />}
      {state.phase === 'authenticated' && state.user && state.route === 'list' && <section aria-labelledby="page-title">
        <div className="page-heading"><div><span className="eyebrow">REQUESTS</span><h1 id="page-title" ref={heading} tabIndex={-1}>依頼一覧</h1><p className="subtle">{state.user.role === 'employee' ? '自分の依頼' : 'すべての依頼'} · 作成日時の新しい順</p></div>
          <div className="actions">{state.user.role === 'employee' && <RouteLink session={session} to="/requests/new">依頼を登録</RouteLink>}<button disabled={state.loadingList} onClick={() => void (state.listRestricted ? session.check() : session.load())}>{state.listRestricted ? '認証状態を再確認' : '再読込'}</button></div></div>
        <Alert message={state.notice} />
        <div aria-busy={state.loadingList}>
          {state.loadingList && <p className="empty" role="status">依頼を読み込んでいます…</p>}
          {state.listing && <>
            <p className="count" role="status">対象 {state.listing.meta.total}件 · {state.page} / {state.listing.meta.last_page}ページ</p>
            {state.listing.data.length ? <div className="table-wrap"><table><caption className="sr-only">閲覧可能な依頼。1ページ20件、作成日時・IDの降順</caption><thead><tr>{['ID', 'タイトル', '種別', '依頼者', '担当者', '状態', '作成日時（JST）'].map(label => <th scope="col" key={label}>{label}</th>)}</tr></thead><tbody>{state.listing.data.map(row => <RequestRow key={row.id} row={row} session={session} />)}</tbody></table></div>
              : <div className="empty"><p>{state.listing.meta.total === 0 ? '依頼はまだありません。' : 'このページに依頼はありません。'}</p>{state.listing.meta.total === 0 && state.user.role === 'employee' && <RouteLink session={session} to="/requests/new">最初の依頼を登録</RouteLink>}{state.page > 1 && <button onClick={() => void session.go(1)}>先頭ページへ</button>}</div>}
            <nav className="pagination" aria-label="依頼一覧のページ移動"><button disabled={state.page <= 1} onClick={() => void session.go(state.page - 1)}>前へ</button><span>{state.page}ページ</span><button disabled={state.page >= state.listing.meta.last_page} onClick={() => void session.go(state.page + 1)}>次へ</button></nav>
          </>}
        </div>
      </section>}
    </main><footer>IT REQUESTS · ローカル検証版</footer>
  </>
}
