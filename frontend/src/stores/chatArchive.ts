import { defineStore } from 'pinia'
import { ref, type Ref } from 'vue'
import { httpClient } from '@/services/api/httpClient'
import { GetApiChatsListResponseSchema } from '@/generated/api-schemas'
import { CHAT_HISTORY_PAGE } from '@/components/sidebar/chatHistoryPaging'
import { authService } from '@/services/authService'
import { hasSessionHint } from '@/services/sessionHint'
import { isSessionTerminating } from '@/services/sessionTeardown'
import type { Chat } from '@/stores/chats'

/**
 * Archived chats only. The open-chat store keeps today's thread and the
 * sidebar rail; this list is what the Archived button shows.
 */
function checkAuthOrRedirect(): boolean {
  if (isSessionTerminating()) return false
  if (!authService.isAuthenticated()) {
    const reason = hasSessionHint() ? 'session_expired' : 'auth_required'
    window.location.href = `/login?reason=${reason}`
    return false
  }
  return true
}

function normalizeChat(chat: unknown): Chat {
  const row = chat as Chat
  return {
    ...row,
    widgetSession: row.widgetSession ?? null,
    pinned: row.pinned === true,
    pinnedAt: typeof row.pinnedAt === 'string' ? row.pinnedAt : null,
    archived: true,
    tags: Array.isArray(row.tags) ? row.tags.filter((tag) => typeof tag === 'string') : [],
  }
}

interface ArchivedPageState {
  chats: Ref<Chat[]>
  loading: Ref<boolean>
  loadFailed: Ref<boolean>
  hasMore: Ref<boolean>
  offset: Ref<number>
  seq: () => number
  takeSeq: () => number
}

async function loadArchivedPage(reset: boolean, state: ArchivedPageState): Promise<void> {
  if (!checkAuthOrRedirect()) return
  if (!reset && (state.loading.value || !state.hasMore.value)) return

  const seq = state.takeSeq()
  const nextOffset = reset ? 0 : state.offset.value
  state.loading.value = true
  if (reset) state.loadFailed.value = false

  try {
    const data = await httpClient(
      `/api/v1/chats?archived=1&limit=${CHAT_HISTORY_PAGE}&offset=${nextOffset}`,
      { schema: GetApiChatsListResponseSchema }
    )
    if (seq !== state.seq()) return
    const received = data.chats ?? []
    const page = received.map((chat) => normalizeChat(chat))
    if (reset) {
      state.chats.value = page
    } else {
      const seen = new Set(state.chats.value.map((chat) => chat.id))
      state.chats.value = [...state.chats.value, ...page.filter((chat) => !seen.has(chat.id))]
    }
    state.offset.value = nextOffset + received.length
    state.hasMore.value = data.hasMore === true
    state.loadFailed.value = false
  } catch (err: unknown) {
    if (seq !== state.seq()) return
    state.loadFailed.value = true
    if (reset) {
      state.chats.value = []
      state.offset.value = 0
      state.hasMore.value = true
    }
    console.error('Error loading archived chats:', err)
  } finally {
    if (seq === state.seq()) state.loading.value = false
  }
}

export const useChatArchiveStore = defineStore('chatArchive', () => {
  const viewingArchived = ref(false)
  const chats = ref<Chat[]>([])
  const loading = ref(false)
  const loadFailed = ref(false)
  const hasMore = ref(true)
  const offset = ref(0)
  /**
   * Monotonic so a response started before logout cannot fill the next
   * person's archive. Reset increments it; it is never set back to 0.
   */
  let loadSeq = 0

  function isOpenable(chatId: number): boolean {
    return chats.value.some((chat) => chat.id === chatId)
  }

  function prependArchived(chat: Chat) {
    const next = normalizeChat({ ...chat, archived: true })
    chats.value = [next, ...chats.value.filter((row) => row.id !== chat.id)]
  }

  function removeArchived(chatId: number) {
    if (!chats.value.some((chat) => chat.id === chatId)) return
    chats.value = chats.value.filter((chat) => chat.id !== chatId)
    offset.value = Math.max(0, offset.value - 1)
  }

  function patchArchived(chatId: number, patch: Partial<Chat>) {
    const chat = chats.value.find((row) => row.id === chatId)
    if (!chat) return
    Object.assign(chat, patch)
  }

  function showOpenChats() {
    viewingArchived.value = false
  }

  async function loadArchivedChats(reset = false) {
    await loadArchivedPage(reset, {
      chats,
      loading,
      loadFailed,
      hasMore,
      offset,
      seq: () => loadSeq,
      takeSeq: () => ++loadSeq,
    })
  }

  async function toggleViewingArchived() {
    if (viewingArchived.value) {
      showOpenChats()
      return
    }
    viewingArchived.value = true
    await loadArchivedChats(true)
  }

  function $reset() {
    loadSeq += 1
    viewingArchived.value = false
    chats.value = []
    loading.value = false
    loadFailed.value = false
    hasMore.value = true
    offset.value = 0
  }

  return {
    viewingArchived,
    chats,
    loading,
    loadFailed,
    hasMore,
    offset,
    isOpenable,
    prependArchived,
    removeArchived,
    patchArchived,
    showOpenChats,
    loadArchivedChats,
    toggleViewingArchived,
    $reset,
  }
})

/** Drop one row from a paged list and keep the next page from skipping a chat. */
export function forgetPagedChat(list: Ref<Chat[]>, offset: Ref<number>, chatId: number): void {
  if (!list.value.some((chat) => chat.id === chatId)) return
  list.value = list.value.filter((chat) => chat.id !== chatId)
  offset.value = Math.max(0, offset.value - 1)
}

export interface ArchiveChangeDeps {
  chats: Ref<Chat[]>
  railChats: Ref<Chat[]>
  historyChats: Ref<Chat[]>
  historyOffset: Ref<number>
  activeChatId: Ref<number | null>
  locallyCreatedIds: Set<number>
  forgetRailChat: (chatId: number) => void
  updateActiveChatSelection: (chatId: number | null) => void
  createChat: () => Promise<unknown>
  loadChats: () => Promise<void>
  loadRailChats: (reset?: boolean) => Promise<void>
}

/**
 * Archive or restore one chat. The open-chat list and the active thread stay
 * put except when the chat being archived is the one on screen: then the next
 * open chat (never a widget session) takes over.
 */
export async function applyChatArchived(
  chatId: number,
  archived: boolean,
  deps: ArchiveChangeDeps
): Promise<boolean> {
  if (!checkAuthOrRedirect()) return false
  const source =
    deps.chats.value.find((row) => row.id === chatId) ??
    deps.railChats.value.find((row) => row.id === chatId) ??
    deps.historyChats.value.find((row) => row.id === chatId)
  await httpClient(`/api/v1/chats/${chatId}/${archived ? 'archive' : 'unarchive'}`, {
    method: 'POST',
  })
  const archive = useChatArchiveStore()
  if (archived) {
    if (source) archive.prependArchived({ ...source, archived: true })
    deps.chats.value = deps.chats.value.filter((row) => row.id !== chatId)
    deps.forgetRailChat(chatId)
    forgetPagedChat(deps.historyChats, deps.historyOffset, chatId)
    deps.locallyCreatedIds.delete(chatId)
    if (deps.activeChatId.value === chatId) {
      const next = deps.chats.value.find((chat) => !chat.widgetSession)
      if (next) deps.updateActiveChatSelection(next.id)
      else {
        try {
          await deps.createChat()
        } catch (err: unknown) {
          console.error('Opening a fresh chat after archive failed', err)
        }
      }
    }
    return true
  }
  archive.removeArchived(chatId)
  try {
    await Promise.all([deps.loadChats(), deps.loadRailChats(true)])
  } catch (err: unknown) {
    console.error('Reloading open chats after unarchive failed', err)
  }
  return true
}
