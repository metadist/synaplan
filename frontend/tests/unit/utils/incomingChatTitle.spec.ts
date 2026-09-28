import { describe, expect, it } from 'vitest'
import { incomingChatTitle } from '@/utils/incomingChatTitle'

describe('incomingChatTitle', () => {
  it('keeps a message preview that starts with a hash', () => {
    expect(incomingChatTitle('#2146 is still open', 'New Chat')).toBe('#2146 is still open')
  })

  it('replaces a raw id and an empty title with the new-chat label', () => {
    expect(incomingChatTitle('#12', 'New Chat')).toBe('New Chat')
    expect(incomingChatTitle('  ', 'New Chat')).toBe('New Chat')
    expect(incomingChatTitle('New Chat', 'New Chat')).toBe('New Chat')
  })
})
