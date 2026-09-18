import { describe, expect, it, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

import { useConversationFiles } from '@/composables/useConversationFiles'
import { chatApi } from '@/services/api/chatApi'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore, type Message } from '@/stores/history'

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    getConversationFiles: vi.fn(),
  },
}))

vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => true,
    getUser: () => ({ value: { id: 1, email: 'demo@synaplan.com', level: 'NEW' } }),
    getImpersonator: () => ({ value: null }),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(),
}))

const apiFile = {
  id: 88,
  reference: 'file:88',
  name: 'generated.docx',
  category: 'document' as const,
  origin: 'generated' as const,
  fileType: 'docx',
  messageId: 200,
  hasText: true,
}

describe('useConversationFiles', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.mocked(chatApi.getConversationFiles).mockReset()
    vi.mocked(chatApi.getConversationFiles).mockResolvedValue({ success: true, files: [] })

    const auth = useAuthStore()
    auth.user = { id: 1, email: 'demo@synaplan.com', level: 'NEW' }
  })

  it('lists snake_case files from loaded history messages', async () => {
    const history = useHistoryStore()
    history.messages.push({
      id: 'm1',
      role: 'user',
      parts: [{ type: 'text', content: 'see this' }],
      timestamp: new Date(),
      backend_message_id: 100,
      files: [
        {
          file_id: 77,
          filename: 'contract.pdf',
          file_type: 'pdf',
        },
      ],
    } as unknown as Message)

    const { files } = useConversationFiles()
    await nextTick()

    expect(files.value).toEqual([
      {
        id: 77,
        reference: 'file:77',
        name: 'contract.pdf',
        category: 'document',
        origin: 'uploaded',
        fileType: 'pdf',
        messageId: 100,
        hasText: false,
      },
    ])
  })

  it('refreshes the API catalog when a streaming turn finishes', async () => {
    const chats = useChatsStore()
    chats.setActiveChat(7)
    const history = useHistoryStore()
    const { files } = useConversationFiles()
    await nextTick()
    await Promise.resolve()

    expect(chatApi.getConversationFiles).toHaveBeenCalledTimes(1)
    expect(files.value).toEqual([])

    vi.mocked(chatApi.getConversationFiles).mockResolvedValueOnce({
      success: true,
      files: [apiFile],
    })

    const streamingId = history.addStreamingMessage('assistant')
    await nextTick()
    history.finishStreamingMessage(streamingId)
    await nextTick()
    await Promise.resolve()

    expect(chatApi.getConversationFiles).toHaveBeenCalledTimes(2)
    expect(files.value.map((file) => file.name)).toContain('generated.docx')
  })
})
