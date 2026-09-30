import type { RequestSummary } from './api'

export type Status = RequestSummary['status']
export const transitions: Record<Status, Status[]> = {
  open: ['in_progress'], in_progress: ['waiting_confirmation'],
  waiting_confirmation: ['in_progress', 'completed'], completed: [],
}
const conflictMessages: Record<string, string> = {
  stale_version: '表示していた版は古くなっています。',
  request_completed: 'この依頼はすでに完了しています。',
  no_change: '同じ担当者または同じ状態への変更はできません。',
  invalid_transition: '現在の状態からその状態へ変更できません。',
  invalid_assignee_state: '担当者の条件を満たしていません。有効な担当者へ変更してください。担当解除は未対応の間だけ可能です。',
}
export function conflictMessage(code: string) {
  return (conflictMessages[code] ?? '現在の依頼の条件では変更できません。') + ' 最新情報を確認して操作を選び直してください。'
}
