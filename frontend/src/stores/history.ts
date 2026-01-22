import { defineStore } from 'pinia'
import { ref } from 'vue'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import { extractBTextPayload } from '@/utils/jsonResponse'
import { parseAIResponse } from '@/utils/responseParser'
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

export interface Part {
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
  provider?: string
  modelLabel?: string
  topic?: string // Topic from message classification (e.g., 'general', 'mediamaker')
  againData?: AgainData
  originalMessageId?: number
  backendMessageId?: number
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
  } | null // AI model metadata
  webSearch?: {
    enabled?: boolean
    query?: string
    resultsCount?: number
  } | null // Web search metadata
  tool?: {
    command: string // The command name (e.g., 'search', 'pic', 'vid')
    label: string // Display label (e.g., 'Web Search', 'Image Generation', 'Video Generation')
    icon: string // Icon identifier
  } | null // Tool/command metadata
}

/**
 * Parse content to extract thinking blocks, code blocks, and regular text.
 * This ensures consistent rendering between streaming and loaded messages.
 */
function parseContentWithThinking(
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
    tool?: { command: string; label: string; icon: string } | null
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

        console.log('🔍 finishStreamingMessage: Content length:', currentContent.length)
        console.log('🔍 finishStreamingMessage: Has <think>?', currentContent.includes('<think>'))
        console.log('🔍 finishStreamingMessage: Content preview:', currentContent.substring(0, 200))

        if (currentContent && currentContent.includes('<think>')) {
          console.log('✅ Parsing <think> tags!')
          message.parts = parseContentWithThinking(currentContent)
          console.log(
            '✅ Parsed parts:',
            message.parts.length,
            message.parts.map((p) => p.type)
          )
        } else {
          console.log('❌ No <think> tags found or content empty')
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

    isLoadingMessages.value = true

    // Reset pagination state when loading from start (prevents stale state on error)
    if (offset === 0) {
      currentOffset.value = 0
      hasMoreMessages.value = false
    }

    try {
      const { chatApi } = await import('@/services/api')
      const response = await chatApi.getChatMessages(chatId, offset, limit)

      if (response.success && response.messages) {
        const loadedMessages: Message[] = response.messages.map((m: any) => {
          const role = m.direction === 'IN' ? 'user' : 'assistant'

          const parts = parseContentWithThinking(m.text || '', role)

          // Add generated file (image/video/audio) as part if present
          if (m.file && m.file.path) {
            const absoluteUrl = normalizeMediaUrl(m.file.path)
            if (m.file.type === 'image') {
              parts.push({
                type: 'image',
                url: absoluteUrl,
                alt: m.text || 'Generated image',
              })
            } else if (m.file.type === 'video') {
              parts.push({
                type: 'video',
                url: absoluteUrl,
              })
            } else if (m.file.type === 'audio') {
              parts.push({
                type: 'audio',
                url: absoluteUrl,
              })
            }
          }

          // Parse files from backend response (user uploads)
          const files: MessageFile[] = []
          if (m.files && Array.isArray(m.files)) {
            files.push(
              ...m.files.map((f: any) => ({
                id: f.id,
                filename: f.filename || f.fileName,
                fileType: f.fileType || f.file_type,
                filePath: f.filePath || f.file_path,
                fileSize: f.fileSize || f.file_size,
                fileMime: f.fileMime || f.file_mime,
              }))
            )
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

          return {
            id: `backend-${m.id}`,
            role,
            parts,
            timestamp: new Date(m.timestamp * 1000),
            provider: m.provider,
            modelLabel: m.provider || 'AI',
            topic: m.topic, // Topic from message classification
            backendMessageId: m.id,
            files: files.length > 0 ? files : undefined,
            aiModels: m.aiModels || null, // Parse AI model metadata from backend
            webSearch: m.webSearch || null, // Parse web search metadata from backend
            searchResults: m.searchResults || null, // Parse actual search results from backend
            tool: toolData, // Reconstruct tool metadata from topic
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
      console.error('Failed to load messages:', error)
    } finally {
      isLoadingMessages.value = false
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
