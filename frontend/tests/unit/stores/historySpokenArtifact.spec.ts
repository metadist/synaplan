import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useHistoryStore } from '@/stores/history'

/**
 * A picture made during a live call shows up in the chat as the same media
 * message a reload shows: after the spoken question, before the sentence the
 * voice says about it, and never twice.
 */

const getMessage = vi.hoisted(() => vi.fn())

vi.mock('@/services/api', () => ({ chatApi: { getMessage } }))

vi.mock('@/services/authService', () => ({
  authService: { isAuthenticated: () => true },
}))

const storedRow = {
  id: 55,
  direction: 'OUT',
  text: '__IMAGE_GENERATED__',
  timestamp: 1_700_000_000,
  topic: 'mediamaker',
  file: { path: '/api/v1/files/uploads/1/fox.png', type: 'image' },
}

describe('history.insertSpokenArtifact', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    getMessage.mockReset()
  })

  it('puts the stored picture right after the spoken question', async () => {
    getMessage.mockResolvedValue({ success: true, message: storedRow })
    const store = useHistoryStore()
    store.addMessage('user', [{ type: 'text', content: 'Draw a fox' }])
    const spoken = store.addStreamingMessage('assistant')

    await store.insertSpokenArtifact({ type: 'image', state: 'completed', messageId: 55 })

    expect(getMessage).toHaveBeenCalledWith(55)
    expect(store.messages.map((message) => message.role)).toEqual([
      'user',
      'assistant',
      'assistant',
    ])
    expect(store.messages[1]?.backendMessageId).toBe(55)
    expect(store.messages[1]?.parts.some((part) => part.type === 'image')).toBe(true)
    expect(store.messages[2]?.id).toBe(spoken)
  })

  it('shows a stored picture once even when the frame arrives twice', async () => {
    getMessage.mockResolvedValue({ success: true, message: storedRow })
    const store = useHistoryStore()
    store.addMessage('user', [{ type: 'text', content: 'Draw a fox' }])

    await store.insertSpokenArtifact({ type: 'image', state: 'completed', messageId: 55 })
    await store.insertSpokenArtifact({ type: 'image', state: 'completed', messageId: 55 })

    expect(store.messages.filter((message) => message.backendMessageId === 55)).toHaveLength(1)
  })

  it('shows an incognito picture from the frame without asking the server', async () => {
    const store = useHistoryStore()

    await store.insertSpokenArtifact({
      type: 'image',
      state: 'completed',
      row: { ...storedRow, id: 0 },
    })

    expect(getMessage).not.toHaveBeenCalled()
    expect(store.messages).toHaveLength(1)
    expect(store.messages[0]?.backendMessageId).toBeUndefined()
    expect(store.messages[0]?.parts.some((part) => part.type === 'image')).toBe(true)
  })

  it('keeps the spoken reply details off the picture', async () => {
    getMessage.mockResolvedValue({ success: true, message: storedRow })
    const store = useHistoryStore()
    store.addMessage('user', [{ type: 'text', content: 'Draw a fox' }])
    await store.insertSpokenArtifact({ type: 'image', state: 'completed', messageId: 55 })

    store.attachSpokenReply({ topic: 'general' })

    const picture = store.messages.find((message) => message.backendMessageId === 55)
    expect(picture?.topic).toBe('mediamaker')
  })
})
