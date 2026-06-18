import { defineStore } from 'pinia'
import { ref } from 'vue'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import { extractBTextPayload } from '@/utils/jsonResponse'
import { parseAIResponse } from '@/utils/responseParser'
import { generatePartId } from '@/utils/mediaParts'
import { isChannelSource } from '@/utils/channelSource'
import {
  buildUploadUrl,
  isAudioFileType,
  isImageFileType,
  isVideoFileType,
} from '@/utils/mediaTypes'
import type { AgainData } from '@/types/ai-models'
import { authService } from '@/services/authService'

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
  // Multitask routing: true when this assistant turn ran the DAG executor.
  // Persisted server-side (`multitask` meta), so it survives reloads — used to
  // show the simple "Again" (full re-plan) instead of "Again with…".
  wasMultitask?: boolean
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

export const TASK_CARD_STATES = ['pending', 'running', 'done', 'failed', 'skipped'] as const
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
}

export interface TaskPlanState {
  active: boolean
  replyNode: string
  cards: TaskCard[]
}

/** Attachment row from chat messages API */
interface ApiLoadedAttachmentFile {
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

/** Message row from GET /chats/:id/messages */
interface ApiLoadedMessageRow {
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
      }

      if (myGeneration !== loadGeneration) return

      if (response.success && response.messages) {
        const loadedMessages: Message[] = response.messages.map((m) => {
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
            parts,
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
          }
        })

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
  }
})
