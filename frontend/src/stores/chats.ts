import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { httpClient } from '@/services/api/httpClient'
import { useConfigStore } from '@/stores/config'
import { authService } from '@/services/authService'

const ACTIVE_CHAT_STORAGE_KEY = 'synaplan_active_chat_id'

// Helper function to check authentication and redirect if needed
// Uses authService which holds user info in memory (not localStorage)
function checkAuthOrRedirect(): boolean {
  if (!authService.isAuthenticated()) {
    console.warn('🔒 Not authenticated - redirecting to login')
    window.location.href = '/login?reason=session_expired'
    return false
  }
  return true
}

export interface Chat {
  id: number
  title: string
  createdAt: string
  updatedAt: string
  messageCount?: number
  isShared?: boolean
  widgetSession?: WidgetSessionInfo | null
  firstMessagePreview?: string | null
}

export interface WidgetSessionInfo {
  widgetId: string
  widgetName: string | null
  sessionId: string
  messageCount: number
  lastMessage: number | null
  created: number
  expires: number
}

export const useChatsStore = defineStore('chats', () => {
  const chats = ref<Chat[]>([])
  const activeChatId = ref<number | null>(readActiveChatId())
  const loading = ref(false)
  const error = ref<string | null>(null)

  const normalizeChat = (chat: any): Chat =>
    ({
      ...chat,
      widgetSession: chat.widgetSession ?? null,
    }) as Chat

  const activeChat = computed(() => {
    return chats.value.find((c) => c.id === activeChatId.value) || null
  })

  function readActiveChatId(): number | null {
    try {
      const storedId = localStorage.getItem(ACTIVE_CHAT_STORAGE_KEY)
      if (!storedId) {
        return null
      }

      const parsed = Number(storedId)
      return Number.isFinite(parsed) ? parsed : null
    } catch (error) {
      console.warn('Unable to read active chat from storage', error)
      return null
    }
  }

  function persistActiveChatId(chatId: number | null) {
    try {
      if (chatId === null) {
        localStorage.removeItem(ACTIVE_CHAT_STORAGE_KEY)
      } else {
        localStorage.setItem(ACTIVE_CHAT_STORAGE_KEY, String(chatId))
      }
    } catch (error) {
      console.warn('Unable to persist active chat to storage', error)
    }
  }

  function updateActiveChatSelection(chatId: number | null) {
    activeChatId.value = chatId
    persistActiveChatId(chatId)
  }

  function ensureValidActiveChat() {
    const candidateId = activeChatId.value ?? readActiveChatId()
    if (candidateId && chats.value.some((chat) => chat.id === candidateId)) {
      updateActiveChatSelection(candidateId)
      return
    }

    if (chats.value.length > 0) {
      updateActiveChatSelection(chats.value[0].id)
    } else {
      updateActiveChatSelection(null)
    }
  }

  async function loadChats() {
    if (!checkAuthOrRedirect()) return

    loading.value = true
    error.value = null

    try {
      const data = await httpClient<{ chats: any[] }>('/api/v1/chats')
      chats.value = (data.chats || []).map((chat: any) => normalizeChat(chat))
      ensureValidActiveChat()
    } catch (err: any) {
      error.value = err.message || 'Failed to load chats'
      console.error('Error loading chats:', err)
    } finally {
      loading.value = false
    }
  }

  async function createChat(title?: string): Promise<Chat | null> {
    if (!checkAuthOrRedirect()) return null

    loading.value = true
    error.value = null

    try {
      const data = await httpClient<{ success: boolean; chat: any }>('/api/v1/chats', {
        method: 'POST',
        body: JSON.stringify({ title }),
      })

      const newChat = normalizeChat(data.chat)

      chats.value.unshift(newChat)
      updateActiveChatSelection(newChat.id)

      return newChat
    } catch (err: any) {
      error.value = err.message || 'Failed to create chat'
      console.error('Error creating chat:', err)
      return null
    } finally {
      loading.value = false
    }
  }

  /**
   * Find an existing empty chat or create a new one.
   * Prevents creating multiple empty chats unnecessarily.
   */
  async function findOrCreateEmptyChat(): Promise<Chat | null> {
    if (!checkAuthOrRedirect()) return null

    // Look for an existing empty chat (no messages, default title)
    const emptyChat = chats.value.find(
      (chat) =>
        !chat.widgetSession &&
        (chat.messageCount === 0 || chat.messageCount === undefined) &&
        (chat.title === 'New Chat' || chat.title === 'Neuer Chat' || chat.title.startsWith('Chat '))
    )

    if (emptyChat) {
      // Found an empty chat - just switch to it
      console.log('♻️ Reusing existing empty chat:', emptyChat.id)
      updateActiveChatSelection(emptyChat.id)
      return emptyChat
    }

    // No empty chat found - create a new one
    return await createChat()
  }

  async function updateChatTitle(chatId: number, title: string) {
    if (!checkAuthOrRedirect()) return

    try {
      await httpClient(`/api/v1/chats/${chatId}`, {
        method: 'PATCH',
        body: JSON.stringify({ title }),
      })

      const chat = chats.value.find((c) => c.id === chatId)
      if (chat) {
        chat.title = title
      }
    } catch (err: any) {
      error.value = err.message || 'Failed to update chat'
      console.error('Error updating chat:', err)
    }
  }

  async function deleteChat(chatId: number, silent: boolean = false) {
    if (!checkAuthOrRedirect()) return

    try {
      await httpClient(`/api/v1/chats/${chatId}`, {
        method: 'DELETE',
      })

      const wasActiveChat = activeChatId.value === chatId
      chats.value = chats.value.filter((c) => c.id !== chatId)

      // If the deleted chat was active and it was the last chat, create a new one
      if (wasActiveChat && chats.value.length === 0) {
        await createChat()
      } else if (wasActiveChat && chats.value.length > 0) {
        // Select another chat if the deleted one was active
        updateActiveChatSelection(chats.value[0].id)
      }
    } catch (err: any) {
      if (!silent) {
        error.value = err.message || 'Failed to delete chat'
        console.error('Error deleting chat:', err)
      }
    }
  }

  async function shareChat(chatId: number, enable: boolean = true) {
    if (!checkAuthOrRedirect()) return null

    try {
      const data = await httpClient<{
        success: boolean
        shareToken: string
        isShared: boolean
        shareUrl: string
      }>(`/api/v1/chats/${chatId}/share`, {
        method: 'POST',
        body: JSON.stringify({ enable }),
      })

      // Update chat in store
      const chat = chats.value.find((c) => c.id === chatId)
      if (chat) {
        chat.isShared = data.isShared
      }

      return {
        success: data.success,
        shareToken: data.shareToken,
        isShared: data.isShared,
        shareUrl: data.shareUrl,
      }
    } catch (err: any) {
      error.value = err.message || 'Failed to share chat'
      console.error('Error sharing chat:', err)
      throw err
    }
  }

  async function getShareInfo(chatId: number) {
    if (!checkAuthOrRedirect()) return null

    try {
      const data = await httpClient<{ chat: any }>(`/api/v1/chats/${chatId}`)

      // Import config store for building share URL
      const config = useConfigStore()

      return {
        isShared: data.chat.isShared || false,
        shareToken: data.chat.shareToken || null,
        shareUrl: data.chat.shareToken
          ? `${config.appBaseUrl}/shared/${data.chat.shareToken}`
          : null,
      }
    } catch (err: any) {
      error.value = err.message || 'Failed to get share info'
      console.error('Error getting share info:', err)
      throw err
    }
  }

  function setActiveChat(chatId: number) {
    updateActiveChatSelection(chatId)
  }

  function $reset() {
    chats.value = []
    updateActiveChatSelection(null)
    loading.value = false
    error.value = null
  }

  return {
    chats,
    activeChatId,
    activeChat,
    loading,
    error,
    loadChats,
    createChat,
    findOrCreateEmptyChat,
    updateChatTitle,
    deleteChat,
    shareChat,
    getShareInfo,
    setActiveChat,
    $reset,
  }
})
