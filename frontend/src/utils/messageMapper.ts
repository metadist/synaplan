import type {
  Message,
  MessageFile,
  MediaJobInfo,
  Part,
  TaskPlanState,
  TaskCardKind,
  TaskCardState,
} from '@/stores/history'
import { isTaskCardKind, isTaskCardState } from '@/stores/history'
import { extractBTextPayload } from '@/utils/jsonResponse'
import { parseAIResponse } from '@/utils/responseParser'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import { generatePartId, isMediaPartType } from '@/utils/mediaParts'
import { isChannelSource } from '@/utils/channelSource'
import {
  buildUploadUrl,
  isAudioFileType,
  isImageFileType,
  isVideoFileType,
} from '@/utils/mediaTypes'

/**
 * Issue #1070: single authoritative mapping from the persisted API row
 * (GET /chats/{id}/messages and GET /messages/{id}) to the frontend
 * `Message` shape.
 *
 * Both the reload path (`history.loadMessages`) and the post-stream
 * reconciliation (`history.reconcileMessage`) go through this module, so
 * "visible only after reload" divergence between the two paths is
 * structurally impossible for files/media/metadata.
 *
 * Keep this module side-effect free so it can be unit-tested without
 * mounting the chat view or the Pinia store.
 */

/**
 * A realtime/poll media-job status update, as delivered by the Centrifugo
 * `media_job.update` event (Sprint C) or the poll endpoint.
 */
export interface MediaJobUpdate {
  job_id: string
  message_id?: number | null
  chat_id?: number | null
  node_id?: string | null
  type: string
  state: string
  percent?: number | null
  error?: string | null
  file?: { url: string; type?: string } | null
}

/**
 * Apply a media-job update to a loaded message in place: patch `mediaJob` and,
 * on a terminal `done` with a produced file, append the generated media part
 * (idempotent — never duplicates an existing media part of that kind).
 *
 * Shared by the realtime `mediaJobs` store (push) and ChatView's completion
 * handler so the push and poll paths can never diverge.
 *
 * Multitask node jobs (the update carries a `node_id` matching a task card)
 * patch the CARD instead (#1239): the card is the surface for DAG media — the
 * single-task banner and a bubble-level media part would be wrong/duplicative
 * next to it.
 */
export function applyMediaJobUpdateToMessage(message: Message, update: MediaJobUpdate): void {
  if (update.node_id && message.taskPlan) {
    const card = message.taskPlan.cards.find((c) => c.nodeId === update.node_id)
    if (card) {
      // A user-cancelled card is terminal on the client (same rule as the SSE
      // task_update handler) — never overwrite it with a late job state.
      if (card.state !== 'cancelled' && isTaskCardState(update.state)) {
        card.state = update.state
      }
      if (update.error) {
        card.error = update.error
      }
      if (update.file?.url) {
        card.url = normalizeMediaUrl(update.file.url)
        card.mediaType = update.file.type ?? update.type
      }
      return
    }
  }

  message.mediaJob = {
    ...(message.mediaJob ?? {}),
    jobId: update.job_id,
    type: update.type,
    state: update.state,
    ...(update.error != null ? { error: update.error } : {}),
    ...(update.percent != null ? { percent: update.percent } : {}),
  }

  if ('done' === update.state && update.file?.url) {
    appendGeneratedMediaPart(message, update.file.url, update.file.type ?? update.type)
  }
}

/** Append a generated media part to a message, once per media kind. */
function appendGeneratedMediaPart(message: Message, url: string, type: string): void {
  const normalized = normalizeMediaUrl(url)
  if ('video' === type && !message.parts.some((p) => 'video' === p.type)) {
    message.parts.push({ partId: generatePartId(), type: 'video', url: normalized })
  } else if ('image' === type && !message.parts.some((p) => 'image' === p.type)) {
    message.parts.push({
      partId: generatePartId(),
      type: 'image',
      url: normalized,
      alt: 'Generated image',
    })
  } else if ('audio' === type && !message.parts.some((p) => 'audio' === p.type)) {
    message.parts.push({ partId: generatePartId(), type: 'audio', url: normalized })
  }
}

/** Normalize a media_job payload from API rows or SSE metadata. */
export function parseMediaJobPayload(raw: unknown): MediaJobInfo | null {
  if (!raw || typeof raw !== 'object') return null
  const row = raw as Record<string, unknown>
  const jobId = row.job_id ?? row.jobId
  const type = row.type
  const state = row.state
  if (typeof jobId !== 'string' || jobId === '') return null
  if (typeof type !== 'string' || type === '') return null
  const error = row.error
  const percent = row.percent
  return {
    jobId,
    type,
    state: typeof state === 'string' ? state : 'running',
    error: typeof error === 'string' ? error : undefined,
    percent: typeof percent === 'number' ? percent : undefined,
    elapsedSeconds:
      typeof row.elapsed_seconds === 'number'
        ? row.elapsed_seconds
        : typeof row.elapsedSeconds === 'number'
          ? row.elapsedSeconds
          : undefined,
    maxWaitSeconds:
      typeof row.max_wait_seconds === 'number'
        ? row.max_wait_seconds
        : typeof row.maxWaitSeconds === 'number'
          ? row.maxWaitSeconds
          : undefined,
  }
}

/**
 * Parse content to extract thinking blocks, code blocks, and regular text.
 * This ensures consistent rendering between streaming and loaded messages.
 */
export function parseContentWithThinking(
  content: string,
  role: 'user' | 'assistant' = 'assistant'
): Part[] {
  // Handle special file generation markers from backend
  if (content.startsWith('__FILE_GENERATED__:')) {
    const filename = content.replace('__FILE_GENERATED__:', '').trim()
    // Return a placeholder - the actual translation will be done in the component
    // because we need access to i18n there
    return [
      {
        type: 'text',
        content: `__FILE_GENERATED__:${filename}`,
      },
    ]
  }

  if (content === '__FILE_GENERATION_FAILED__') {
    return [
      {
        type: 'text',
        content: '__FILE_GENERATION_FAILED__',
      },
    ]
  }

  // Legacy: Check for old JSON format (BTEXT) from database
  // This is only for backward compatibility with old messages
  const extraction = extractBTextPayload(content)
  if (extraction.text !== undefined) {
    const remainder = extraction.remainder?.trim()
    const text = extraction.text ?? ''
    content = remainder ? `${text}\n\n${remainder}`.trim() : text
  }

  const parts: Part[] = []

  // Extract thinking blocks first
  const thinkRegex = /<think>([\s\S]*?)<\/think>/g
  const thinkingBlocks: Array<{ content: string; index: number }> = []
  let match

  while ((match = thinkRegex.exec(content)) !== null) {
    thinkingBlocks.push({
      content: match[1].trim(),
      index: match.index,
    })
  }

  // If there are thinking blocks, extract them
  if (thinkingBlocks.length > 0) {
    // Add thinking blocks
    thinkingBlocks.forEach((block) => {
      // Estimate thinking time based on content length (rough approximation)
      const thinkingTime = Math.max(3, Math.floor(block.content.length / 100))
      parts.push({
        type: 'thinking',
        content: block.content,
        thinkingTime,
      })
    })

    // Remove thinking blocks from content
    content = content.replace(/<think>[\s\S]*?<\/think>/g, '').trim()
  }

  // For assistant messages, use parseAIResponse to extract code blocks
  // This ensures consistent rendering between streaming and loaded messages
  if (role === 'assistant' && content) {
    const parsed = parseAIResponse(content)
    parsed.parts.forEach((part) => {
      if (part.type === 'code') {
        parts.push({
          type: 'code',
          content: part.content,
          language: part.language,
        })
      } else if (part.type === 'text' && part.content.trim()) {
        parts.push({
          type: 'text',
          content: part.content,
        })
      } else if (part.type === 'links' && part.links) {
        parts.push({
          type: 'links',
          items: part.links.map((l) => ({
            title: l.title,
            url: l.url,
            desc: l.description,
          })),
        })
      }
    })
  } else if (content) {
    // For user messages, just add as text
    parts.push({
      type: 'text',
      content,
    })
  }

  return parts.length > 0 ? parts : [{ type: 'text', content: '' }]
}

/** Attachment row from the messages API */
export interface ApiLoadedAttachmentFile {
  id: number
  filename?: string
  fileName?: string
  fileType?: string
  file_type?: string
  filePath?: string
  file_path?: string
  fileSize?: number
  file_size?: number
  fileMime?: string
  file_mime?: string
}

/** Message row from GET /chats/{id}/messages and GET /messages/{id} */
export interface ApiLoadedMessageRow {
  id: number
  direction?: string
  text?: string
  timestamp: number
  topic?: string
  originalTopic?: string
  originalMediaType?: string
  original_media_type?: string
  provider?: string
  aiModels?: Message['aiModels']
  webSearch?: Message['webSearch']
  searchResults?: Message['searchResults']
  multitask?: boolean
  quotedText?: string | null
  quotedMessageId?: number | null
  file?: { path: string; type: string }
  files?: ApiLoadedAttachmentFile[]
  /** Per-node render state for DAG turns — present only on OUT messages of DAG turns. */
  taskPlan?: {
    reply_node: string
    cards: Array<{
      nodeId: string
      capability: string
      kind: string
      state: string
      text?: string
      url?: string
      type?: string
      error?: string
      job_id?: string
      /** Compact web-search summary fields (search cards only) */
      query?: string
      resultsCount?: number
      /** #1229 smart collapse: card prose is contained in the answer body. */
      redundant?: boolean
    }>
  } | null
  /** Background async media job (Release 4.0). */
  mediaJob?: {
    job_id: string
    type: string
    state: string
    error?: string
  } | null
  /** Per-message token/cost usage for the taximeter (assistant messages). */
  usage?: {
    promptTokens: number
    completionTokens: number
    totalTokens: number
    cost: string | null
    modelKey: string
    kind: string
  } | null
  /** Auxiliary usage of the turn (sorting/routing call, media renders, TTS). */
  usageExtra?: Array<{
    promptTokens: number
    completionTokens: number
    totalTokens: number
    cost: string | null
    modelKey: string
    kind: string
  }> | null
}

/**
 * Map a persisted API message row to the frontend `Message` shape.
 *
 * Extracted from `history.loadMessages` so the post-stream reconciliation
 * resolves the final message state through the exact same logic as a page
 * reload (issue #1070).
 */
export function mapApiMessageRow(m: ApiLoadedMessageRow): Message {
  const role = m.direction === 'IN' ? 'user' : 'assistant'

  const parts = parseContentWithThinking(m.text || '', role)

  // Add generated file (image/video/audio) as part if present.
  // Issue #625: assign a stable `partId` on load so the `<audio>` /
  // `<video>` element keeps its identity if the message later gets
  // re-parsed (e.g. continuation appends text). Without this, the
  // index-based fallback key would remount the media player.
  //
  // Issue #955: detect audio/image/video by both the generic media
  // kind (`audio`, `image`, `video` — used by TTS / MEDIAMAKER) and
  // by the raw file extension (`ogg`, `mp3`, `png`, …) the backend
  // stores for inbound WhatsApp voice notes and direct uploads.
  // Without the extension-aware check, a WhatsApp voice reply was
  // surfaced as a plain text bubble with no player.
  //
  // Relative `m.file.path` values (e.g. WhatsApp's `13/.../voice.ogg`)
  // are first prefixed with the static-serve endpoint so the player
  // can fetch them; absolute URLs and already-prefixed paths pass
  // through `buildUploadUrl` unchanged before final normalization.
  if (m.file && m.file.path) {
    const absoluteUrl = normalizeMediaUrl(buildUploadUrl(m.file.path))
    if (isImageFileType(m.file.type)) {
      parts.push({
        partId: generatePartId(),
        type: 'image',
        url: absoluteUrl,
        alt: m.text || 'Generated image',
      })
    } else if (isVideoFileType(m.file.type)) {
      parts.push({
        partId: generatePartId(),
        type: 'video',
        url: absoluteUrl,
      })
    } else if (isAudioFileType(m.file.type)) {
      parts.push({
        partId: generatePartId(),
        type: 'audio',
        url: absoluteUrl,
      })
    }
  }

  // Parse files from backend response (user uploads).
  const files: MessageFile[] = []
  if (m.files && Array.isArray(m.files)) {
    files.push(
      ...m.files.map((f) => ({
        id: f.id,
        filename: f.filename || f.fileName || '',
        fileType: f.fileType || f.file_type || '',
        filePath: f.filePath || f.file_path || '',
        fileSize: f.fileSize ?? f.file_size,
        fileMime: f.fileMime ?? f.file_mime,
      }))
    )
  }

  // Issue #955: render uploaded audio attachments with the
  // `MessageAudio` player instead of only as a download badge.
  // The badge stays via `files[]` so the user can still see the
  // filename / size and download the original recording. Inbound
  // WhatsApp voice messages travel through this same `files`
  // pipeline once they're persisted as a `File` entity.
  //
  // The MIME type is forwarded so that ambiguous containers like
  // `.webm` get classified by the actual upload mime (`audio/webm`
  // for voice notes, `video/webm` for screen recordings) instead
  // of falling into the audio default purely by extension.
  for (const file of files) {
    if (!isAudioFileType(file.fileType, file.fileMime)) continue
    const audioUrl = buildUploadUrl(file.filePath)
    if (!audioUrl) continue
    parts.push({
      partId: generatePartId(),
      type: 'audio',
      url: normalizeMediaUrl(audioUrl),
    })
  }

  // Rebuild task plan cards from persisted render state (issue #1070 DAG reload).
  // The card urls are added to a dedup set so we don't also emit them as plain
  // media parts in the bubble (same dedup logic as reconcileLocalMessage).
  let taskPlanState: TaskPlanState | null = null
  const cardMediaUrls = new Set<string>()
  if (m.taskPlan && m.taskPlan.cards.length > 0) {
    const cards = m.taskPlan.cards.map((c) => {
      const kind: TaskCardKind = isTaskCardKind(c.kind) ? c.kind : 'text'
      const state: TaskCardState = isTaskCardState(c.state) ? c.state : 'skipped'
      let cardUrl: string | undefined
      if (c.url) {
        cardUrl = normalizeMediaUrl(buildUploadUrl(c.url))
        cardMediaUrls.add(mediaUrlKey(cardUrl))
      }
      return {
        nodeId: c.nodeId,
        capability: c.capability,
        kind,
        state,
        text: c.text ?? '',
        url: cardUrl,
        mediaType: c.type,
        error: c.error,
        query: c.query,
        resultsCount: c.resultsCount,
        jobId: typeof c.job_id === 'string' ? c.job_id : undefined,
        // #1229 smart collapse: assembly-time redundancy flag. The body stays
        // the canonical answer surface; the duplicated card collapses (the
        // previous #1165 approach of REMOVING the body text is retired).
        redundant: c.redundant === true,
      }
    })
    taskPlanState = {
      active: false,
      replyNode: m.taskPlan.reply_node,
      cards,
    }
  }

  // Remove any plain-media parts whose URL already appears on a restored card
  // so the user sees each piece of media exactly once (card is the primary surface).
  const deduplicatedParts =
    cardMediaUrls.size > 0
      ? parts.filter(
          (p) => !(isMediaPartType(p.type) && p.url && cardMediaUrls.has(mediaUrlKey(p.url)))
        )
      : parts

  // Reconstruct tool metadata from topic field for user messages
  // Also clean command prefix from message content
  let toolData: { command: string; label: string; icon: string } | null = null
  if (role === 'user' && m.topic && m.topic.startsWith('tools:')) {
    const cmd = m.topic.replace('tools:', '')
    const toolMap: Record<string, { label: string; icon: string }> = {
      search: { label: 'Web Search', icon: 'mdi:web' },
      pic: { label: 'Image Generation', icon: 'mdi:image' },
      vid: { label: 'Video Generation', icon: 'mdi:video' },
    }
    if (toolMap[cmd]) {
      toolData = { command: cmd, ...toolMap[cmd] }

      // Remove command prefix from parts content if present
      if (parts.length > 0 && parts[0].type === 'text' && parts[0].content) {
        const content = parts[0].content
        const commandMatch = content.match(/^\/(\w+)\s+(.*)$/)
        if (commandMatch && commandMatch[1] === cmd) {
          // Remove the command prefix from display
          parts[0] = { ...parts[0], content: commandMatch[2].trim() }
        }
      }
    }
  }

  // The backend reuses the `provider` column to also store the
  // channel/source for inbound messages (`WHATSAPP`, `EMAIL`,
  // `WEB`, `widget`, …). When that token leaks into the chat
  // footer the user sees confusing labels like
  // `Model: WHATSAPP · Provider: WHATSAPP`. Strip it here so
  // only real AI provider names ever reach the UI — see #653.
  const rawProvider = m.aiModels?.chat?.provider ?? m.provider
  const cleanProvider = isChannelSource(rawProvider) ? undefined : rawProvider
  const rawModelLabel = m.aiModels?.chat?.model ?? m.provider
  const cleanModelLabel = isChannelSource(rawModelLabel)
    ? role === 'assistant'
      ? 'AI'
      : undefined
    : (rawModelLabel ?? (role === 'assistant' ? 'AI' : undefined))

  return {
    id: `backend-${m.id}`,
    role,
    parts: deduplicatedParts,
    timestamp: new Date(m.timestamp * 1000),
    provider: cleanProvider,
    modelLabel: cleanModelLabel,
    topic: m.topic,
    originalTopic: m.originalTopic || null,
    originalMediaType: m.originalMediaType ?? m.original_media_type ?? null,
    backendMessageId: m.id,
    quotedText: m.quotedText ?? null,
    quotedMessageId: m.quotedMessageId ?? null,
    files: files.length > 0 ? files : undefined,
    aiModels: m.aiModels || null,
    webSearch: m.webSearch || null,
    searchResults: m.searchResults || null,
    wasMultitask: m.multitask === true,
    tool: toolData,
    taskPlan: taskPlanState,
    mediaJob: parseMediaJobPayload(m.mediaJob),
    usage: m.usage ?? null,
    usageExtra: m.usageExtra ?? null,
  }
}

/** In-progress turn payload from GET /chats/{id}/messages (#1142 / #1343). */
export interface ApiInProgressTurn {
  reply_node: string
  cards: Array<{
    nodeId: string
    capability: string
    kind: string
    state: string
    text?: string | null
    url?: string | null
    error?: string | null
    query?: string | null
    resultsCount?: number | null
    type?: string | null
  }>
}

/** Stable client id for the synthesized in-progress assistant bubble (#1142). */
export const IN_PROGRESS_TURN_ID = 'in-progress-turn'

/**
 * Issue #1142 / #1343: build a provisional assistant message from the
 * in-progress turn payload so that reloading (or returning to) a chat while a
 * multi-task turn is still running shows the running/completed task cards —
 * including settled text/url/error — instead of only the bare user prompt. The
 * real assistant message replaces this on the next reload once the turn
 * completes and its OUT row exists.
 *
 * The bubble carries a fixed synthetic id (never `backend-*`), so it can't
 * collide with a persisted row and is ignored by the reconcile path (which
 * keys on `backendMessageId`).
 */
export function mapInProgressTurn(turn: ApiInProgressTurn): Message {
  const cards = turn.cards.map((c) => ({
    nodeId: c.nodeId,
    capability: c.capability,
    kind: isTaskCardKind(c.kind) ? c.kind : ('text' as TaskCardKind),
    state: isTaskCardState(c.state) ? c.state : ('running' as TaskCardState),
    text: typeof c.text === 'string' ? c.text : '',
    ...(typeof c.url === 'string' && c.url ? { url: c.url } : {}),
    ...(typeof c.error === 'string' && c.error ? { error: c.error } : {}),
    ...(typeof c.query === 'string' && c.query ? { query: c.query } : {}),
    ...(typeof c.resultsCount === 'number' && c.resultsCount > 0
      ? { resultsCount: c.resultsCount }
      : {}),
    ...(typeof c.type === 'string' && c.type ? { mediaType: c.type } : {}),
  }))

  return {
    id: IN_PROGRESS_TURN_ID,
    role: 'assistant',
    parts: [{ type: 'text', content: '' }],
    timestamp: new Date(),
    isStreaming: true,
    modelLabel: 'AI',
    wasMultitask: true,
    taskPlan: {
      active: true,
      replyNode: turn.reply_node,
      cards,
    },
  }
}

/**
 * Comparison key for media URLs: the path component, ignoring origin and
 * query string. The live SSE event and the persisted row may differ in
 * absolute-vs-relative form or cache-buster params, but the upload path
 * itself is identical.
 */
function mediaUrlKey(url: string): string {
  try {
    return new URL(url, 'http://localhost').pathname
  } catch {
    return url
  }
}

/**
 * True when a text part holds a document-generation result marker
 * (`__FILE_GENERATED__:<name>` or `__FILE_GENERATION_FAILED__`). These are
 * emitted by the backend for officemaker/document turns and translated to the
 * user-facing download prompt / failure notice by `MessageText`.
 */
function isFileGenerationMarker(content: string | undefined): boolean {
  if (!content) return false
  return content.startsWith('__FILE_GENERATED__:') || content === '__FILE_GENERATION_FAILED__'
}

/**
 * Issue #1070: reconcile a live-streamed message with its persisted
 * version after SSE `complete`. The persisted version is authoritative
 * for files, generated media, and metadata; the streamed state stays
 * authoritative for the live text parts (already rendered chunk by
 * chunk) and task-card animations.
 *
 * - Media parts (image/video/audio) present in the persisted message but
 *   missing from the live bubble are appended — this is what makes e.g.
 *   TTS audio in a multitask (DAG) turn appear without a page reload.
 * - Media already shown by an active task card is NOT duplicated into
 *   the bubble; the card remains the streaming-time surface.
 * - Metadata (files, aiModels, webSearch, searchResults, topic, …) is
 *   overwritten when the persisted version carries a value, and left
 *   untouched otherwise (never wipes live-only state).
 *
 * Mutates `local` in place (it is a reactive store object). The parts
 * array is reassigned — not pushed to — so a detached proxy reference
 * can never swallow the update (see `pushMediaPart` for background).
 */
export function reconcileLocalMessage(local: Message, persisted: Message): void {
  // --- Media parts ---------------------------------------------------
  const shownUrls = new Set<string>()
  for (const part of local.parts) {
    if (isMediaPartType(part.type) && part.url) {
      shownUrls.add(mediaUrlKey(part.url))
    }
  }
  for (const card of local.taskPlan?.cards ?? []) {
    if (card.url) {
      shownUrls.add(mediaUrlKey(card.url))
    }
  }

  const missingMedia: Part[] = persisted.parts.filter(
    (p) =>
      isMediaPartType(p.type) && typeof p.url === 'string' && !shownUrls.has(mediaUrlKey(p.url))
  )
  if (missingMedia.length > 0) {
    local.parts = [...local.parts, ...missingMedia]
  }

  // --- File-generation marker text (issue #1258) ----------------------
  // A document/officemaker turn persists its response as a special marker
  // (`__FILE_GENERATED__:<name>` / `__FILE_GENERATION_FAILED__`) that
  // MessageText translates into the download prompt / failure notice. The
  // streaming path assembles this text through a separate branch that can
  // miss it — e.g. a multitask/DAG document node (no `generatedFile` in the
  // `complete` payload) or a JSON body that never matched the replace
  // heuristic — leaving the bubble without any response text until a reload.
  //
  // The persisted row is authoritative after `complete`, so adopt its marker
  // text here. This keeps the streaming and reload paths from diverging (the
  // structural fix from #1070): stale text/code parts (raw JSON, an empty
  // body, or an already-translated duplicate) are dropped and the marker is
  // prepended, while generated media/link parts are preserved.
  const persistedMarker = persisted.parts.find(
    (p) => p.type === 'text' && isFileGenerationMarker(p.content)
  )
  if (persistedMarker) {
    const alreadyShown = local.parts.some(
      (p) => p.type === 'text' && p.content === persistedMarker.content
    )
    if (!alreadyShown) {
      const preserved = local.parts.filter((p) => p.type !== 'text' && p.type !== 'code')
      local.parts = [persistedMarker, ...preserved]
    }
  }

  // --- Files (attachments + generated documents) ----------------------
  if (persisted.files && persisted.files.length > 0) {
    local.files = persisted.files
  }

  // --- Metadata: persisted wins when present --------------------------
  if (persisted.aiModels) {
    local.aiModels = persisted.aiModels
    if (persisted.provider) local.provider = persisted.provider
    if (persisted.modelLabel) local.modelLabel = persisted.modelLabel
  }
  if (persisted.webSearch) {
    local.webSearch = persisted.webSearch
  }
  if (persisted.searchResults && persisted.searchResults.length > 0) {
    local.searchResults = persisted.searchResults
  }
  if (persisted.topic) {
    local.topic = persisted.topic
  }
  if (persisted.originalTopic) {
    local.originalTopic = persisted.originalTopic
  }
  if (persisted.originalMediaType) {
    local.originalMediaType = persisted.originalMediaType
  }
  if (persisted.wasMultitask) {
    local.wasMultitask = true
  }
  // Taximeter usage: adopt the persisted (authoritative) value when present so
  // the token-cost badge survives the post-stream reconcile.
  if (persisted.usage) {
    local.usage = persisted.usage
  }
  if (persisted.usageExtra && persisted.usageExtra.length > 0) {
    local.usageExtra = persisted.usageExtra
  }
  // Media job state: a terminal client state is FINAL and must never be
  // downgraded back to `running` by a stale persisted snapshot. Without this
  // guard the post-completion reconcile (which can race the worker's message
  // sync) flips a just-completed job back to `running`, which re-enables
  // polling, which completes again — an endless flicker between the video and
  // the "generating" banner. Only apply the persisted state when our local
  // state is not yet terminal, or when the persisted state is itself terminal.
  if (persisted.mediaJob) {
    const terminal = new Set(['done', 'failed', 'cancelled'])
    const localTerminal = local.mediaJob ? terminal.has(local.mediaJob.state) : false
    const persistedTerminal = terminal.has(persisted.mediaJob.state)
    if (!localTerminal || persistedTerminal) {
      local.mediaJob = persisted.mediaJob
    }
  }
}
