import { describe, expect, it } from 'vitest'
import { normalizeText, validateRequest } from './request-input'

describe('API-aligned request text', () => {
  it.each(['あ', '😀'])('counts %s as one codepoint at each boundary', char => {
    for (const [field, max] of [['title', 100], ['body', 5000]] as const) {
      const input = { title: '題名', body: '内容', category: 'inquiry' as const }
      expect(validateRequest({ ...input, [field]: char.repeat(max) }).fields[field]).toBeUndefined()
      expect(validateRequest({ ...input, [field]: char.repeat(max + 1) }).fields[field]).toBeDefined()
      expect(validateRequest({ ...input, [field]: char }).fields[field]).toBeUndefined()
      expect(validateRequest({ ...input, [field]: '' }).fields[field]).toBeDefined()
    }
  })
  it('matches Unicode trim and LF conversion without removing FEFF', () => {
    expect(normalizeText('\u0085\u3000内容\r\n次\r行\u00a0')).toBe('内容\n次\n行')
    expect(normalizeText('\ufeff内容\ufeff')).toBe('\ufeff内容\ufeff')
    expect(validateRequest({ title: '\n題名', body: '内容', category: 'bug' }).fields.title).toBeDefined()
  })
  it.each(['\u0000', '\ud800', '\udfff', '\u3000\u0085'])('rejects invalid or blank text', text => {
    const result = validateRequest({ title: text, body: text, category: 'improvement' })
    expect(result.fields.title).toBeDefined(); expect(result.fields.body).toBeDefined()
  })
})
