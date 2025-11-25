import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'
const ACTIVE_CHAT_STORAGE_KEY = 'synaplan_active_chat_id'

// Helper function to check authentication and redirect if needed
function checkAuthOrRedirect(): boolean {
  const token = localStorage.getItem('auth_token')
  if (!token) {
    console.warn('🔒 No auth token found - redirecting to login')
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

  const normalizeChat = (chat: any): Chat => ({
    ...chat,
    widgetSession: chat.widgetSession ?? null
  } as Chat)

  const activeChat = computed(() => {
    return chats.value.find(c => c.id === activeChatId.value) || null
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
    if (candidateId && chats.value.some(chat => chat.id === candidateId)) {
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
      const token = localStorage.getItem('auth_token')!

      const response = await fetch(`${API_BASE_URL}/api/v1/chats`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      })

      if (!response.ok) {
        throw new Error('Failed to load chats')
      }

      const data = await response.json()
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
      const token = localStorage.getItem('auth_token')!

      const response = await fetch(`${API_BASE_URL}/api/v1/chats`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ title })
      })

      if (!response.ok) {
        throw new Error('Failed to create chat')
      }

      const data = await response.json()
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

  async function updateChatTitle(chatId: number, title: string) {
    if (!checkAuthOrRedirect()) return
    
    try {
      const token = localStorage.getItem('auth_token')!

      const response = await fetch(`${API_BASE_URL}/api/v1/chats/${chatId}`, {
        method: 'PATCH',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ title })
      })

      if (!response.ok) {
        throw new Error('Failed to update chat title')
      }

      const chat = chats.value.find(c => c.id === chatId)
      if (chat) {
        chat.title = title
      }
    } catch (err: any) {
      error.value = err.message || 'Failed to update chat'
      console.error('Error updating chat:', err)
    }
  }

  async function deleteChat(chatId: number) {
    if (!checkAuthOrRedirect()) return
    
    try {
      const token = localStorage.getItem('auth_token')!

      const response = await fetch(`${API_BASE_URL}/api/v1/chats/${chatId}`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      })

      if (!response.ok) {
        throw new Error('Failed to delete chat')
      }

      chats.value = chats.value.filter(c => c.id !== chatId)
      
      // Select another chat if the deleted one was active
      if (activeChatId.value === chatId) {
        updateActiveChatSelection(chats.value.length > 0 ? chats.value[0].id : null)
      }
    } catch (err: any) {
      error.value = err.message || 'Failed to delete chat'
      console.error('Error deleting chat:', err)
    }
  }

  async function shareChat(chatId: number, enable: boolean = true) {
    if (!checkAuthOrRedirect()) return null
    
    try {
      const token = localStorage.getItem('auth_token')!

      const response = await fetch(`${API_BASE_URL}/api/v1/chats/${chatId}/share`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ enable })
      })

      if (!response.ok) {
        throw new Error('Failed to share chat')
      }

      const data = await response.json()
      
      // Update chat in store
      const chat = chats.value.find(c => c.id === chatId)
      if (chat) {
        chat.isShared = data.isShared
      }
      
      return {
        success: data.success,
        shareToken: data.shareToken,
        isShared: data.isShared,
        shareUrl: data.shareUrl
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
      const token = localStorage.getItem('auth_token')!

      const response = await fetch(`${API_BASE_URL}/api/v1/chats/${chatId}`, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      })

      if (!response.ok) {
        throw new Error('Failed to get share info')
      }

      const data = await response.json()
      return {
        isShared: data.chat.isShared || false,
        shareToken: data.chat.shareToken || null,
        shareUrl: data.chat.shareToken 
          ? `${API_BASE_URL}/shared/${data.chat.shareToken}`
          : null
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
    updateChatTitle,
    deleteChat,
    shareChat,
    getShareInfo,
    setActiveChat,
    $reset
  }
})

