import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AgainData } from '@/types/ai-models'

export type PartType = 'text' | 'image' | 'video' | 'code' | 'links' | 'docs' | 'screenshot' | 'translation' | 'link' | 'commandList' | 'thinking'

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
  thinkingTime?: number  // Time in seconds for thinking process
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
  againData?: AgainData
  originalMessageId?: number
  backendMessageId?: number
}

/**
 * Parse content to extract thinking blocks and regular text
 */
function parseContentWithThinking(content: string): Part[] {
  const parts: Part[] = []
  
  // Extract thinking blocks
  const thinkRegex = /<think>([\s\S]*?)<\/think>/g
  const thinkingBlocks: Array<{ content: string; index: number }> = []
  let match
  
  while ((match = thinkRegex.exec(content)) !== null) {
    thinkingBlocks.push({
      content: match[1].trim(),
      index: match.index
    })
  }
  
  // If there are thinking blocks, extract them
  if (thinkingBlocks.length > 0) {
    // Add thinking blocks
    thinkingBlocks.forEach(block => {
      // Estimate thinking time based on content length (rough approximation)
      const thinkingTime = Math.max(3, Math.floor(block.content.length / 100))
      parts.push({
        type: 'thinking',
        content: block.content,
        thinkingTime
      })
    })
    
    // Remove thinking blocks from content
    content = content.replace(/<think>[\s\S]*?<\/think>/g, '').trim()
  }
  
  // Add remaining text content
  if (content) {
    parts.push({
      type: 'text',
      content
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
    provider?: string, 
    modelLabel?: string,
    againData?: AgainData,
    backendMessageId?: number,
    originalMessageId?: number
  ) => {
    messages.value.push({
      id: crypto.randomUUID(),
      role,
      parts,
      timestamp: new Date(),
      provider,
      modelLabel,
      againData,
      backendMessageId,
      originalMessageId
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
      originalMessageId
    })
    return id
  }

  const updateStreamingMessage = (id: string, content: string) => {
    const message = messages.value.find(m => m.id === id)
    if (message && message.parts[0]) {
      message.parts[0].content = content
    }
  }

  const finishStreamingMessage = (id: string, parts?: Part[]) => {
    const message = messages.value.find(m => m.id === id)
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

  const markSuperseded = (id: string) => {
    const message = messages.value.find(m => m.id === id)
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
    isLoadingMessages.value = true
    try {
      const { chatApi } = await import('@/services/api')
      const response = await chatApi.getChatMessages(chatId, offset, limit)
      
      if (response.success && response.messages) {
        const loadedMessages: Message[] = response.messages.map((m: any) => {
          const role = m.direction === 'IN' ? 'user' : 'assistant'
          const parts = parseContentWithThinking(m.text || '')
          
          return {
            id: `backend-${m.id}`,
            role,
            parts,
            timestamp: new Date(m.timestamp * 1000),
            provider: m.provider,
            modelLabel: m.provider || 'AI',
            backendMessageId: m.id
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
    clear,
    clearHistory: clear,
    loadMessages,
    loadMoreMessages
  }
})
