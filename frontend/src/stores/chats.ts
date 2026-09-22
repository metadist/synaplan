import { defineStore } from 'pinia'
import { useNotification } from '@/composables/useNotification'
import { i18n } from '@/i18n/instance'
import { ref, computed, watch } from 'vue'
import { httpClient } from '@/services/api/httpClient'
import { GetApiChatsListResponseSchema } from '@/generated/api-schemas'
import { useIncognitoStore } from '@/stores/incognito'
import { useHistoryStore } from '@/stores/history'
import { useIncomingStore } from '@/stores/incoming'
import { isIamSharingEnabled } from '@/composables/useIamFeature'
import { authService } from '@/services/authService'
import { hasSessionHint } from '@/services/sessionHint'
import { isSessionTerminating } from '@/services/sessionTeardown'
import { chatGoneStatus } from '@/utils/chatAccessError'
import { getErrorMessage } from '@/utils/errorMessage'
import { buildChatShareUrl } from '@/utils/urlHelper'

const ACTIVE_CHAT_STORAGE_KEY = 'synaplan_active_chat_id'

/** Page size for the mobile history drawer's infinite scroll. */
const HISTORY_PAGE_SIZE = 20

// Helper function to check authentication and redirect if needed
// Uses authService which holds user info in memory (not localStorage).
// The redirect reason is only `session_expired` when this browser has a prior
// successful login (session hint) — a never-logged-in guest gets the neutral
// `auth_required`, so they never see the misleading "session expired" message.
function checkAuthOrRedirect(): boolean {
  // A logout in progress owns the next navigation: redirecting here would
  // cancel it (see sessionTeardown). Bail out silently instead.
  if (isSessionTerminating()) return false

  if (!authService.isAuthenticated()) {
    console.warn('🔒 Not authenticated - redirecting to login')
    const reason = hasSessionHint() ? 'session_expired' : 'auth_required'
    window.location.href = `/login?reason=${reason}`
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
  /** IAM grants on an owned chat. A public link stays on isShared. */
  shareSummary?: {
    everyone: boolean
    people: number
    groups: string[]
  }
  source?: 'web' | 'whatsapp' | 'email' | 'widget' | 'api'
  widgetSession?: WidgetSessionInfo | null
  firstMessagePreview?: string | null
  access?: 'owner' | 'read' | 'use'
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

export type ConversationSharedVia = { type: 'user' | 'group' | 'everyone'; name: string }

export type ConversationSource = {
  owner: { id: number; name: string } | null
  sharedVia: ConversationSharedVia | null
}

export const useChatsStore = defineStore('chats', () => {
  const chats = ref<Chat[]>([])
  const activeChatId = ref<number | null>(readActiveChatId())
  const conversationAccess = ref<'owner' | 'read' | 'use' | null>(null)
  const conversationSource = ref<ConversationSource | null>(null)
  let conversationAccessSeq = 0
  const loading = ref(false)
  const error = ref<string | null>(null)
  /**
   * Bumped at the start of every `loadChats()`, on `$reset`, and after any
   * local list mutation a previously started GET cannot know about (rename /
   * delete / generated title). A response whose generation no longer matches
   * is dropped so it cannot replace a title the user just saved, resurrect a
   * chat they just deleted, or leak the previous user's list after logout.
   * The counter stays monotonic — resetting it to 0 would let a pre-logout
   * `seq === 1` match the next user's first load.
   *
   * Creates and activity bumps are merged into the applied snapshot instead
   * of bumping this counter: discarding the boot GET because the user clicked
   * New Chat mid-load would drop every other chat. Only ids recorded in
   * `locallyCreatedIds` are kept when the snapshot omits them — otherwise a
   * chat deleted on another device would come back on every refresh.
   */
  let chatsLoadSeq = 0
  /** Ids `createChat()` added that a snapshot started beforehand cannot list. */
  const locallyCreatedIds = new Set<number>()
  /**
   * Live generating marks, keyed by chat id → `chatsLoadSeq` at mark time.
   * Applied only to loads that were already in flight (`epoch >= loadSeq`) so
   * a later snapshot can turn the marker off after the user walked away.
   */
  const liveGeneratingEpoch = new Map<number, number>()
  /** Live clears, same generation rule as `liveGeneratingEpoch`. */
  const liveClearedEpoch = new Map<number, number>()

  /**
   * Paginated history for the mobile drawer. Kept separate from `chats` so the
   * global list (chat switching, desktop rail, `ensureValidActiveChat`) is not
   * affected by the incremental, page-by-page loading of the drawer.
   */
  const historyChats = ref<Chat[]>([])
  const historyLoading = ref(false)
  const historyHasMore = ref(true)
  const historyOffset = ref(0)

  /**
   * Chats whose answer is still being written on the server. A turn survives
   * the client disconnect it was started from, so this marks the chats a user
   * can return to and keep watching.
   */
  const activeRunChatIds = ref<Set<number>>(new Set())

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

    // An incoming (shared-with-me) chat is not in my own list but is a valid
    // thing to have open. Keep it while the incoming list confirms it.
    if (candidateId && !candidate && useIncomingStore().isOpenable(candidateId)) {
      updateActiveChatSelection(candidateId)
      return
    }

    const firstRegularChat = chats.value.find((chat) => !chat.widgetSession)
    updateActiveChatSelection(firstRegularChat ? firstRegularChat.id : null)
  }

  // Once the incoming list has arrived, an active chat that is neither mine nor
  // shared with me (revoked share, stale storage) falls back like before.
  watch(
    () => useIncomingStore().loaded,
    (incomingLoaded) => {
      if (incomingLoaded && chats.value.length > 0) ensureValidActiveChat()
    }
  )

  function invalidateInFlightChatsLoad() {
    chatsLoadSeq += 1
    loading.value = false
  }

  function mergeLoadedChat(local: Chat | undefined, server: Chat): Chat {
    if (!local) {
      return server
    }
    const localTs = Date.parse(local.updatedAt ?? '') || 0
    const serverTs = Date.parse(server.updatedAt ?? '') || 0
    return {
      ...server,
      updatedAt: localTs > serverTs ? local.updatedAt : server.updatedAt,
      messageCount:
        Math.max(local.messageCount ?? 0, server.messageCount ?? 0) || server.messageCount,
      firstMessagePreview: local.firstMessagePreview ?? server.firstMessagePreview,
    }
  }

  function applyLiveRunOverlay(serverRunIds: number[], loadSeq: number): Set<number> {
    const nextRuns = new Set(serverRunIds)
    for (const [id, epoch] of liveGeneratingEpoch) {
      if (epoch >= loadSeq) {
        nextRuns.add(id)
      } else {
        liveGeneratingEpoch.delete(id)
      }
    }
    for (const [id, epoch] of liveClearedEpoch) {
      if (epoch >= loadSeq) {
        nextRuns.delete(id)
      } else {
        liveClearedEpoch.delete(id)
      }
    }
    return nextRuns
  }

  function applyChatsFromServer(incoming: Chat[], serverRunIds: number[], loadSeq: number) {
    const serverIds = new Set(incoming.map((chat) => chat.id))
    const localById = new Map(chats.value.map((chat) => [chat.id, chat]))
    const merged = incoming.map((server) => mergeLoadedChat(localById.get(server.id), server))
    const createdLocally = chats.value.filter(
      (chat) => locallyCreatedIds.has(chat.id) && !serverIds.has(chat.id)
    )
    for (const id of serverIds) {
      locallyCreatedIds.delete(id)
    }
    chats.value = createdLocally.length > 0 ? [...createdLocally, ...merged] : merged
    activeRunChatIds.value = applyLiveRunOverlay(serverRunIds, loadSeq)
  }

  async function loadChats() {
    if (!checkAuthOrRedirect()) return

    const seq = ++chatsLoadSeq
    loading.value = true
    error.value = null

    try {
      const data = await httpClient<{ chats: unknown[]; activeRunChatIds?: number[] }>(
        '/api/v1/chats'
      )
      if (seq !== chatsLoadSeq) {
        return
      }
      applyChatsFromServer(
        (data.chats || []).map((chat) => normalizeChat(chat)),
        data.activeRunChatIds ?? [],
        seq
      )
      ensureValidActiveChat()
    } catch (err: unknown) {
      if (seq !== chatsLoadSeq) {
        return
      }
      error.value = getErrorMessage(err) || 'Failed to load chats'
      console.error('Error loading chats:', err)
    } finally {
      if (seq === chatsLoadSeq) {
        loading.value = false
      }
    }
  }

  /**
   * Flag a chat as generating (or no longer generating) without waiting for the
   * next chat-list fetch.
   *
   * `loadChats()` is the server's word on this, but it only runs on entry, so on
   * its own the marker would be a snapshot from app start: it would never light
   * up when the user walks away from a running turn, and never go out when that
   * turn finishes. The chat view drives it live from the stream's own
   * `run_started` and terminal events instead.
   */
  function markChatGenerating(chatId: number, generating: boolean) {
    // Replaced rather than mutated: a Set is not deeply reactive, so template
    // reads of activeRunChatIds would not re-render on add/delete alone.
    const next = new Set(activeRunChatIds.value)
    if (generating) {
      liveGeneratingEpoch.set(chatId, chatsLoadSeq)
      liveClearedEpoch.delete(chatId)
      next.add(chatId)
    } else {
      liveClearedEpoch.set(chatId, chatsLoadSeq)
      liveGeneratingEpoch.delete(chatId)
      next.delete(chatId)
    }
    activeRunChatIds.value = next
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
      // `hasMore` is optional in the API contract (older/prod OpenAPI specs omit
      // it, where the generated schema types it as `unknown`). Treat a missing
      // flag as "no more pages" so the type stays boolean across all specs.
      historyHasMore.value = data.hasMore === true
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

      locallyCreatedIds.add(newChat.id)
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

    // Starting a brand-new chat always leaves incognito: the "New Chat" button
    // must land the user in a normal, persisted conversation. We cannot rely on
    // the activeChatId watcher for this — an incognito session usually reuses an
    // empty underlying chat, so the id stays unchanged and the watcher never
    // fires. Ending the session here (fire-and-forget; file cleanup runs in the
    // background) flips the flag so ChatView restores the normal surface.
    const incognitoStore = useIncognitoStore()
    if (incognitoStore.active) {
      void incognitoStore.endSession()
    }

    // Find all empty chats (not widget sessions, no messages, default title).
    // #732: exclude the active chat when local history already has messages
    // (e.g. cancelled stream) — list metadata can still look empty, so reuse
    // would leave the user on the same thread with no visible change.
    const historyStore = useHistoryStore()
    const emptyChats = chats.value.filter((chat) => {
      if (!isChatEmpty(chat)) return false
      if (chat.id === activeChatId.value && historyStore.messages.length > 0) {
        return false
      }
      return true
    })

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

    invalidateInFlightChatsLoad()

    try {
      await httpClient(`/api/v1/chats/${chatId}`, {
        method: 'PATCH',
        body: JSON.stringify({ title }),
      })

      const chat = chats.value.find((c) => c.id === chatId)
      if (chat) {
        chat.title = title
      }
      // A load that started while the PATCH was in flight can have been
      // served the old title. Drop it so it cannot land after this write.
      invalidateInFlightChatsLoad()
    } catch (err: unknown) {
      error.value = getErrorMessage(err) || 'Failed to update chat'
      console.error('Error updating chat:', err)
    }
  }

  /**
   * Reflect a title the server generated for a chat (#1500). Local-only: the
   * value is already persisted, so re-sending it would be a redundant PATCH.
   */
  function applyChatTitle(chatId: number, title: string) {
    invalidateInFlightChatsLoad()
    const chat = chats.value.find((c) => c.id === chatId)
    if (chat) {
      chat.title = title
    }
  }

  async function deleteChat(chatId: number, silent: boolean = false) {
    if (!checkAuthOrRedirect()) return

    invalidateInFlightChatsLoad()

    try {
      await httpClient(`/api/v1/chats/${chatId}`, {
        method: 'DELETE',
      })

      const wasActiveChat = activeChatId.value === chatId
      locallyCreatedIds.delete(chatId)
      chats.value = chats.value.filter((c) => c.id !== chatId)
      invalidateInFlightChatsLoad()

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

  function parseSharedVia(value: unknown): ConversationSharedVia | null {
    if (!value || typeof value !== 'object') return null
    const type = 'type' in value ? String(value.type) : ''
    if (type !== 'user' && type !== 'group' && type !== 'everyone') return null
    const name = 'name' in value && typeof value.name === 'string' ? value.name : ''
    return { type, name }
  }

  function parseOwner(value: unknown): ConversationSource['owner'] {
    if (!value || typeof value !== 'object') return null
    const id = 'id' in value ? Number(value.id) : 0
    const name = 'name' in value && typeof value.name === 'string' ? value.name : ''
    if (!id) return null
    return { id, name }
  }

  /**
   * Settle the access question without asking the server, for the cases where
   * the answer can only be "the viewer's own": nothing is open at all, or the
   * conversation sits in the viewer's own list. Deliberately distinct from the
   * `null` "probe still running" state, which withholds the composer on
   * purpose — leaving an unanswerable question at `null` kept the composer
   * hidden, and on a brand-new account (no chat to open) it never came back.
   */
  function resolveConversationAccessAsOwn() {
    ++conversationAccessSeq
    conversationAccess.value = 'owner'
    conversationSource.value = null
  }

  async function loadConversationAccess(chatId: number) {
    if (!isIamSharingEnabled()) {
      resolveConversationAccessAsOwn()
      return
    }
    // A chat in the viewer's own list can only come back as "owner":
    // conversations other people shared arrive through the incoming store and
    // are never part of this list (see ensureValidActiveChat). Asking anyway
    // unmounted the composer for the length of a foregone request on every
    // single chat switch.
    if (chats.value.some((chat) => chat.id === chatId)) {
      resolveConversationAccessAsOwn()
      return
    }
    const seq = ++conversationAccessSeq
    conversationAccess.value = null
    conversationSource.value = null
    try {
      const data = await httpClient<{
        chat: {
          access?: string
          owner?: { id: number; name: string }
          sharedVia?: ConversationSharedVia | null
        }
      }>(`/api/v1/chats/${chatId}`)
      if (seq !== conversationAccessSeq) {
        return
      }
      const access = data.chat.access
      conversationAccess.value = access === 'read' || access === 'use' ? access : 'owner'
      conversationSource.value = {
        owner: parseOwner(data.chat.owner),
        sharedVia: parseSharedVia(data.chat.sharedVia),
      }
    } catch (err: unknown) {
      if (seq !== conversationAccessSeq) {
        return
      }
      const gone = chatGoneStatus(err)
      if (gone) {
        void releaseUnavailableChat(chatId, gone)
        return
      }
      conversationAccess.value = null
      conversationSource.value = null
    }
  }

  async function getShareInfo(chatId: number) {
    if (!checkAuthOrRedirect()) return null

    try {
      const data = await httpClient<{ chat: Record<string, unknown> }>(`/api/v1/chats/${chatId}`)

      const chat = data.chat
      const shareTok = typeof chat.shareToken === 'string' ? chat.shareToken : null
      const isShared = Boolean(chat.isShared)

      return {
        isShared,
        shareToken: shareTok,
        shareUrl: shareTok ? buildChatShareUrl(shareTok) : null,
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

  /**
   * React to activity that happened outside this browser tab — an inbound
   * message on an inbound channel (WhatsApp, email) pushed over the realtime
   * `user:{id}` channel (#1372).
   *
   * If the chat is already in the local store, bump it so it re-sorts to the
   * top immediately. If it is not (e.g. a brand-new inbound conversation), pull
   * the chat list so the new conversation appears without a manual reload.
   *
   * @param chatId Target chat.
   * @param options.firstMessagePreview Optional preview to lift an "empty" chat
   *   out of the empty-chat filter once it has real content.
   */
  async function noteExternalActivity(
    chatId: number,
    options: { firstMessagePreview?: string } = {}
  ) {
    const chat = chats.value.find((c) => c.id === chatId)
    if (chat) {
      bumpChatActivity(chatId, { firstMessagePreview: options.firstMessagePreview })
    } else {
      await loadChats()
    }

    // Another tab, or a person this chat is shared with, just received a
    // finished turn. Reload the open thread unless this tab is still streaming
    // it itself (#2057).
    if (activeChatId.value === chatId && !useHistoryStore().hasLiveStream()) {
      void useHistoryStore().loadMessages(chatId, 0, 50, true)
    }
  }

  const releasingChats = new Map<number, Promise<'deleted' | 'unshared'>>()

  /**
   * The open conversation answered 404 or 403. Drop the selection, stop the
   * loaders that would keep retrying it, and say what happened (#2061).
   */
  function releaseUnavailableChat(
    chatId: number,
    status: 403 | 404
  ): Promise<'deleted' | 'unshared'> {
    const existing = releasingChats.get(chatId)
    if (existing) return existing

    const job = (async () => {
      const owned = chats.value.some((chat) => chat.id === chatId)
      const reason: 'deleted' | 'unshared' = status === 404 && owned ? 'deleted' : 'unshared'
      chats.value = chats.value.filter((chat) => chat.id !== chatId)
      useIncomingStore().drop(chatId)
      if (activeChatId.value === chatId) {
        useHistoryStore().discardMessages()
        updateActiveChatSelection(null)
      }
      useNotification().info(
        i18n.global.t(reason === 'deleted' ? 'chat.goneDeleted' : 'chat.goneUnshared')
      )
      await Promise.all([loadChats(), useIncomingStore().load()])
      if (activeChatId.value === null) {
        ensureValidActiveChat()
      }
      return reason
    })().finally(() => {
      releasingChats.delete(chatId)
    })

    releasingChats.set(chatId, job)
    return job
  }

  function $reset() {
    chats.value = []
    conversationAccess.value = null
    conversationSource.value = null
    conversationAccessSeq += 1
    invalidateInFlightChatsLoad()
    locallyCreatedIds.clear()
    liveGeneratingEpoch.clear()
    liveClearedEpoch.clear()
    activeRunChatIds.value = new Set()
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
    conversationAccess,
    conversationSource,
    loadConversationAccess,
    resolveConversationAccessAsOwn,
    activeChat,
    loading,
    error,
    historyChats,
    historyLoading,
    historyHasMore,
    activeRunChatIds,
    markChatGenerating,
    loadChats,
    loadChatHistory,
    createChat,
    findOrCreateEmptyChat,
    updateChatTitle,
    applyChatTitle,
    deleteChat,
    shareChat,
    getShareInfo,
    setActiveChat,
    bumpChatActivity,
    noteExternalActivity,
    releaseUnavailableChat,
    $reset,
  }
})
