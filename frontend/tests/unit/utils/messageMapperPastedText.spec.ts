import { describe, expect, it } from 'vitest'
import { parseContentWithThinking } from '@/utils/messageMapper'
import { wrapPastedBlocks } from '@/utils/pastedContent'

describe('parseContentWithThinking pasted text', () => {
  it('splits user messages into pastedText parts plus remaining text', () => {
    const raw = wrapPastedBlocks('What is this error?', [
      { id: '1', content: 'stack trace line 1\nstack trace line 2' },
    ])

    const parts = parseContentWithThinking(raw, 'user')

    expect(parts).toEqual([
      { type: 'pastedText', content: 'stack trace line 1\nstack trace line 2' },
      { type: 'text', content: 'What is this error?' },
    ])
  })

  it('keeps a plain user message as a single text part', () => {
    expect(parseContentWithThinking('hello', 'user')).toEqual([{ type: 'text', content: 'hello' }])
  })
})
