import { useEffect, useRef } from 'react'
import type { Session, ViewState } from './session'
import { normalizeText } from './request-input'

const dates = new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

// Render feedback outside the protected detail so a failed post-success GET remains explainable.
export function CommentFeedback({ state, session }: { state: ViewState; session: Session }) {
  const ref = useRef<HTMLParagraphElement>(null)
  useEffect(() => { if (state.commentNotice) ref.current?.focus() }, [state.commentNotice])
  return state.commentNotice ? <div>
    <p className="result" role={state.commentOutcome === 'success' ? 'status' : 'alert'} tabIndex={-1} ref={ref}>{state.commentNotice}</p>
    {['refresh', 'unknown', 'completed'].includes(state.commentOutcome) && <button disabled={state.busy || state.loadingList || state.loadingComments} onClick={() => void session.refreshComments()}>詳細とコメントを確認</button>}
  </div> : null
}
export function CommentsView({ state, session }: { state: ViewState; session: Session }) {
  const error = useRef<HTMLParagraphElement>(null)
  useEffect(() => { if (state.commentReadError) error.current?.focus() }, [state.commentReadError])
  const { comments, detail } = state
  if (!detail) return null
  const completed = detail.status === 'completed'
  const blocked = state.busy || state.loadingList || completed || !['idle', 'success'].includes(state.commentOutcome)
  return <section className="comments" aria-labelledby="comments-heading">
    <h2 id="comments-heading">コメント</h2>
    <p className="subtle">投稿日時の古い順・1ページ20件</p>
    <div aria-busy={state.loadingComments}>
      {state.loadingComments && <p role="status">コメントを読み込んでいます…</p>}
      {state.commentReadError && <p role="alert" tabIndex={-1} ref={error}>{state.commentReadError}</p>}
      <button disabled={state.busy || state.loadingComments || state.loadingList} onClick={() => void session.loadComments()}>コメントを再読込</button>
      {comments && <>
        <p role="status">コメント {comments.meta.total}件 · {state.commentPage} / {comments.meta.last_page}ページ</p>
        {!comments.data.length && <p>{comments.meta.total === 0 ? 'コメントはまだありません。' : 'このページにコメントはありません。'}</p>}
        <ol className="comment-list">{comments.data.map(comment => <li key={comment.id}>
          <div className="comment-heading"><strong>{comment.author.display_name}</strong><time dateTime={comment.created_at}>{dates.format(new Date(comment.created_at))}（JST）</time></div>
          <p className="comment-body">{comment.body}</p>
        </li>)}</ol>
        <nav className="pagination" aria-label="コメントのページ移動">
          <button disabled={state.busy || state.commentPage <= 1} onClick={() => void session.loadComments(state.commentPage - 1)}>コメントの前へ</button>
          <span>{state.commentPage}ページ</span>
          <button disabled={state.busy || state.commentPage >= comments.meta.last_page} onClick={() => void session.loadComments(state.commentPage + 1)}>コメントの次へ</button>
          {state.commentPage > comments.meta.last_page && <button disabled={state.busy} onClick={() => void session.loadComments(1)}>コメントの先頭へ</button>}
        </nav>
      </>}
    </div>
    {completed && <p>完了した依頼へのコメント投稿はできません。</p>}
    {(!completed || state.commentDraft !== '') && <form noValidate aria-busy={state.busy} onSubmit={event => { event.preventDefault(); void session.postComment() }}>
      <label htmlFor="comment-body">{completed ? '未送信のコメント下書き' : 'コメント本文'} <span className="required">必須</span></label>
      <textarea id="comment-body" rows={5} value={state.commentDraft} disabled={blocked} onChange={event => session.editComment(event.target.value)} aria-invalid={!!state.commentError} aria-describedby="comment-help comment-error" />
      <small id="comment-help">1～2000文字。前後の空白を除去し、改行を統一します。日本語・絵文字はコードポイントで数えます。正規化後 {Array.from(normalizeText(state.commentDraft)).length}文字。下書きはこの画面のメモリーだけに保持します。</small>
      <span className="field-error" id="comment-error">{state.commentError}</span>
      <div className="actions">
        {!completed && <button className="primary" disabled={blocked}>{state.commentOutcome === 'sending' ? '投稿中…' : 'コメントを投稿'}</button>}
        {state.commentDraft !== '' && <button type="button" disabled={state.busy} onClick={() => session.discardComment()}>コメント下書きを破棄</button>}
      </div>
    </form>}
  </section>
}
