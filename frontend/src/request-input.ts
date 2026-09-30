import type { Fields, RequestInput } from './api'

// Match RequestText.php (PCRE Unicode whitespace), not JS trim's extra U+FEFF.
export function normalizeText(value: string): string {
  return value.replace(/\r\n?/g, '\n').replace(/^[\p{Z}\t\n\v\f\r\u0085]+|[\p{Z}\t\n\v\f\r\u0085]+$/gu, '')
}
export const blankDraft = (): RequestInput => ({ title: '', category: 'inquiry', body: '' })
export function validateRequest(input: RequestInput): { value: RequestInput; fields: Fields } {
  const value = { ...input, title: normalizeText(input.title), body: normalizeText(input.body) }
  const fields: Fields = {}
  const validText = (text: string, max: number) => {
    const length = Array.from(text).length
    return length >= 1 && length <= max && !text.includes('\0') && !/[\uD800-\uDFFF]/u.test(text)
  }
  if (/[\r\n]/.test(input.title) || !validText(value.title, 100)) fields.title = ['タイトルは改行なしの1～100文字で入力してください。']
  if (!validText(value.body, 5000)) fields.body = ['内容は1～5000文字で入力してください。']
  if (!['inquiry', 'bug', 'improvement'].includes(input.category)) fields.category = ['種別を選択してください。']
  return { value, fields }
}
