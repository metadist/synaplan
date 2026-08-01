import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useHistoryStore } from '@/stores/history'

// `loadMessages` short-circuits on a failed auth check before issuing
// the API call. The tests below exercise the response-parsing branch,
// so we keep the auth gate green via the in-memory authService used
// by the store.
vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
  },
}))

describe('History Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('should initialize with empty messages', () => {
    const store = useHistoryStore()
    expect(store.messages).toEqual([])
  })

  it('should add a message', () => {
    const store = useHistoryStore()

    store.addMessage('user', [{ type: 'text', content: 'Hello' }])

    expect(store.messages).toHaveLength(1)
    expect(store.messages[0].role).toBe('user')
    expect(store.messages[0].parts[0].content).toBe('Hello')
  })

  it('should clear all messages', () => {
    const store = useHistoryStore()
    store.addMessage('user', [{ type: 'text', content: 'Hello' }])
    store.addMessage('assistant', [{ type: 'text', content: 'Hi' }])

    store.clear()
    expect(store.messages).toEqual([])
  })

  it('should add streaming message', () => {
    const store = useHistoryStore()

    const id = store.addStreamingMessage('assistant', 'openai', 'GPT-4')

    expect(store.messages).toHaveLength(1)
    expect(store.messages[0].isStreaming).toBe(true)
    expect(store.messages[0].id).toBe(id)
  })

  it('should update streaming message', () => {
    const store = useHistoryStore()
    const id = store.addStreamingMessage('assistant')

    store.updateStreamingMessage(id, 'Hello')

    expect(store.messages[0].parts[0].content).toBe('Hello')
    expect(store.messages[0].isStreaming).toBe(true)
  })

  it('should finish streaming message', () => {
    const store = useHistoryStore()
    const id = store.addStreamingMessage('assistant')

    store.finishStreamingMessage(id)

    expect(store.messages[0].isStreaming).toBe(false)
  })

  // #1058: measured thinking duration from thinkingStartedAt
  it('finishStreamingMessage sets thinkingTime from thinkingStartedAt', () => {
    const store = useHistoryStore()
    const id = store.addStreamingMessage('assistant')
    store.messages[0].parts = [
      {
        type: 'thinking',
        content: 'step by step…',
        isStreaming: true,
        thinkingStartedAt: Date.now() - 4500,
      },
    ]

    store.finishStreamingMessage(id)

    const thinking = store.messages[0].parts.find((p) => p.type === 'thinking')!
    expect(thinking.isStreaming).toBeUndefined()
    expect(thinking.thinkingStartedAt).toBeUndefined()
    expect(thinking.thinkingTime).toBeGreaterThanOrEqual(4)
    expect(thinking.thinkingTime).toBeLessThanOrEqual(6)
  })

  it('should mark message as superseded', () => {
    const store = useHistoryStore()
    store.addMessage('assistant', [{ type: 'text', content: 'Old response' }])
    const messageId = store.messages[0].id

    store.markSuperseded(messageId)

    expect(store.messages[0].isSuperseded).toBe(true)
  })

  /**
   * Issue #955 regression coverage. The chat API ships uploaded voice
   * notes and TTS audio in two shapes:
   *
   *  - legacy single attachment `m.file = { path, type }` where `type`
   *    is either the generic media kind (`audio`) or the raw file
   *    extension (`ogg`, `mp3`, …) for inbound WhatsApp messages.
   *  - modern `m.files[]` collection where each entry carries the file
   *    extension on `fileType`.
   *
   * `loadMessages` must surface both as `audio` parts so the message
   * renders a `MessageAudio` player instead of a plain text bubble.
   */
  describe('loadMessages — audio rendering (issue #955)', () => {
    const mockChatApi = (messages: unknown[]) => {
      return vi.fn().mockResolvedValue({
        success: true,
        messages,
        pagination: { hasMore: false },
      })
    }

    const stubChatApi = async (getChatMessages: ReturnType<typeof vi.fn>) => {
      vi.doMock('@/services/api', () => ({
        chatApi: { getChatMessages },
      }))
    }

    it('renders an audio player for a legacy file.type === "ogg" message', async () => {
      vi.resetModules()
      await stubChatApi(
        mockChatApi([
          {
            id: 1,
            direction: 'IN',
            text: 'Hei, test.',
            timestamp: 1700000000,
            // Inbound WhatsApp voice notes carry a relative upload path
            // (the same shape the backend exposes via files[].file_path).
            // The store must prefix it with the static-serve endpoint so
            // the <audio> element can fetch the recording.
            file: { path: '13/000/voice.ogg', type: 'ogg' },
          },
        ])
      )

      const { useHistoryStore: useStore } = await import('@/stores/history')
      const store = useStore()

      await store.loadMessages(42)

      expect(store.messages).toHaveLength(1)
      const audioParts = store.messages[0].parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(1)
      expect(audioParts[0].url).toContain('voice.ogg')
      expect(audioParts[0].url).toContain('/api/v1/files/uploads/')
    })

    it('renders an audio player for a generic file.type === "audio" message', async () => {
      vi.resetModules()
      await stubChatApi(
        mockChatApi([
          {
            id: 2,
            direction: 'OUT',
            text: 'AI reply',
            timestamp: 1700000010,
            file: { path: '13/000/tts_reply.mp3', type: 'audio' },
          },
        ])
      )

      const { useHistoryStore: useStore } = await import('@/stores/history')
      const store = useStore()

      await store.loadMessages(42)

      const audioParts = store.messages[0].parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(1)
      expect(audioParts[0].url).toContain('tts_reply.mp3')
    })

    it('renders an audio player for files[] attachments with an audio extension', async () => {
      vi.resetModules()
      await stubChatApi(
        mockChatApi([
          {
            id: 3,
            direction: 'IN',
            text: 'Voice note transcript',
            timestamp: 1700000020,
            files: [
              {
                id: 99,
                filename: 'voice.ogg',
                fileType: 'ogg',
                filePath: '13/000/uploaded_voice.ogg',
                fileSize: 1024,
              },
            ],
          },
        ])
      )

      const { useHistoryStore: useStore } = await import('@/stores/history')
      const store = useStore()

      await store.loadMessages(42)

      const audioParts = store.messages[0].parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(1)
      expect(audioParts[0].url).toContain('uploaded_voice.ogg')
      expect(audioParts[0].url).toContain('/api/v1/files/uploads/')
      // The download badge stays available alongside the player.
      expect(store.messages[0].files).toHaveLength(1)
      expect(store.messages[0].files![0].filename).toBe('voice.ogg')
    })

    it('does not add an audio part for non-audio file attachments', async () => {
      vi.resetModules()
      await stubChatApi(
        mockChatApi([
          {
            id: 4,
            direction: 'IN',
            text: 'See attached PDF',
            timestamp: 1700000030,
            files: [
              {
                id: 100,
                filename: 'report.pdf',
                fileType: 'pdf',
                filePath: '13/000/report.pdf',
              },
            ],
          },
        ])
      )

      const { useHistoryStore: useStore } = await import('@/stores/history')
      const store = useStore()

      await store.loadMessages(42)

      const audioParts = store.messages[0].parts.filter((p) => p.type === 'audio')
      expect(audioParts).toHaveLength(0)
    })
  })

  it('polls an in-progress turn until the persisted assistant reply replaces it (#1343)', async () => {
    vi.useFakeTimers()
    vi.resetModules()

    const getChatMessages = vi
      .fn()
      .mockResolvedValueOnce({
        success: true,
        messages: [{ id: 1, direction: 'IN', text: 'Do two tasks', timestamp: 1700000000 }],
        pagination: { hasMore: false },
        inProgressTurn: {
          reply_node: 'n2',
          cards: [
            {
              nodeId: 'n1',
              capability: 'chat',
              kind: 'text',
              state: 'done',
              text: 'First task result',
            },
            { nodeId: 'n2', capability: 'chat', kind: 'text', state: 'running' },
          ],
        },
      })
      .mockResolvedValueOnce({
        success: true,
        messages: [
          { id: 1, direction: 'IN', text: 'Do two tasks', timestamp: 1700000000 },
          {
            id: 2,
            direction: 'OUT',
            text: 'Both tasks are complete',
            timestamp: 1700000010,
          },
        ],
        pagination: { hasMore: false },
      })

    vi.doMock('@/services/api', () => ({
      chatApi: { getChatMessages },
    }))

    try {
      const { useHistoryStore: useStore } = await import('@/stores/history')
      const store = useStore()

      await store.loadMessages(42)

      expect(store.messages.at(-1)?.id).toBe('in-progress-turn')
      expect(store.messages.at(-1)?.taskPlan?.cards[0].text).toBe('First task result')

      await vi.advanceTimersByTimeAsync(2000)

      expect(getChatMessages).toHaveBeenCalledTimes(2)
      expect(store.messages.some((message) => message.id === 'in-progress-turn')).toBe(false)
      expect(store.messages.at(-1)?.parts[0].content).toBe('Both tasks are complete')

      await vi.advanceTimersByTimeAsync(4000)
      expect(getChatMessages).toHaveBeenCalledTimes(2)
    } finally {
      vi.useRealTimers()
    }
  })

  it('preserves all loaded pages when polling an in-progress turn', async () => {
    vi.useFakeTimers()
    vi.resetModules()
    vi.doUnmock('@/services/api')

    const rows = (start: number, count: number) =>
      Array.from({ length: count }, (_, index) => ({
        id: start + index,
        direction: 'IN',
        text: `Message ${start + index}`,
        timestamp: 1700000000 + start + index,
      }))
    const firstPage = rows(51, 50)
    const olderPage = rows(1, 50)
    const getChatMessages = vi
      .fn()
      .mockResolvedValueOnce({
        success: true,
        messages: firstPage,
        pagination: { hasMore: true },
        inProgressTurn: {
          reply_node: 'n1',
          cards: [{ nodeId: 'n1', capability: 'chat', kind: 'text', state: 'running' }],
        },
      })
      .mockResolvedValueOnce({
        success: true,
        messages: olderPage,
        pagination: { hasMore: false },
      })
      .mockResolvedValueOnce({
        success: true,
        messages: [...olderPage, ...firstPage],
        pagination: { hasMore: false },
      })

    vi.doMock('@/services/api', () => ({
      chatApi: { getChatMessages },
    }))

    try {
      const { useHistoryStore: useStore } = await import('@/stores/history')
      const store = useStore()

      await store.loadMessages(42)
      await store.loadMoreMessages(42)
      await vi.advanceTimersByTimeAsync(2000)

      expect(getChatMessages).toHaveBeenNthCalledWith(3, 42, 0, 100)
      expect(store.messages).toHaveLength(100)
      expect(store.messages.some((message) => message.backendMessageId === 1)).toBe(true)
      expect(store.messages.some((message) => message.backendMessageId === 100)).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('defers an in-progress poll while load-more is still running', async () => {
    vi.useFakeTimers()
    vi.resetModules()
    vi.doUnmock('@/services/api')

    let resolveLoadMore!: (value: unknown) => void
    const loadMoreResponse = new Promise((resolve) => {
      resolveLoadMore = resolve
    })
    const getChatMessages = vi
      .fn()
      .mockResolvedValueOnce({
        success: true,
        messages: [{ id: 2, direction: 'IN', text: 'Newer', timestamp: 1700000002 }],
        pagination: { hasMore: true },
        inProgressTurn: {
          reply_node: 'n1',
          cards: [{ nodeId: 'n1', capability: 'chat', kind: 'text', state: 'running' }],
        },
      })
      .mockReturnValueOnce(loadMoreResponse)
      .mockResolvedValueOnce({
        success: true,
        messages: [
          { id: 1, direction: 'IN', text: 'Older', timestamp: 1700000001 },
          { id: 2, direction: 'IN', text: 'Newer', timestamp: 1700000002 },
        ],
        pagination: { hasMore: false },
      })

    vi.doMock('@/services/api', () => ({
      chatApi: { getChatMessages },
    }))

    try {
      const { useHistoryStore: useStore } = await import('@/stores/history')
      const store = useStore()
      await store.loadMessages(42)

      const loadMore = store.loadMoreMessages(42)
      await vi.advanceTimersByTimeAsync(2000)
      expect(getChatMessages).toHaveBeenCalledTimes(2)

      resolveLoadMore({
        success: true,
        messages: [{ id: 1, direction: 'IN', text: 'Older', timestamp: 1700000001 }],
        pagination: { hasMore: false },
      })
      await loadMore
      expect(store.messages.some((message) => message.backendMessageId === 1)).toBe(true)

      await vi.advanceTimersByTimeAsync(2000)
      expect(getChatMessages).toHaveBeenCalledTimes(3)
      expect(store.messages).toHaveLength(2)
    } finally {
      vi.useRealTimers()
    }
  })

  describe('recoverInterruptedTurn (#1413)', () => {
    it('re-polls the chat until the persisted assistant answer lands', async () => {
      vi.useFakeTimers()
      vi.resetModules()

      const getChatMessages = vi
        .fn()
        // First reload: only the user prompt is persisted, the turn is still running.
        .mockResolvedValueOnce({
          success: true,
          messages: [{ id: 1, direction: 'IN', text: 'Question', timestamp: 1700000000 }],
          pagination: { hasMore: false },
        })
        // Second reload: the assistant answer has now been persisted.
        .mockResolvedValueOnce({
          success: true,
          messages: [
            { id: 1, direction: 'IN', text: 'Question', timestamp: 1700000000 },
            { id: 2, direction: 'OUT', text: 'The answer', timestamp: 1700000010 },
          ],
          pagination: { hasMore: false },
        })

      vi.doMock('@/services/api', () => ({
        chatApi: { getChatMessages },
      }))

      try {
        const { useHistoryStore: useStore } = await import('@/stores/history')
        const store = useStore()

        const recovery = store.recoverInterruptedTurn(42)
        await vi.advanceTimersByTimeAsync(1000)
        await recovery

        expect(getChatMessages).toHaveBeenCalledTimes(2)
        expect(store.messages.at(-1)?.parts[0].content).toBe('The answer')
      } finally {
        vi.useRealTimers()
      }
    })

    it('hands off to the 2s in-progress poll when the turn is still running', async () => {
      vi.useFakeTimers()
      vi.resetModules()

      const getChatMessages = vi.fn().mockResolvedValue({
        success: true,
        messages: [{ id: 1, direction: 'IN', text: 'Question', timestamp: 1700000000 }],
        pagination: { hasMore: false },
        inProgressTurn: {
          reply_node: 'n1',
          cards: [{ nodeId: 'n1', capability: 'chat', kind: 'text', state: 'running' }],
        },
      })

      vi.doMock('@/services/api', () => ({
        chatApi: { getChatMessages },
      }))

      try {
        const { useHistoryStore: useStore } = await import('@/stores/history')
        const store = useStore()

        // A single reload sees `inProgressTurn`, so recovery stops and defers to
        // the existing 2s poll instead of running its own backoff loop.
        await store.recoverInterruptedTurn(42)

        expect(getChatMessages).toHaveBeenCalledTimes(1)
        expect(store.messages.some((m) => m.id === 'in-progress-turn')).toBe(true)
      } finally {
        vi.useRealTimers()
      }
    })
  })
})
