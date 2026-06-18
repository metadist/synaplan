import { describe, it, expect } from 'vitest'
import { formatQuoteAsBlockquote } from '@/composables/useMessageQuoting'

describe('formatQuoteAsBlockquote', () => {
  it('prefixes a single line with a Markdown blockquote marker', () => {
    expect(formatQuoteAsBlockquote('hello world')).toBe('> hello world')
  })

  it('prefixes every line of a multi-line excerpt', () => {
    expect(formatQuoteAsBlockquote('line one\nline two')).toBe('> line one\n> line two')
  })

  it('preserves empty lines as blockquote separators', () => {
    expect(formatQuoteAsBlockquote('a\n\nb')).toBe('> a\n> \n> b')
  })
})
