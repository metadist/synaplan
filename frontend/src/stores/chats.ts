import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { httpClient } from '@/services/api/httpClient'
import { GetApiChatsListResponseSchema } from '@/generated/api-schemas'
import { useConfigStore } from '@/stores/config'
import { authService } from '@/services/authService'
import { getErrorMessage } from '@/utils/errorMessage'

const ACTIVE_CHAT_STORAGE_KEY = 'synaplan_active_chat_id'

/** Page size for the mobile history drawer's infinite scroll. */
const HISTORY_PAGE_SIZE = 20

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
  source?: 'web' | 'whatsapp' | 'email' | 'widget'
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

export function isDefaultChatTitle(title: string, localizedNewChat?: string): boolean {
  return (
    title === 'New Chat' ||
    title === 'Neuer Chat' ||
    title.startsWith('Chat ') ||
    (localizedNewChat !== undefined && title === localizedNewChat)
  )
}

export const useChatsStore = defineStore('chats', () => {
  const chats = ref<Chat[]>([])
  const activeChatId = ref<number | null>(readActiveChatId())
  const loading = ref(false)
  const error = ref<string | null>(null)

  /**
   * Paginated history for the mobile drawer. Kept separate from `chats` so the
   * global list (chat switching, desktop rail, `ensureValidActiveChat`) is not
   * affected by the incremental, page-by-page loading of the drawer.
   */
  const historyChats = ref<Chat[]>([])
  const historyLoading = ref(false)
  const historyHasMore = ref(true)
  const historyOffset = ref(0)

  const normalizeChat = (chat: unknown): Chat => {
    const c = chat as Chat
    return {
      ...c,
      widgetSession: c.widgetSession ?? null,
    }
  }

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
    const candidate = candidateId ? chats.value.find((chat) => chat.id === candidateId) : undefined

    // Never auto-restore a widget session as the active chat in the main
    // ChatView. Widget sessions belong to their dedicated widget session view;
    // restoring one here renders out-of-context system messages and the chat
    // is hidden in the collapsed "Widget Chats" sidebar section (#1152).
    if (candidate && !candidate.widgetSession) {
      updateActiveChatSelection(candidate.id)
      return
    }

    const firstRegularChat = chats.value.find((chat) => !chat.widgetSession)
    updateActiveChatSelection(firstRegularChat ? firstRegularChat.id : null)
  }

  async function loadChats() {
    if (!checkAuthOrRedirect()) return

    loading.value = true
    error.value = null

    try {
      const data = await httpClient<{ chats: unknown[] }>('/api/v1/chats')
      chats.value = (data.chats || []).map((chat) => normalizeChat(chat))
      ensureValidActiveChat()
    } catch (err: unknown) {
      error.value = getErrorMessage(err) || 'Failed to load chats'
      console.error('Error loading chats:', err)
    } finally {
      loading.value = false
    }
  }

  /**
   * Load one page of the paginated chat history for the mobile drawer.
   *
   * @param reset When true, start over at offset 0 and replace the list
   *   (e.g. when the drawer opens or after a mutation). Otherwise append the
   *   next page for infinite scroll. No-op while a page is in flight, or when
   *   there is nothing more to load (unless resetting).
   */
  async function loadChatHistory(reset = false) {
    if (!checkAuthOrRedirect()) return
    if (historyLoading.value) return
    if (!reset && !historyHasMore.value) return

    const offset = reset ? 0 : historyOffset.value
    historyLoading.value = true

    try {
      const data = await httpClient(`/api/v1/chats?limit=${HISTORY_PAGE_SIZE}&offset=${offset}`, {
        schema: GetApiChatsListResponseSchema,
      })
      const page = (data.chats ?? []).map((chat) => normalizeChat(chat))

      if (reset) {
        historyChats.value = page
      } else {
        // Dedup by id so an item that shifted pages (a chat updated between
        // requests) is never rendered twice.
        const seen = new Set(historyChats.value.map((c) => c.id))
        historyChats.value = [...historyChats.value, ...page.filter((c) => !seen.has(c.id))]
      }

      historyOffset.value = offset + page.length
      historyHasMore.value = data.hasMore
    } catch (err: unknown) {
      console.error('Error loading chat history:', err)
    } finally {
      historyLoading.value = false
    }
  }

  async function createChat(title?: string): Promise<Chat | null> {
    if (!checkAuthOrRedirect()) return null

    loading.value = true
    error.value = null

    // Selection as of the moment the request is fired. If the user (or a
    // faster concurrent createChat) switches the active chat while this
    // request is still in flight, the late response must NOT steal the
    // selection — switching views mid-stream aborts a running answer.
    const selectionAtRequest = activeChatId.value

    try {
      const data = await httpClient<{ success: boolean; chat: unknown }>('/api/v1/chats', {
        method: 'POST',
        body: JSON.stringify({ title }),
      })

      const newChat = normalizeChat(data.chat)

      chats.value.unshift(newChat)
      if (activeChatId.value === selectionAtRequest) {
        updateActiveChatSelection(newChat.id)
      }

      return newChat
    } catch (err: unknown) {
      error.value = getErrorMessage(err) || 'Failed to create chat'
      console.error('Error creating chat:', err)
      return null
    } finally {
      loading.value = false
    }
  }

  /**
   * Check if a chat is truly empty (no messages, no content).
   */
  function isChatEmpty(chat: Chat): boolean {
    // Widget sessions are never considered empty for reuse
    if (chat.widgetSession) return false

    // Has messages - not empty
    if (chat.messageCount && chat.messageCount > 0) return false

    // Has first message preview - not empty
    if (chat.firstMessagePreview) return false

    if (!isDefaultChatTitle(chat.title)) return false

    return true
  }

  /**
   * Find an existing empty chat or create a new one.
   * Prevents creating multiple empty chats unnecessarily.
   * Also cleans up stale empty chats to prevent accumulation.
   */
  async function findOrCreateEmptyChat(): Promise<Chat | null> {
    if (!checkAuthOrRedirect()) return null

    // Find all empty chats (not widget sessions, no messages, default title)
    const emptyChats = chats.value.filter((chat) => isChatEmpty(chat))

    if (emptyChats.length > 0) {
      // Use the first (most recent) empty chat
      const chatToReuse = emptyChats[0]
      console.log('♻️ Reusing existing empty chat:', chatToReuse.id)

      // Clean up extra empty chats in the background (keep only the one we're using)
      if (emptyChats.length > 1) {
        console.log(`🧹 Cleaning up ${emptyChats.length - 1} stale empty chat(s)`)
        for (let i = 1; i < emptyChats.length; i++) {
          // Delete silently to avoid UI noise
          deleteChat(emptyChats[i].id, true).catch((err) => {
            console.warn('Failed to clean up stale empty chat:', err)
          })
        }
      }

      updateActiveChatSelection(chatToReuse.id)
      return chatToReuse
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
    } catch (err: unknown) {
      error.value = getErrorMessage(err) || 'Failed to update chat'
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
    } catch (err: unknown) {
      if (!silent) {
        error.value = getErrorMessage(err) || 'Failed to delete chat'
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
    } catch (err: unknown) {
      error.value = getErrorMessage(err) || 'Failed to share chat'
      console.error('Error sharing chat:', err)
      throw err
    }
  }

  async function getShareInfo(chatId: number) {
    if (!checkAuthOrRedirect()) return null

    try {
      const data = await httpClient<{ chat: Record<string, unknown> }>(`/api/v1/chats/${chatId}`)

      // Import config store for building share URL
      const config = useConfigStore()

      const chat = data.chat
      const shareTok = typeof chat.shareToken === 'string' ? chat.shareToken : null
      const isShared = Boolean(chat.isShared)

      return {
        isShared,
        shareToken: shareTok,
        shareUrl: shareTok ? `${config.appBaseUrl}/shared/${shareTok}` : null,
      }
    } catch (err: unknown) {
      error.value = getErrorMessage(err) || 'Failed to get share info'
      console.error('Error getting share info:', err)
      throw err
    }
  }

  function setActiveChat(chatId: number) {
    updateActiveChatSelection(chatId)
  }

  /**
   * Mark a chat as recently active so it re-sorts to the top of sidebar lists.
   *
   * The history sheet (`SidebarV2`) orders chats by `updatedAt DESC` so
   * the most recently active conversation is always at the top. The backend
   * keeps `updatedAt` in sync, but local in-memory chats only see that change
   * after a full reload. Whenever a new message lands on a chat (web SSE,
   * WhatsApp, email, widget), call this to bump the local chat so the UI
   * reflects activity immediately without a round-trip to the server.
   *
   * @param chatId Target chat. No-op if the chat is not in the local store.
   * @param options.incrementMessageCount Whether to add 1 to `messageCount`.
   *   Useful when the caller knows a new message was just appended; pass
   *   `false` if the count is being managed elsewhere.
   * @param options.firstMessagePreview Optional first message preview to set
   *   if the chat does not have one yet (used to lift "empty" chats out of
   *   the empty-chat filter once they have real content).
   */
  function bumpChatActivity(
    chatId: number,
    options: { incrementMessageCount?: boolean; firstMessagePreview?: string } = {}
  ) {
    const chat = chats.value.find((c) => c.id === chatId)
    if (!chat) return

    chat.updatedAt = new Date().toISOString()

    if (options.incrementMessageCount ?? true) {
      chat.messageCount = (chat.messageCount ?? 0) + 1
    }

    if (options.firstMessagePreview && !chat.firstMessagePreview) {
      chat.firstMessagePreview = options.firstMessagePreview
    }
  }

  function $reset() {
    chats.value = []
    historyChats.value = []
    historyOffset.value = 0
    historyHasMore.value = true
    historyLoading.value = false
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
    historyChats,
    historyLoading,
    historyHasMore,
    loadChats,
    loadChatHistory,
    createChat,
    findOrCreateEmptyChat,
    updateChatTitle,
    deleteChat,
    shareChat,
    getShareInfo,
    setActiveChat,
    bumpChatActivity,
    $reset,
  }
})
