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
      } else {
        // Parse the current content for thinking blocks
        const currentContent = message.parts[0]?.content || ''
        if (currentContent) {
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
  }

  return {
    messages,
    addMessage,
    addStreamingMessage,
    updateStreamingMessage,
    finishStreamingMessage,
    markSuperseded,
    clear,
  }
})
