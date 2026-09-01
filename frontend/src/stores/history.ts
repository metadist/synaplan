import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AgainData } from '@/types/ai-models'
import type { ApiActiveRun, ApiInProgressTurn, ApiLoadedMessageRow } from '@/utils/messageMapper'
import {
  IN_PROGRESS_TURN_ID,
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
  | 'json'
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
  /** Epoch ms when the first reasoning chunk arrived (live stream only, #1058). */
  thinkingStartedAt?: number
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
  'folder',
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
  /** Upstream step ids this step waited on (empty/undefined for roots). */
  dependsOn?: string[]
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

/**
 * Backoff schedule (ms between attempts) for recovering a turn whose SSE
 * stream dropped mid-flight (#1413). Roughly 19s total — long enough for the
 * backend to finish and persist a turn that was still running at drop time,
 * short enough to feel like a live recovery rather than a reload.
 */
const DROP_RECOVERY_DELAYS_MS = [1000, 2000, 3000, 5000, 8000]

/**
 * Whether a message already carries something the user can see — rendered
 * text, a media/link part, etc. Used to tell a persisted, finished answer
 * apart from an empty placeholder while recovering a dropped turn.
 */
function messageHasRenderableContent(message: Message): boolean {
  return message.parts.some(
    (part) =>
      (typeof part.content === 'string' && '' !== part.content.trim()) ||
      Boolean(part.url) ||
      Boolean(part.imageUrl) ||
      (Array.isArray(part.items) && part.items.length > 0)
  )
}

/**
 * Whether a message is a live, client-owned stream.
 *
 * The synthetic in-progress bubble carries `isStreaming` too, but it is not
 * live state the client owns — it is re-rendered from the server on every
 * in-progress poll. Treating it as a stream to preserve would keep the stale
 * copy and drop the fresh one, freezing the task cards it exists to update.
 */
function isLiveStream(message: Message): boolean {
  return true === message.isStreaming && message.id !== IN_PROGRESS_TURN_ID
}

/** Concatenated text content of a message, used for duplicate detection. */
function messageTextContent(message: Message): string {
  return message.parts
    .filter((part) => part.type === 'text')
    .map((part) => (part.content ?? '').trim())
    .join('\n')
}

/**
 * Merge a freshly hydrated history snapshot with the live message list when a
 * load resolves while a streaming exchange is in flight (see `loadMessages`).
 * The snapshot replaces everything EXCEPT the in-flight tail: the streaming
 * message(s) plus the locally added user message(s) of that exchange directly
 * preceding them (added by `addMessage` with a local uuid and no backend id
 * yet).
 *
 * Trailing snapshot rows that duplicate the tail are dropped — the live copy
 * wins, because ChatView still references it by its local id (error status,
 * post-stream reconciliation) and it carries the newest streamed content:
 *  - same local id or same persisted backend id (turn row persisted mid-stream),
 *  - the synthetic in-progress bubble (the live stream already renders it),
 *  - a user row with the same NON-EMPTY text as a not-yet-acknowledged local
 *    user message (the send was persisted before the response was built).
 * Only the trailing overlap is scanned, so older history rows with
 * coincidentally identical text are never dropped. Empty text is excluded from
 * that last rule: a file-only send carries no text, and matching on "" would
 * make every attachment-only prompt look like a duplicate of every other one.
 */
export function mergeHydrationWithStreamingTail(
  snapshot: Message[],
  current: Message[]
): Message[] {
  const firstStreamingIndex = current.findIndex(isLiveStream)
  if (firstStreamingIndex === -1) return snapshot

  let tailStart = firstStreamingIndex
  while (
    tailStart > 0 &&
    current[tailStart - 1].role === 'user' &&
    current[tailStart - 1].backendMessageId === undefined
  ) {
    tailStart--
  }
  const tail = current.slice(tailStart)

  const tailIds = new Set(tail.map((message) => message.id))
  const tailBackendIds = new Set(
    tail.map((message) => message.backendMessageId).filter((id): id is number => id !== undefined)
  )
  const tailUserTexts = new Set(
    tail
      .filter((message) => message.role === 'user' && message.backendMessageId === undefined)
      .map(messageTextContent)
      .filter((text) => text !== '')
  )

  const duplicatesTail = (message: Message): boolean =>
    tailIds.has(message.id) ||
    message.id === IN_PROGRESS_TURN_ID ||
    (message.backendMessageId !== undefined && tailBackendIds.has(message.backendMessageId)) ||
    (message.role === 'user' && tailUserTexts.has(messageTextContent(message)))

  let snapshotEnd = snapshot.length
  while (snapshotEnd > 0 && duplicatesTail(snapshot[snapshotEnd - 1])) {
    snapshotEnd--
  }

  return [...snapshot.slice(0, snapshotEnd), ...tail]
}

export const useHistoryStore = defineStore('history', () => {
  const messages = ref<Message[]>([])
  const isLoadingMessages = ref(false)
  const hasMoreMessages = ref(false)
  const currentOffset = ref(0)
  const inProgressPollIntervalMs = 2000

  /**
   * A turn of the loaded chat that is STILL generating on the server.
   *
   * The backend keeps a turn alive across a client disconnect and buffers its
   * events, so a reload or a trip to another view can pick it back up. The
   * store only reports it; ChatView owns the re-attach because rendering the
   * events needs its stream handler.
   */
  const activeRun = ref<ApiActiveRun | null>(null)

  // Monotonic generation counter: incremented each time loadMessages is called
  // for a fresh chat (offset === 0). Responses from older generations are
  // discarded so a slow response for a previous chat never overwrites the
  // messages of the current one.
  let loadGeneration = 0
  let inProgressPollTimer: ReturnType<typeof setTimeout> | null = null
  let inProgressPollChatId: number | null = null

  const stopInProgressPolling = () => {
    if (inProgressPollTimer !== null) {
      clearTimeout(inProgressPollTimer)
      inProgressPollTimer = null
    }
    inProgressPollChatId = null
  }

  function scheduleInProgressPoll(chatId: number) {
    if (inProgressPollTimer !== null && inProgressPollChatId === chatId) return

    stopInProgressPolling()
    inProgressPollChatId = chatId
    inProgressPollTimer = setTimeout(() => {
      inProgressPollTimer = null
      if (inProgressPollChatId !== chatId) return

      // A foreground page load owns the message list until it settles. Defer
      // the poll so it cannot replace or invalidate a concurrent load-more.
      if (isLoadingMessages.value) {
        scheduleInProgressPoll(chatId)
        return
      }

      const loadedMessageCount = messages.value.filter(
        (message) => message.id !== IN_PROGRESS_TURN_ID
      ).length
      void loadMessages(chatId, 0, Math.max(50, loadedMessageCount), true)
    }, inProgressPollIntervalMs)
  }

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

      // #1058: convert live wall-clock start → thinkingTime seconds, then clear
      // the ephemeral startedAt so history payloads stay lean.
      const now = Date.now()
      for (const part of message.parts) {
        if (part.type !== 'thinking') continue
        if (part.isStreaming) {
          delete part.isStreaming
        }
        if (typeof part.thinkingStartedAt === 'number' && !part.thinkingTime) {
          part.thinkingTime = Math.max(1, Math.round((now - part.thinkingStartedAt) / 1000))
        }
        delete part.thinkingStartedAt
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
    stopInProgressPolling()
    messages.value = []
    currentOffset.value = 0
    hasMoreMessages.value = false
    activeRun.value = null
  }

  const loadMessages = async (chatId: number, offset = 0, limit = 50, silent = false) => {
    if (!checkAuthOrRedirect()) return

    if (offset === 0 && !silent) {
      stopInProgressPolling()
    }

    // Only a foreground load from the beginning starts a new generation.
    // Silent same-chat polls share the current generation so they cannot
    // invalidate a concurrent load-more request.
    const startsNewGeneration = offset === 0 && !silent
    const myGeneration = startsNewGeneration ? ++loadGeneration : loadGeneration

    if (!silent) {
      isLoadingMessages.value = true
    }

    // Reset pagination state when loading from start (prevents stale state on error)
    if (offset === 0 && !silent) {
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
        activeRun?: ApiActiveRun | null
      }

      if (myGeneration !== loadGeneration) return

      if (response.success && response.messages) {
        const loadedMessages: Message[] = response.messages.map(mapApiMessageRow)

        // Only the first page carries it, and only while the turn runs.
        if (offset === 0) {
          activeRun.value = response.activeRun ?? null
        }

        // Issue #1142: append a provisional assistant bubble for a still-running
        // multi-task turn (only sent on the first page) so returning mid-stream
        // shows the running/completed task cards, not just the user prompt.
        if (offset === 0 && response.inProgressTurn) {
          loadedMessages.push(mapInProgressTurn(response.inProgressTurn))
          scheduleInProgressPoll(chatId)
        } else if (offset === 0 && inProgressPollChatId === chatId) {
          stopInProgressPolling()
        }

        // If offset is 0, replace messages; otherwise, prepend (for infinite scroll)
        if (offset === 0) {
          // A hydration may have started just before the user sent a message.
          // The live stream is newer than that response and must not be replaced
          // by its stale snapshot — but the snapshot still carries the chat's
          // prior history, so merge it in front of the in-flight exchange
          // instead of dropping it (and fall through to the pagination update,
          // which was reset before the fetch).
          //
          // Silent loads need the same protection: the very response that
          // triggers the merge also schedules the 2s in-progress poll above, and
          // that poll is silent — guarding only the foreground path let it
          // replace the streaming bubble two seconds later, so a multitask turn
          // visibly stopped streaming mid-answer. Drop recovery is unaffected:
          // ChatView calls finishStreamingMessage() before recoverInterruptedTurn(),
          // so its silent reloads never see an active stream to preserve.
          if (messages.value.some(isLiveStream)) {
            messages.value = mergeHydrationWithStreamingTail(loadedMessages, messages.value)
          } else {
            messages.value = loadedMessages
          }
        } else {
          messages.value = [...loadedMessages, ...messages.value]
        }

        currentOffset.value = offset + response.messages.length
        hasMoreMessages.value = response.pagination?.hasMore || false
      }
    } catch (error) {
      if (myGeneration !== loadGeneration) return
      console.error('Failed to load messages:', error)
      if (
        silent &&
        inProgressPollChatId === chatId &&
        messages.value.some((message) => message.id === IN_PROGRESS_TURN_ID)
      ) {
        scheduleInProgressPoll(chatId)
      }
    } finally {
      if (!silent && myGeneration === loadGeneration) {
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

  /**
   * Issue #1413: a mid-turn SSE transport drop can land BEFORE the backend has
   * persisted the answer — the turn keeps running on the server after the
   * socket closes (#1230/#1265). The previous recovery reconciled/reloaded
   * exactly ONCE, immediately, so it found nothing and the answer never
   * rendered until a manual page reload (re-sending then produced a duplicate
   * turn).
   *
   * Re-poll the persisted turn with bounded backoff instead. A plain reload is
   * used (not `reconcileMessage`) because the reconcile path deliberately keeps
   * the live-streamed text authoritative — for a drop the streamed text is
   * incomplete/empty, so only a fresh mapping of the persisted row shows the
   * answer. As soon as the backend reports the turn is still running
   * (`inProgressTurn`), the existing 2s in-progress poll (#1142/#1343) takes
   * over and this loop stops. Every fetch is an idempotent GET, so retrying is
   * safe.
   *
   * Recovery uses silent loads, which deliberately share the current
   * `loadGeneration`. If the user switches chats while we are parked on a
   * backoff timer, a foreground load for the new chat bumps `loadGeneration`;
   * we capture it on entry and bail the moment it changes so a late recovery
   * poll for the old chat can never overwrite the newly-selected chat.
   */
  const recoverInterruptedTurn = async (chatId: number): Promise<void> => {
    const recoveryGeneration = loadGeneration

    for (let attempt = 0; attempt <= DROP_RECOVERY_DELAYS_MS.length; attempt++) {
      if (recoveryGeneration !== loadGeneration) return

      const loadedMessageCount = messages.value.filter(
        (message) => message.id !== IN_PROGRESS_TURN_ID
      ).length
      await loadMessages(chatId, 0, Math.max(50, loadedMessageCount), true)

      // A foreground load (chat switch or manual reload) superseded us while
      // the silent reload was in flight — never touch the new chat's list.
      if (recoveryGeneration !== loadGeneration) return

      // Backend reports the turn is still running → hand off to the 2s poll.
      if (inProgressPollChatId === chatId) return

      // A persisted assistant answer for the turn has landed (a completed turn
      // ends with the assistant message).
      const lastReal = [...messages.value]
        .reverse()
        .find((message) => message.id !== IN_PROGRESS_TURN_ID)
      if (lastReal && 'assistant' === lastReal.role && messageHasRenderableContent(lastReal)) {
        return
      }

      const delay = DROP_RECOVERY_DELAYS_MS[attempt]
      if (undefined === delay) return // backoff schedule exhausted
      await new Promise((resolve) => setTimeout(resolve, delay))
    }
  }

  return {
    messages,
    isLoadingMessages,
    hasMoreMessages,
    activeRun,
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
    recoverInterruptedTurn,
  }
})
