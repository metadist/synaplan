import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AgainData } from '@/types/ai-models'
import type { ApiInProgressTurn, ApiLoadedMessageRow } from '@/utils/messageMapper'
import {
  mapApiMessageRow,
  mapInProgressTurn,
  parseContentWithThinking,
  reconcileLocalMessage,
} from '@/utils/messageMapper'
import { authService } from '@/services/authService'
import { hasSessionHint } from '@/services/sessionHint'
import type { MessageUsage } from '@/stores/usageTaximeter'

// Re-export so existing consumers keep importing from the store module.
// The implementation moved to utils/messageMapper.ts (issue #1070) so the
// reload path and the post-stream reconciliation share one mapping.
export { parseContentWithThinking }

// Helper function to check authentication and redirect if needed
// Uses authService which holds user info in memory (not localStorage)
function checkAuthOrRedirect(): boolean {
  if (!authService.isAuthenticated()) {
    console.warn('🔒 Not authenticated - redirecting to login')
    // Only genuine expired sessions (prior login on this browser) get the
    // "session expired" message; never-logged-in guests get `auth_required`.
    const reason = hasSessionHint() ? 'session_expired' : 'auth_required'
    window.location.href = `/login?reason=${reason}`
    return false
  }
  return true
}

export type PartType =
  | 'text'
  | 'image'
  | 'video'
  | 'audio'
  | 'code'
  | 'links'
  | 'docs'
  | 'screenshot'
  | 'translation'
  | 'link'
  | 'commandList'
  | 'thinking'
  | 'tts_loading'

export interface Part {
  /**
   * Phase 3a: stable id assigned when the part is first created so the
   * ChatMessage `<MessagePart v-for>` can use it as `:key` instead of the
   * array index. With index-based keys, mid-stream parser splits (text →
   * text+code) reused the wrong DOM nodes for ~1 frame, which read as a
   * visible flash. partId stays attached to the part across re-parses.
   */
  partId?: string
  type: PartType
  content?: string
  url?: string
  imageUrl?: string
  alt?: string
  poster?: string
  language?: string
  filename?: string
  title?: string
  items?: Array<{ title: string; url: string; desc?: string; host?: string }>
  matches?: Array<{ filename: string; snippet: string }>
  lang?: string
  result?: string
  expiresAt?: string
  thinkingTime?: number // Time in seconds for thinking process
  isStreaming?: boolean // For reasoning parts that are still being streamed
  autoplay?: boolean // Auto-play audio (voice reply)
}

export interface MessageFile {
  id: number
  filename: string
  fileType: string
  filePath: string
  fileSize?: number
  fileMime?: string
}

/** Background async media render (Release 4.0 — video detach). */
export interface MediaJobInfo {
  jobId: string
  type: string
  state: string
  error?: string
  percent?: number
  elapsedSeconds?: number
  maxWaitSeconds?: number
  remainingSeconds?: number
  /** Poll returned 404 — job snapshot expired from Redis. */
  lost?: boolean
  /** Backend hint: job has been queued too long, worker likely down. */
  stalled?: boolean
  /** i18n key (e.g. queue_worker_down) for the stall reason. */
  stallReason?: string
}

export interface Message {
  id: string
  role: 'user' | 'assistant'
  parts: Part[]
  timestamp: Date
  isSuperseded?: boolean
  isStreaming?: boolean
  truncated?: boolean
  provider?: string
  modelLabel?: string
  topic?: string // Topic from message classification (e.g., 'general', 'mediamaker')
  originalTopic?: string | null // Original classification topic preserved on error messages
  /** BMEDIA subtype from sorting when topic is mediamaker (persisted on failed generation) */
  originalMediaType?: string | null
  againData?: AgainData
  originalMessageId?: number
  backendMessageId?: number
  /** Text the user quoted from an earlier message when composing this one. */
  quotedText?: string | null
  /** Backend id of the message the quote was taken from. */
  quotedMessageId?: number | null
  files?: MessageFile[] // Attached files
  // Status for failed/pending messages
  status?: 'sent' | 'failed' | 'rate_limited'
  errorType?: 'rate_limit' | 'connection' | 'unknown'
  errorData?: {
    limitType?: string
    actionType?: string
    used?: number
    limit?: number
    remaining?: number
    resetAt?: number | null
    userLevel?: string
  }
  searchResults?: Array<{
    title: string
    url: string
    description?: string
    published?: string
    source?: string
    thumbnail?: string
  }> | null // Web search results
  aiModels?: {
    chat?: {
      provider: string
      model: string
      model_id: number | null
    }
    sorting?: {
      provider: string
      model: string
      model_id: number | null
    }
    // Audio (TTS) model used for voice replies. Sent independently
    // from `chat` because the LLM authors the text and a separate TTS
    // pipeline (e.g. Piper) synthesises it — see issue #583.
    audio?: {
      provider: string
      model: string
      model_id: number | null
    }
  } | null // AI model metadata
  webSearch?: {
    enabled?: boolean
    query?: string
    resultsCount?: number
  } | null // Web search metadata
  tool?: {
    command?: string
    icon: string
    label: string
  } | null // Tool metadata (e.g., web search, file generation)
  memoryIds?: number[] | null // IDs of memories used (resolved from memoriesStore)
  feedbackIds?: number[] | null // IDs of feedbacks used (resolved from feedbackStore)
  processingStatus?: string
  processingMetadata?: Record<string, unknown> | null
  // Multitask routing: live task-card state while a multi-node plan streams.
  // Only set when a `plan` SSE event arrives (multi-node turns). On reload the
  // turn is flattened (text + media parts), so this is a streaming-time affordance.
  taskPlan?: TaskPlanState | null
  /** Background media job — video/image render continues after the stream ends. */
  mediaJob?: MediaJobInfo | null
  // Multitask routing: true when this assistant turn ran the DAG executor.
  // Persisted server-side (`multitask` meta), so it survives reloads — used to
  // show the simple "Again" (full re-plan) instead of "Again with…".
  wasMultitask?: boolean
  /**
   * Per-message token/cost usage for the taximeter (badge + session store).
   * Present on assistant messages that recorded usage; null/absent otherwise.
   */
  usage?: MessageUsage | null
  /** Auxiliary usage of the turn (sorting/routing call, media renders, TTS). */
  usageExtra?: MessageUsage[] | null
}

export const TASK_CARD_KINDS = [
  'text',
  'image',
  'video',
  'audio',
  'document',
  'search',
  'extract',
  'email',
] as const
export type TaskCardKind = (typeof TASK_CARD_KINDS)[number]

export const TASK_CARD_STATES = [
  'pending',
  'running',
  'done',
  'failed',
  'skipped',
  'cancelled',
] as const
export type TaskCardState = (typeof TASK_CARD_STATES)[number]

/** Runtime guards for values arriving over SSE — never trust the wire. */
export function isTaskCardKind(value: unknown): value is TaskCardKind {
  return typeof value === 'string' && (TASK_CARD_KINDS as readonly string[]).includes(value)
}

export function isTaskCardState(value: unknown): value is TaskCardState {
  return typeof value === 'string' && (TASK_CARD_STATES as readonly string[]).includes(value)
}

export interface TaskCard {
  nodeId: string
  capability: string
  kind: TaskCardKind
  state: TaskCardState
  text?: string
  url?: string
  mediaType?: string
  // Failure details from the `task_update` SSE event (failed/skipped states).
  error?: string
  // Resolved generation prompt of a failed media node — payload for the
  // "retry this step with the next model" action.
  prompt?: string
  // Web search card compact summary — populated by WebSearchRunner/DagExecutor
  // so the card shows "Searched the web · N sources" instead of the raw dump.
  query?: string
  resultsCount?: number
  // Live media-generation progress from the `task_progress` SSE event: a 0-100
  // estimate, the provider's coarse status, and elapsed seconds. Drive a moving
  // bar instead of a static spinner while a video renders.
  progressPercent?: number
  providerStatus?: string
  elapsedSeconds?: number
  /** Async media job key when the node detached to a background worker. */
  jobId?: string
  /**
   * #1229 smart collapse: the card's prose is already contained in the final
   * answer body, so the card collapses to its header (set by ResultAssembler
   * at assembly time and by markRedundantTaskPlanProse client-side).
   */
  redundant?: boolean
}

export interface TaskPlanState {
  active: boolean
  replyNode: string
  cards: TaskCard[]
  // Streaming turn id (Date.now()) captured when the plan starts. The per-card
  // Stop button needs it to call /cancel-node; reading it from the plan is more
  // reliable than the mutable module-level currentTrackId, which can be cleared
  // by a racing complete/error handler (issue #1141).
  trackId?: number
}

export const useHistoryStore = defineStore('history', () => {
  const messages = ref<Message[]>([])
  const isLoadingMessages = ref(false)
  const hasMoreMessages = ref(false)
  const currentOffset = ref(0)

  // Monotonic generation counter: incremented each time loadMessages is called
  // for a fresh chat (offset === 0). Responses from older generations are
  // discarded so a slow response for a previous chat never overwrites the
  // messages of the current one.
  let loadGeneration = 0

  const addMessage = (
    role: 'user' | 'assistant',
    parts: Part[],
    files?: MessageFile[],
    provider?: string,
    modelLabel?: string,
    againData?: AgainData,
    backendMessageId?: number,
    originalMessageId?: number,
    webSearch?: { enabled?: boolean; query?: string; resultsCount?: number } | null,
    tool?: { command: string; label: string; icon: string } | null,
    quotedText?: string | null,
    quotedMessageId?: number | null
  ) => {
    messages.value.push({
      id: crypto.randomUUID(),
      role,
      parts,
      timestamp: new Date(),
      files,
      provider,
      modelLabel,
      againData,
      backendMessageId,
      originalMessageId,
      webSearch,
      tool,
      quotedText,
      quotedMessageId,
    })
  }

  const addStreamingMessage = (
    role: 'user' | 'assistant',
    provider?: string,
    modelLabel?: string,
    againData?: AgainData,
    backendMessageId?: number,
    originalMessageId?: number
  ): string => {
    const id = crypto.randomUUID()
    messages.value.push({
      id,
      role,
      parts: [{ type: 'text', content: '' }],
      timestamp: new Date(),
      isStreaming: true,
      provider,
      modelLabel,
      againData,
      backendMessageId,
      originalMessageId,
    })
    return id
  }

  const updateStreamingMessage = (id: string, content: string) => {
    const message = messages.value.find((m) => m.id === id)
    if (message && message.parts[0]) {
      message.parts[0].content = content
    }
  }

  const finishStreamingMessage = (id: string, parts?: Part[]) => {
    const message = messages.value.find((m) => m.id === id)
    if (message) {
      message.isStreaming = false
      if (parts) {
        message.parts = parts
      }
      // If parts are already set correctly (e.g., during streaming), don't re-parse
      // Only parse if we have a single text part that might contain thinking blocks
      else if (message.parts.length === 1 && message.parts[0].type === 'text') {
        const currentContent = message.parts[0]?.content || ''

        if (currentContent && currentContent.includes('<think>')) {
          message.parts = parseContentWithThinking(currentContent)
        }
      }
    }
  }

  const removeMessage = (id: string) => {
    messages.value = messages.value.filter((m: Message) => m.id !== id)
  }

  const setMessageStatus = (
    id: string,
    status: 'sent' | 'failed' | 'rate_limited',
    errorType?: 'rate_limit' | 'connection' | 'unknown',
    errorData?: Message['errorData']
  ) => {
    const message = messages.value.find((m: Message) => m.id === id)
    if (message) {
      message.status = status
      message.errorType = errorType
      message.errorData = errorData
    }
  }

  const clearMessageError = (id: string) => {
    const message = messages.value.find((m: Message) => m.id === id)
    if (message) {
      message.status = 'sent'
      message.errorType = undefined
      message.errorData = undefined
    }
  }

  const markSuperseded = (id: string) => {
    const message = messages.value.find((m: Message) => m.id === id)
    if (message) {
      message.isSuperseded = true
    }
  }

  const clear = () => {
    messages.value = []
    currentOffset.value = 0
    hasMoreMessages.value = false
  }

  const loadMessages = async (chatId: number, offset = 0, limit = 50) => {
    if (!checkAuthOrRedirect()) return

    // Fresh load (offset 0) bumps the generation so any in-flight response
    // for a *previous* chat is silently discarded when it lands.
    const myGeneration = offset === 0 ? ++loadGeneration : loadGeneration

    isLoadingMessages.value = true

    // Reset pagination state when loading from start (prevents stale state on error)
    if (offset === 0) {
      currentOffset.value = 0
      hasMoreMessages.value = false
    }

    try {
      const { chatApi } = await import('@/services/api')
      const response = (await chatApi.getChatMessages(chatId, offset, limit)) as {
        success?: boolean
        messages?: ApiLoadedMessageRow[]
        pagination?: { hasMore?: boolean }
        inProgressTurn?: ApiInProgressTurn | null
      }

      if (myGeneration !== loadGeneration) return

      if (response.success && response.messages) {
        const loadedMessages: Message[] = response.messages.map(mapApiMessageRow)

        // Issue #1142: append a provisional assistant bubble for a still-running
        // multi-task turn (only sent on the first page) so returning mid-stream
        // shows the running/completed task cards, not just the user prompt.
        if (offset === 0 && response.inProgressTurn) {
          loadedMessages.push(mapInProgressTurn(response.inProgressTurn))
        }

        // If offset is 0, replace messages; otherwise, prepend (for infinite scroll)
        if (offset === 0) {
          messages.value = loadedMessages
        } else {
          messages.value = [...loadedMessages, ...messages.value]
        }

        currentOffset.value = offset + loadedMessages.length
        hasMoreMessages.value = response.pagination?.hasMore || false
      }
    } catch (error) {
      if (myGeneration !== loadGeneration) return
      console.error('Failed to load messages:', error)
    } finally {
      if (myGeneration === loadGeneration) {
        isLoadingMessages.value = false
      }
    }
  }

  const loadMoreMessages = async (chatId: number) => {
    if (isLoadingMessages.value || !hasMoreMessages.value) {
      return
    }
    await loadMessages(chatId, currentOffset.value, 50)
  }

  /**
   * Issue #1070: after SSE `complete`, re-fetch the persisted message from
   * the backend and reconcile it with the streamed state. The persisted
   * version is authoritative for files/media/metadata, so anything the SSE
   * accumulation missed (e.g. TTS audio in a multitask turn, where the
   * `audio` event is suppressed while task cards stream) still renders
   * without a page reload.
   *
   * Best-effort: a failed fetch leaves the streamed state untouched — the
   * user can always recover via reload, which uses the same mapper.
   */
  const reconcileMessage = async (localId: string, backendMessageId: number) => {
    try {
      const { chatApi } = await import('@/services/api')
      const response = (await chatApi.getMessage(backendMessageId)) as {
        success?: boolean
        message?: ApiLoadedMessageRow
      }

      if (!response.success || !response.message) return

      const local = messages.value.find((m) => m.id === localId)
      if (!local) return

      reconcileLocalMessage(local, mapApiMessageRow(response.message))
    } catch (error) {
      console.error('Failed to reconcile message with persisted state:', error)
    }
  }

  return {
    messages,
    isLoadingMessages,
    hasMoreMessages,
    addMessage,
    addStreamingMessage,
    updateStreamingMessage,
    finishStreamingMessage,
    markSuperseded,
    removeMessage,
    setMessageStatus,
    clearMessageError,
    clear,
    clearHistory: clear,
    loadMessages,
    loadMoreMessages,
    reconcileMessage,
  }
})
