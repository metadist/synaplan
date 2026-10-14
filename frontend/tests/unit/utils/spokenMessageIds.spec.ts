import { describe, expect, it } from 'vitest'
import type { Message } from '@/stores/history'
import {
  claimSpokenMessageId,
  hasUnboundSpoken,
  stampSpokenMessageIds,
} from '@/utils/spokenMessageIds'

function row(overrides: Partial<Message> & Pick<Message, 'id' | 'role'>): Message {
  return {
    parts: [{ type: 'text', content: 'Hello' }],
    timestamp: new Date(0),
    ...overrides,
  }
}

describe('spoken message ids', () => {
  it('attaches the stored id to the matching bubble', () => {
    const messages = [
      row({ id: 'user-1', role: 'user', parts: [{ type: 'text', content: 'Hello' }] }),
      row({ id: 'ai-1', role: 'assistant', parts: [{ type: 'text', content: 'Hi' }] }),
    ]
    claimSpokenMessageId(messages, 'user', 41, 'Hello')
    claimSpokenMessageId(messages, 'assistant', 42, 'Hi')
    expect(messages.map((message) => message.backendMessageId)).toEqual([41, 42])
  })

  it('does not reuse an id that is already on a bubble', () => {
    const messages = [
      row({ id: 'user-1', role: 'user', backendMessageId: 41 }),
      row({ id: 'user-2', role: 'user', parts: [{ type: 'text', content: 'Again' }] }),
    ]
    claimSpokenMessageId(messages, 'user', 41, 'Again')
    expect(messages[1]?.backendMessageId).toBeUndefined()
  })

  it('leaves two open bubbles alone when the text matches neither', () => {
    const messages = [
      row({ id: 'user-1', role: 'user', parts: [{ type: 'text', content: 'One' }] }),
      row({ id: 'user-2', role: 'user', parts: [{ type: 'text', content: 'Two' }] }),
    ]
    claimSpokenMessageId(messages, 'user', 9, 'Three')
    expect(messages.every((message) => message.backendMessageId === undefined)).toBe(true)
  })

  it('copies the saved id onto this call only', () => {
    const local = [
      row({ id: 'older', role: 'user', parts: [{ type: 'text', content: 'Hello' }] }),
      row({ id: 'user-1', role: 'user', parts: [{ type: 'text', content: 'Hello' }] }),
      row({ id: 'ai-1', role: 'assistant', parts: [{ type: 'text', content: 'Hi' }] }),
    ]
    const persisted = [
      row({
        id: 'backend-41',
        role: 'user',
        backendMessageId: 41,
        parts: [{ type: 'text', content: 'Hello' }],
      }),
      row({
        id: 'backend-42',
        role: 'assistant',
        backendMessageId: 42,
        topic: 'general',
        parts: [{ type: 'text', content: 'Hi' }],
        aiModels: { voice: { provider: 'OpenAI', model: 'GPT Live', model_id: 4 } },
      }),
    ]
    stampSpokenMessageIds(local, persisted, new Set(['user-1', 'ai-1']))
    expect(local[0]?.backendMessageId).toBeUndefined()
    expect(local[1]?.backendMessageId).toBe(41)
    expect(local[2]?.backendMessageId).toBe(42)
    expect(local[2]?.topic).toBe('general')
    expect(local[2]?.aiModels?.voice?.model).toBe('GPT Live')
    expect(hasUnboundSpoken(local, new Set(['user-1', 'ai-1']))).toBe(false)
  })
})
