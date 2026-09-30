import type { Session, ViewState } from './session'
import { transitions } from './workflow'
import type { Status } from './workflow'

const labels: Record<Status, string> = { open: '未対応', in_progress: '対応中', waiting_confirmation: '確認待ち', completed: '完了' }
export function WorkflowControls({ state, session }: { state: ViewState; session: Session }) {
  const row = state.detail
  if (state.user?.role !== 'it_staff' || !row || row.status === 'completed') return null
  const disabled = state.busy || state.loadingList || state.loadingCandidates || !state.workflowReady
  const candidates = state.candidates
  return <section className="workflow" aria-labelledby="workflow-heading" aria-busy={state.busy || state.loadingCandidates}>
    <h2 id="workflow-heading">担当・状態の変更</h2>
    <p className="subtle">担当外の依頼も操作できます。変更にはサーバーで確認した最新の版を使用します。</p>
    {!state.workflowReady && !state.loadingCandidates && !state.loadingList && <p>最新情報の確認が完了するまで更新できません。</p>}
    <button type="button" disabled={state.busy || state.loadingList || state.loadingCandidates} onClick={() => void session.loadDetail()}>最新情報を確認</button>
    <form onSubmit={event => { event.preventDefault(); void session.changeAssignee() }}>
      <fieldset disabled={disabled}>
        <legend>担当者を選択</legend>
        <p id="candidate-help">候補は有効なIT担当者です。このページに現在の担当者がいなくても、停止済みとは限りません。</p>
        <p className="selection" aria-live="polite">選択中：{state.selectedAssignee ? `${state.selectedAssignee.display_name}（#${state.selectedAssignee.id}）` : '未選択'}</p>
        {state.loadingCandidates && <p role="status">担当候補を読み込んでいます…</p>}
        {candidates && <>
          <div className="candidate-list">{candidates.data.map(candidate => <label key={candidate.id} className="radio-label">
            <input type="radio" name="assignee" value={candidate.id} checked={state.selectedAssignee?.id === candidate.id} onChange={() => session.selectAssignee(candidate.id)} aria-describedby="candidate-help" />
            <span>{candidate.display_name}（#{candidate.id}）</span>
          </label>)}</div>
          {!candidates.data.length && <p>このページに担当候補はありません。</p>}
          <p>候補 {candidates.meta.total}件 · {state.candidatePage} / {candidates.meta.last_page}ページ（20件ずつ）</p>
          <nav className="pagination" aria-label="担当候補のページ移動">
            <button type="button" disabled={disabled || state.candidatePage <= 1} onClick={() => void session.loadCandidates(state.candidatePage - 1)}>候補の前へ</button>
            <button type="button" disabled={disabled || state.candidatePage >= candidates.meta.last_page} onClick={() => void session.loadCandidates(state.candidatePage + 1)}>候補の次へ</button>
          </nav>
        </>}
        <div className="actions"><button className="primary" disabled={disabled || !state.selectedAssignee}>担当を変更</button>
          {row.status === 'open' && <button type="button" disabled={disabled || !row.assignee} onClick={() => void session.changeAssignee(true)}>担当を解除</button>}</div>
      </fieldset>
    </form>
    <form onSubmit={event => { event.preventDefault(); void session.changeStatus() }}>
      <fieldset disabled={disabled}>
        <legend>状態を変更</legend>
        {!row.assignee && <p id="status-help">担当者が未割当のため対応を開始できません。先に有効な担当者を割り当ててください。</p>}
        <label htmlFor="next-status">変更先の状態</label>
        <select id="next-status" value={state.selectedStatus} disabled={disabled || !row.assignee} aria-describedby={!row.assignee ? 'status-help' : undefined} onChange={event => session.selectStatus(event.target.value as Status | '')}>
          <option value="">選択してください</option>
          {transitions[row.status].map(status => <option key={status} value={status}>{labels[status]}</option>)}
        </select>
        {state.selectedStatus === 'completed' && <label className="radio-label completion-confirm">
          <input type="checkbox" checked={state.completionConfirmed} onChange={event => session.confirmCompletion(event.target.checked)} />
          <span>完了後は担当・状態を変更できず、再開できないことを確認しました。</span>
        </label>}
        <div className="actions"><button className="primary" disabled={disabled || !row.assignee || !state.selectedStatus || (state.selectedStatus === 'completed' && !state.completionConfirmed)}>{state.selectedStatus === 'completed' ? '完了にする' : '状態を変更'}</button></div>
      </fieldset>
    </form>
    {state.busy && <p role="status">変更を確認しています…</p>}
  </section>
}
