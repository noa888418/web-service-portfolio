import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import type { FormEvent } from 'react'
import type { Fields, RequestSummary } from './api'
import type { Session, ViewState } from './session'

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
function RequestRow({ row }: { row: RequestSummary }) {
  return <tr>
    <td data-label="ID" className="id">#{row.id}</td>
    <td data-label="タイトル" className="title-cell">{row.title}</td>
    <td data-label="種別">{categories[row.category]}</td>
    <td data-label="依頼者">{row.requester.display_name}</td>
    <td data-label="担当者">{row.assignee?.display_name ?? '未割当'}</td>
    <td data-label="状態"><span className={`status ${row.status}`}>{statuses[row.status]}</span></td>
    <td data-label="作成日時（JST）"><time dateTime={row.created_at}>{dates.format(new Date(row.created_at))}</time></td>
  </tr>
}
export function App({ session }: { session: Session }) {
  const state = useSyncExternalStore(session.subscribe, session.snapshot)
  const heading = useRef<HTMLHeadingElement>(null)
  useEffect(() => {
    void session.open()
    const pop = () => { void session.open() }
    const visibility = () => { if (document.hidden) session.conceal(); else void session.open() }
    const pageshow = (event: PageTransitionEvent) => { if (event.persisted) void session.open() }
    window.addEventListener('popstate', pop)
    document.addEventListener('visibilitychange', visibility)
    window.addEventListener('pageshow', pageshow)
    return () => {
      window.removeEventListener('popstate', pop)
      document.removeEventListener('visibilitychange', visibility)
      window.removeEventListener('pageshow', pageshow)
      session.conceal()
    }
  }, [session])
  useEffect(() => { heading.current?.focus() }, [state.phase, state.page])
  return <>
    <a className="skip" href="#main">本文へ移動</a>
    <header className="topbar"><div className="brand"><span className="brand-mark" aria-hidden="true">IT</span><div>社内IT依頼・改善管理<span className="brand-caption">LOCAL DEMO</span></div></div>
      {state.user && <div className="identity"><span>{state.user.display_name}<small>{roles[state.user.role]}</small></span><button onClick={() => void session.logout()} disabled={state.busy}>ログアウト</button></div>}
    </header>
    <main id="main">
      <aside className="demo-notice"><strong>架空データ専用</strong><span>実在の個人情報、パスワード、APIキー、社内の機密情報を入力しないでください。<br />ローカル検証用・外部公開していません。</span></aside>
      {state.phase === 'guest' && <Login session={session} state={state} />}
      {state.phase === 'checking' && <p className="empty" role="status" aria-busy="true">認証状態を確認しています…</p>}
      {state.phase === 'uncertain' && <section className="empty"><h1 ref={heading} tabIndex={-1}>認証状態の確認</h1><Alert message={state.notice} /><button disabled={state.busy} onClick={() => void session.check()}>認証状態を再確認</button></section>}
      {state.phase === 'notFound' && <section className="empty"><h1>ページが見つかりません</h1><p>{state.notice}</p><a href="/requests">依頼一覧へ</a></section>}
      {state.phase === 'authenticated' && state.user && <section aria-labelledby="page-title">
        <div className="page-heading"><div><span className="eyebrow">REQUESTS</span><h1 id="page-title" ref={heading} tabIndex={-1}>依頼一覧</h1><p className="subtle">{state.user.role === 'employee' ? '自分の依頼' : 'すべての依頼'} · 作成日時の新しい順</p></div>
          <button disabled={state.loadingList} onClick={() => void (state.listRestricted ? session.check() : session.load())}>{state.listRestricted ? '認証状態を再確認' : '再読込'}</button></div>
        <Alert message={state.notice} />
        <div aria-busy={state.loadingList}>
          {state.loadingList && <p className="empty" role="status">依頼を読み込んでいます…</p>}
          {state.listing && <>
            <p className="count" role="status">対象 {state.listing.meta.total}件 · {state.page} / {state.listing.meta.last_page}ページ</p>
            {state.listing.data.length ? <div className="table-wrap"><table><caption className="sr-only">閲覧可能な依頼。1ページ20件、作成日時・IDの降順</caption><thead><tr>{['ID', 'タイトル', '種別', '依頼者', '担当者', '状態', '作成日時（JST）'].map(label => <th scope="col" key={label}>{label}</th>)}</tr></thead><tbody>{state.listing.data.map(row => <RequestRow key={row.id} row={row} />)}</tbody></table></div>
              : <div className="empty"><p>{state.listing.meta.total === 0 ? '依頼はまだありません。' : 'このページに依頼はありません。'}</p>{state.page > 1 && <button onClick={() => void session.go(1)}>先頭ページへ</button>}</div>}
            <nav className="pagination" aria-label="依頼一覧のページ移動"><button disabled={state.page <= 1} onClick={() => void session.go(state.page - 1)}>前へ</button><span>{state.page}ページ</span><button disabled={state.page >= state.listing.meta.last_page} onClick={() => void session.go(state.page + 1)}>次へ</button></nav>
          </>}
        </div>
      </section>}
    </main><footer>IT REQUESTS · ローカル検証版</footer>
  </>
}
