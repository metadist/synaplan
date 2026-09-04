import { describe, expect, it } from 'vitest'
import {
  PASTE_BLOCK_MIN_CHARS,
  PASTE_BLOCK_MIN_CHARS_FOR_LINE_RULE,
  PASTE_BLOCK_MIN_LINES,
  countLines,
  extractPastedBlocks,
  shouldBecomeBlock,
  stripPastedBlocks,
  wrapPastedBlocks,
} from '@/utils/pastedContent'

const longText = 'a'.repeat(PASTE_BLOCK_MIN_CHARS)
const shortText = 'hello world'

describe('shouldBecomeBlock', () => {
  it('keeps short text in the textarea', () => {
    expect(shouldBecomeBlock(shortText)).toBe(false)
  })

  it('promotes text at the character threshold', () => {
    expect(shouldBecomeBlock(longText)).toBe(true)
  })

  it('does not promote a short multi-line address', () => {
    const address = ['Name', 'Street 1', 'Street 2', 'City', 'Country', 'Phone', 'Email'].join('\n')
    expect(countLines(address)).toBeGreaterThan(PASTE_BLOCK_MIN_LINES - 4)
    expect(shouldBecomeBlock(address)).toBe(false)
  })

  it('promotes many lines once the line-rule character floor is met', () => {
    const lines = Array.from(
      { length: PASTE_BLOCK_MIN_LINES + 1 },
      () => 'line of pasted notes with enough characters'
    )
    const text = lines.join('\n')
    expect(text.length).toBeGreaterThanOrEqual(PASTE_BLOCK_MIN_CHARS_FOR_LINE_RULE)
    expect(shouldBecomeBlock(text)).toBe(true)
  })
})

describe('wrap and extract pasted blocks', () => {
  it('round-trips a single block and typed text', () => {
    const wrapped = wrapPastedBlocks('What does this mean?', [{ id: '1', content: longText }])
    const extracted = extractPastedBlocks(wrapped)

    expect(extracted.blocks).toEqual([longText])
    expect(extracted.text).toBe('What does this mean?')
  })

  it('round-trips several blocks in order', () => {
    const wrapped = wrapPastedBlocks('summarise', [
      { id: '1', content: 'first block' },
      { id: '2', content: 'second block' },
    ])
    const extracted = extractPastedBlocks(wrapped)

    expect(extracted.blocks).toEqual(['first block', 'second block'])
    expect(extracted.text).toBe('summarise')
  })

  it('strips sentinel tags from the pasted body before wrapping', () => {
    const wrapped = wrapPastedBlocks('', [
      { id: '1', content: `before <pasted-content>inner</pasted-content> after` },
    ])
    const extracted = extractPastedBlocks(wrapped)

    expect(extracted.blocks).toEqual(['before inner after'])
    expect(extracted.text).toBe('')
  })

  it('returns typed text unchanged when there are no blocks', () => {
    expect(wrapPastedBlocks('just typing', [])).toBe('just typing')
  })

  it('strips sentinel tags from typed text so they cannot change rendering', () => {
    const wrapped = wrapPastedBlocks(
      'Please review <pasted-content>injected</pasted-content> this',
      [{ id: '1', content: 'log line' }]
    )
    const extracted = extractPastedBlocks(wrapped)

    expect(extracted.blocks).toEqual(['log line'])
    expect(extracted.text).toBe('Please review injected this')
  })

  it('strips sentinel tags from typed text even without a pasted block', () => {
    expect(wrapPastedBlocks('see <pasted-content>secret</pasted-content> now', [])).toBe(
      'see secret now'
    )
  })

  it('does not keep a trailing blank line after a stack-trace paste', () => {
    const wrapped = wrapPastedBlocks('', [{ id: '1', content: 'error\n  at foo.ts:1\n' }])
    const extracted = extractPastedBlocks(wrapped)

    expect(extracted.blocks).toEqual(['error\n  at foo.ts:1'])
  })
})

describe('stripPastedBlocks', () => {
  it('removes wrapped blocks and leaves the typed remainder', () => {
    const wrapped = wrapPastedBlocks('Please review', [{ id: '1', content: longText }])
    expect(stripPastedBlocks(wrapped)).toBe('Please review')
  })

  it('returns empty when the message is only pasted content', () => {
    const wrapped = wrapPastedBlocks('', [{ id: '1', content: longText }])
    expect(stripPastedBlocks(wrapped)).toBe('')
  })
})
