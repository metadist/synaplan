<template>
  <MainLayout>
    <template #header>
    </template>

    <div class="flex flex-col h-full" data-testid="page-chat">
      <div ref="chatContainer" class="flex-1 overflow-y-auto bg-chat" @scroll="handleScroll" data-testid="section-messages">
        <div class="max-w-4xl mx-auto py-6">
          <!-- Loading indicator for infinite scroll -->
          <div v-if="historyStore.isLoadingMessages" class="flex items-center justify-center py-4" data-testid="state-loading">
            <svg class="w-4 h-4 animate-spin txt-brand" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
            </svg>
            <span class="ml-2 txt-secondary text-sm">Loading messages...</span>
          </div>
          
          <div v-if="historyStore.messages.length === 0 && !historyStore.isLoadingMessages" class="flex items-center justify-center h-full px-6" data-testid="state-empty">
            <div class="text-center">
              <h2 class="text-2xl font-semibold txt-primary mb-2">
                {{ $t('welcome') }}
              </h2>
              <p class="txt-secondary">
                {{ $t('chatInput.placeholder') }}
              </p>
            </div>
          </div>

          <template v-for="(group, groupIndex) in groupedMessages" :key="groupIndex">
            <div class="flex items-center justify-center my-4" data-testid="item-message-group">
              <div class="px-4 py-1.5 surface-chip text-xs font-medium txt-secondary">
                {{ group.label }}
              </div>
            </div>
            <ChatMessage
              v-for="message in group.messages"
              :key="message.id"
              :role="message.role"
              :parts="message.parts"
              :timestamp="message.timestamp"
              :is-superseded="message.isSuperseded"
              :is-streaming="message.isStreaming"
              :provider="message.provider"
              :model-label="message.modelLabel"
              :topic="message.topic"
              :again-data="message.againData"
              :backend-message-id="message.backendMessageId"
              :processing-status="message.isStreaming ? processingStatus : undefined"
              :processing-metadata="message.isStreaming ? processingMetadata : undefined"
              :files="message.files"
              :search-results="message.searchResults"
              :ai-models="message.aiModels"
              :web-search="message.webSearch"
              :tool="message.tool"
              @regenerate="handleRegenerate(message, $event)"
              @again="handleAgain"
            />
          </template>
        </div>
      </div>

      <ChatInput 
        ref="chatInputRef"
        :is-streaming="isStreaming" 
        @send="handleSendMessage"
        @stop="handleStopStreaming"
      />
    </div>

    <!-- Limit Reached Modal -->
    <LimitReachedModal
      :is-open="showLimitModal"
      :limit-type="limitData?.limitType || 'lifetime'"
      :action-type="limitData?.actionType || 'MESSAGES'"
      :used="limitData?.used || 0"
      :current-limit="limitData?.limit || 0"
      :reset-time="limitData?.resetTime"
      :user-level="limitData?.userLevel || 'NEW'"
      :phone-verified="limitData?.phoneVerified || false"
      @close="closeLimitModal"
      @upgrade="closeLimitModal"
      @verify-phone="closeLimitModal"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import ChatInput from '@/components/ChatInput.vue'
import ChatMessage from '@/components/ChatMessage.vue'
import LimitReachedModal from '@/components/common/LimitReachedModal.vue'
import { useHistoryStore, type Message } from '@/stores/history'
import { useChatsStore } from '@/stores/chats'
import { useModelsStore } from '@/stores/models'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import { useLimitCheck } from '@/composables/useLimitCheck'
import { chatApi } from '@/services/api'
import type { ModelOption } from '@/composables/useModelSelection'
import { parseAIResponse } from '@/utils/responseParser'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import { httpClient } from '@/services/api/httpClient'

const { t } = useI18n()
const { showLimitModal, limitData, checkAndShowLimit, closeLimitModal } = useLimitCheck()

const chatContainer = ref<HTMLElement | null>(null)
const chatInputRef = ref<InstanceType<typeof ChatInput> | null>(null)
const autoScroll = ref(true)
const historyStore = useHistoryStore()
const chatsStore = useChatsStore()
const modelsStore = useModelsStore()
const aiConfigStore = useAiConfigStore()
const authStore = useAuthStore()
let streamingAbortController: AbortController | null = null
let stopStreamingFn: (() => void) | null = null // Store EventSource close function
let currentTrackId: number | undefined = undefined // Store current trackId for stop request

// Processing status for real-time feedback
const processingStatus = ref<string>('')
const processingMetadata = ref<any>({})

// Use mock data in development or when API is not available
const useMockData = import.meta.env.VITE_USE_MOCK_DATA === 'true' || false

interface MessageGroup {
  label: string
  messages: Message[]
}

const isStreaming = computed(() => {
  return historyStore.messages.some(m => m.isStreaming === true)
})

// Init on mount
onMounted(async () => {
  // Load AI models config for Again functionality
  await Promise.all([
    aiConfigStore.loadModels(),
    aiConfigStore.loadDefaults()
  ])
  
  // Load chats first
  await chatsStore.loadChats()
  
  // If no active chat, create one
  if (!chatsStore.activeChatId) {
    await chatsStore.createChat('New Chat')
  } else {
    // Load messages for active chat
    await historyStore.loadMessages(chatsStore.activeChatId)
  }
  
  // Auto-focus ChatInput after mounting with delay
  await nextTick()
  setTimeout(() => {
    if (chatInputRef.value?.textareaRef) {
      console.log('🎯 Auto-focusing ChatInput')
      chatInputRef.value.textareaRef.focus()
    } else {
      console.warn('⚠️ ChatInput ref not available for auto-focus')
    }
  }, 100)
})

// Cleanup: Stop streaming when component unmounts (user leaves chat)
onBeforeUnmount(() => {
  console.log('🧹 ChatView unmounting - cleaning up streaming')
  handleStopStreaming()
})

// Watch for active chat changes and load messages
watch(() => chatsStore.activeChatId, async (newChatId) => {
  if (newChatId) {
    historyStore.clear()
    await historyStore.loadMessages(newChatId)
    await nextTick()
    scrollToBottom()
    
    // Auto-focus input when switching chats
    setTimeout(() => {
      if (chatInputRef.value?.textareaRef) {
        chatInputRef.value.textareaRef.focus()
      }
    }, 100)
  }
})

async function generateChatTitleFromFirstMessage(firstMessage: string) {
  const chat = chatsStore.activeChat
  if (!chat) return
  
  // Only generate if chat has default title
  if (chat.title && chat.title !== 'New Chat') return
  
  // Only generate for user messages from this chat
  const userMessages = historyStore.messages.filter(m => m.role === 'user')
  if (userMessages.length !== 1) return
  
  // Generate title from first message (take first 50 chars)
  let title = firstMessage.trim()
  if (title.length > 50) {
    title = title.substring(0, 47) + '...'
  }
  
  // Update chat title
  await chatsStore.updateChatTitle(chat.id, title)
}

const getDateLabel = (date: Date): string => {
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const messageDate = new Date(date)
  messageDate.setHours(0, 0, 0, 0)

  const diffTime = today.getTime() - messageDate.getTime()
  const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24))

  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'

  return messageDate.toLocaleDateString('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  })
}


const groupedMessages = computed(() => {
  const groups: MessageGroup[] = []
  let currentGroup: MessageGroup | null = null

  historyStore.messages.forEach((message) => {
    const label = getDateLabel(message.timestamp)

    if (!currentGroup || currentGroup.label !== label) {
      currentGroup = { label, messages: [] }
      groups.push(currentGroup)
    }

    currentGroup.messages.push(message)
  })

  return groups
})

const scrollToBottom = () => {
  if (autoScroll.value && chatContainer.value) {
    nextTick(() => {
      if (chatContainer.value) {
        chatContainer.value.scrollTop = chatContainer.value.scrollHeight
      }
    })
  }
}

const handleScroll = async () => {
  if (!chatContainer.value) return

  const { scrollTop, scrollHeight, clientHeight } = chatContainer.value
  
  // Check if at bottom for auto-scroll
  const isAtBottom = Math.abs(scrollHeight - clientHeight - scrollTop) < 50
  autoScroll.value = isAtBottom
  
  // Check if at top for loading more messages (Infinite Scroll)
  const isAtTop = scrollTop < 100
  if (isAtTop && historyStore.hasMoreMessages && !historyStore.isLoadingMessages && chatsStore.activeChatId) {
    const currentScrollHeight = scrollHeight
    await historyStore.loadMoreMessages(chatsStore.activeChatId)
    // Restore scroll position after loading
    await nextTick()
    if (chatContainer.value) {
      const newScrollHeight = chatContainer.value.scrollHeight
      chatContainer.value.scrollTop = newScrollHeight - currentScrollHeight + scrollTop
    }
  }
}

watch(() => historyStore.messages, () => {
  scrollToBottom()
}, { deep: true })

const handleSendMessage = async (content: string, options?: { includeReasoning?: boolean, webSearch?: boolean, modelId?: number, fileIds?: number[] }) => {
  autoScroll.value = true

  // Prepare files info if fileIds are provided
  let files: any[] | undefined = undefined
  if (options?.fileIds && options.fileIds.length > 0) {
    // Import filesService dynamically
    const { default: filesService } = await import('@/services/filesService')
    
    // Fetch file details for each fileId
    files = []
    for (const fileId of options.fileIds) {
      try {
        const response = await filesService.getFileContent(fileId)
        if (response) {
          files.push({
            id: response.id,
            filename: response.filename,
            fileType: response.file_type,
            filePath: response.file_path,
            fileSize: response.file_size,
            fileMime: response.mime
          })
        }
      } catch (error) {
        console.error('Failed to fetch file details:', fileId, error)
      }
    }
  }

  // Prepare webSearch metadata for user message
  const webSearchData = options?.webSearch ? { enabled: true } : null

  // Prepare tool metadata based on command in message
  // Also extract the clean content without command prefix for display
  let toolData: { command: string; label: string; icon: string } | null = null
  let displayContent = content
  let backendContent = content // Content to send to backend
  
  if (content.startsWith('/')) {
    const commandMatch = content.match(/^\/(\w+)\s+(.*)$/)
    if (commandMatch) {
      const cmd = commandMatch[1]
      const args = commandMatch[2] || ''
      
      const toolMap: Record<string, { label: string; icon: string }> = {
        'search': { label: 'Web Search', icon: 'mdi:web' },
        'pic': { label: 'Image Generation', icon: 'mdi:image' },
        'vid': { label: 'Video Generation', icon: 'mdi:video' }
      }
      
      if (toolMap[cmd]) {
        toolData = { command: cmd, ...toolMap[cmd] }
        // Remove command prefix from display content
        displayContent = args.trim()
        
        // For /search, send only the query to backend (we use webSearch flag)
        // For /pic and /vid, keep the full command (backend needs it for routing)
        if (cmd === 'search') {
          backendContent = args.trim()
        }
      }
    }
  }

  // Add user message with files, webSearch, and tool info
  // Use displayContent (without command) for the message text shown in UI
  historyStore.addMessage(
    'user', 
    [{ type: 'text', content: displayContent }], 
    files, 
    undefined, // provider 
    undefined, // modelLabel
    undefined, // againData
    undefined, // backendMessageId
    undefined, // originalMessageId
    webSearchData, // webSearch
    toolData // tool
  )

  // Stream to backend - use backendContent which may differ from displayContent
  await streamAIResponse(backendContent, options)
}

const streamAIResponse = async (userMessage: string, options?: { includeReasoning?: boolean; webSearch?: boolean; modelId?: number; fileIds?: number[] }) => {
  streamingAbortController = new AbortController()
  
  // Get current selected model from aiConfig store (DB model with ID)
  const currentModel = aiConfigStore.getCurrentModel('CHAT')
  const provider = currentModel?.service || modelsStore.selectedProvider
  const modelLabel = currentModel?.name || modelsStore.selectedModel
  
  // Create empty streaming message with provider info
  const messageId = historyStore.addStreamingMessage('assistant', provider, modelLabel)
  
  try {
    if (useMockData) {
      // Mock streaming for development (simple text streaming)
      const mockResponse = 'This is a mock response for development. The actual streaming is handled by the backend API.'
      
      // Simple character-by-character streaming
      for (let i = 0; i < mockResponse.length; i += 3) {
        if (streamingAbortController.signal.aborted) {
          break
        }
        const chunk = mockResponse.slice(0, i + 3)
        historyStore.updateStreamingMessage(messageId, chunk)
        await new Promise(resolve => setTimeout(resolve, 30))
      }
      
      historyStore.finishStreamingMessage(messageId)
    } else {
      // Use real Backend API with SSE streaming
      const userId = authStore.user?.id || 1
      const chatId = chatsStore.activeChatId
      
      if (!chatId) {
        console.error('No active chat selected')
        return
      }
      
      const trackId = Date.now()
      currentTrackId = trackId // Store for stop functionality
      console.log('🎯 TrackId set for streaming:', currentTrackId)
      let fullContent = ''
      
      const includeReasoning = options?.includeReasoning ?? false
      const webSearch = options?.webSearch ?? false
      // IMPORTANT: Only pass modelId if explicitly provided (e.g., "Again" function)
      // For normal requests, let backend do classification/sorting to determine the right handler
      const finalModelId = options?.modelId // Don't fallback to current model!
      const fileIds = options?.fileIds || [] // Array of fileIds
      
      console.log('🚀 Streaming with options:', { includeReasoning, webSearch, modelId: finalModelId, fileIds, fileCount: fileIds.length })
      
      const stopStreaming = chatApi.streamMessage(
        userId,
        userMessage,
        trackId,
        chatId,
        (data) => {
          // CRITICAL: Check abort signal at the very beginning
          if (streamingAbortController?.signal.aborted) {
            console.log('⏹️ Ignoring chunk - streaming aborted')
            return
          }

          // Handle different status events for UI feedback
          if (data.status === 'started') {
            processingStatus.value = 'started'
            processingMetadata.value = {}
          } else if (data.status === 'preprocessing') {
            processingStatus.value = 'preprocessing'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'analyzing') {
            // Analyzing phase (e.g., understanding media generation request)
            processingStatus.value = 'analyzing'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'classifying') {
            processingStatus.value = 'classifying'
            processingMetadata.value = data.metadata || {}
            
            // Update message with sorting model from backend (instead of store model)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && data.metadata) {
              if (data.metadata.provider) {
                message.provider = data.metadata.provider
              }
              if (data.metadata.model_name) {
                message.modelLabel = data.metadata.model_name
              }
            }
          } else if (data.status === 'classified') {
            const meta = data.metadata || {}
            processingMetadata.value = meta
            processingStatus.value = 'classified'
          } else if (data.status === 'searching') {
            processingStatus.value = 'searching'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'search_complete') {
            processingStatus.value = 'search_complete'
            processingMetadata.value = data.metadata || {}
          } else if (data.status === 'generating') {
            processingStatus.value = 'generating'
            // Use custom message from backend if available, otherwise default
            processingMetadata.value = { 
              customMessage: data.message || undefined,
              ...(data.metadata || {})
            }
            
            // Check if this is a file generation (backend sends 'Datei wird generiert...')
            if (data.message && (data.message.includes('Datei') || data.message.includes('file'))) {
              processingStatus.value = 'generating_file'
            }
            
            // Update message with real model from backend (instead of store model)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && data.metadata) {
              if (data.metadata.provider) {
                message.provider = data.metadata.provider
              }
              if (data.metadata.model_name) {
                message.modelLabel = data.metadata.model_name
              }
            }
          } else if (data.status === 'processing') {
            // Processing/routing messages - improved logging
            if (data.message && !data.message.includes('image_generation')) {
            console.log('Processing:', data.message)
            } else {
              // Generic routing message, suppress spam
              console.log('Processing: Routing to handler')
            }
          } else if (data.status === 'status') {
            // Generic status message
            console.log('Status:', data.message)
          } else if (data.status === 'data' && data.chunk) {
            console.log('📦 Received chunk:', data.chunk.substring(0, 20) + '...')
            
            if (processingStatus.value) {
              processingStatus.value = ''
              processingMetadata.value = {}
            }
            
            // AI gibt nur TEXT zurück (keine JSON!)
            fullContent += data.chunk
            
            // Don't parse JSON during streaming - it's incomplete!
            // We'll parse it at the end in the 'complete' event
            
            // NEW: Detect if this looks like file generation JSON (OfficeM aker)
            // If it starts with { and contains BFILEPATH, don't display it yet
            const trimmedContent = fullContent.trim()
            const looksLikeFileGeneration = 
              (trimmedContent.startsWith('{') || trimmedContent.startsWith('```json\n{') || trimmedContent.startsWith('```\n{')) &&
              (trimmedContent.includes('BFILEPATH') || trimmedContent.includes('"BFILEPATH"'))
            
            if (looksLikeFileGeneration) {
              // Set generating_file status but don't display the JSON content yet
              if (processingStatus.value !== 'generating_file') {
                processingStatus.value = 'generating_file'
                processingMetadata.value = { customMessage: 'Erstelle Datei...' }
              }
              
              // Don't update message parts yet - wait for backend to process
              console.log('📄 Detected file generation JSON, waiting for backend processing...')
              
              // Set message parts to EMPTY to hide JSON during generation
              const message = historyStore.messages.find(m => m.id === messageId)
              if (message) {
                message.parts = []  // Clear parts completely during file generation
              }
              
              return // Skip normal parsing
            }
            
            // Extrahiere thinking blocks und content separat
            const thinkingMatches = fullContent.match(/<think>([\s\S]*?)(<\/think>|$)/g)
            const thinkingParts: any[] = []
            
            if (thinkingMatches) {
              thinkingMatches.forEach(match => {
                const content = match.replace(/<think>|<\/think>/g, '').trim()
                if (content) {
                  thinkingParts.push({ type: 'thinking', content })
                }
              })
            }
            
            // Display content OHNE <think> blocks (RAW - will be parsed on complete)
            const displayContent = fullContent.replace(/<think>[\s\S]*?<\/think>/g, '').trim()
            
            // Parse für code blocks, etc.
            const parsed = parseAIResponse(displayContent)
            
            // Update message
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              const newParts = [...thinkingParts]
              
              parsed.parts.forEach(part => {
                if (part.type === 'text') {
                  newParts.push({ type: 'text', content: part.content })
                } else if (part.type === 'code' || part.type === 'json') {
                  newParts.push({
                    type: 'code',
                    content: part.content,
                    language: part.language
                  })
                } else if (part.type === 'links' && part.links) {
                  newParts.push({
                    type: 'links',
                    items: part.links.map(l => {
                      try {
                        return {
                          title: l.title,
                          url: l.url,
                          desc: l.description,
                          host: new URL(l.url).hostname
                        }
                      } catch {
                        return {
                          title: l.title,
                          url: l.url,
                          desc: l.description,
                          host: l.url
                        }
                      }
                    })
                  })
                }
              })
              
              message.parts = newParts
            }
          } else if (data.status === 'reasoning' && data.chunk) {
            // Reasoning chunks from OpenAI o-series / GPT-5 models
            console.log('🧠 Received reasoning chunk:', data.chunk.substring(0, 50) + '...')
            
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              // Find existing reasoning part or create new one
              let reasoningPart = message.parts.find(p => p.type === 'thinking' && p.isStreaming)
              
              if (!reasoningPart) {
                // Create new reasoning part at the beginning
                reasoningPart = {
                  type: 'thinking',
                  content: '',
                  isStreaming: true
                }
                message.parts.unshift(reasoningPart)
              }
              
              // Append reasoning content
              reasoningPart.content += data.chunk
            }
          } else if (data.status === 'file') {
            // Handle file attachments (images, videos, audio, etc.)
            console.log('📎 File received:', data.type, data.url)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              // Add file part based on type - normalize URLs to absolute
              const absoluteUrl = normalizeMediaUrl(data.url)
              if (data.type === 'image') {
                message.parts.push({ type: 'image', url: absoluteUrl })
              } else if (data.type === 'video') {
                message.parts.push({ type: 'video', url: absoluteUrl })
              } else if (data.type === 'audio') {
                message.parts.push({ type: 'audio', url: absoluteUrl })
              }
            }
          } else if (data.status === 'links') {
            // Handle web search results
            console.log('🔗 Links received:', data.links)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && data.links) {
              message.parts.push({
                type: 'links',
                items: data.links.map((l: any) => {
                  try {
                    return {
                      title: l.title || l.url,
                      url: l.url,
                      desc: l.description,
                      host: new URL(l.url).hostname
                    }
                  } catch {
                    return {
                      title: l.title || l.url,
                      url: l.url,
                      desc: l.description,
                      host: l.url
                    }
                  }
                })
              })
            }
          } else if (data.status === 'complete') {
            console.log('✅ Complete event received:', data)
            
            // Clear processing status
            processingStatus.value = ''
            processingMetadata.value = {}
            
            // Update message metadata
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              console.log('📍 Found message to update:', message.id)
              
              // ✨ NEW: Handle generated file from backend
              if (data.generatedFile) {
                console.log('📄 Generated file received from backend:', data.generatedFile)
                
                // Add file to message FIRST
                if (!message.files) {
                  message.files = []
                }
                
                const fileData = {
                  id: data.generatedFile.id,
                  fileName: data.generatedFile.filename,
                  filename: data.generatedFile.filename, // Both camelCase and lowercase for compatibility
                  filePath: data.generatedFile.path,
                  fileSize: data.generatedFile.size,
                  fileType: data.generatedFile.type,
                  fileMime: data.generatedFile.mime
                }
                
                message.files.push(fileData)

                console.log('📄 File attached to message:', message.files)

                // Replace JSON content or special markers with translated message
                const hasJsonOrMarker = message.parts.length === 0 ||
                    (message.parts[0].type === 'code' && message.parts[0].content?.includes('BFILEPATH')) ||
                    (message.parts[0].type === 'text' && message.parts[0].content?.includes('__FILE_GENERATED__'))

                if (hasJsonOrMarker) {
                  // Use translation with filename parameter
                  const translatedMessage = t('message.fileGenerated', { filename: data.generatedFile.filename })
                  message.parts = [{
                    type: 'text',
                    content: translatedMessage
                  }]
                  console.log('📄 Set translated message:', translatedMessage)
                }

                // Force Vue reactivity with multiple strategies
                nextTick(() => {
                  // Strategy 1: Update the message object with a new id to force key-based re-render
                  const messageIndex = historyStore.messages.findIndex(m => m.id === message.id)
                  if (messageIndex !== -1) {
                    // Create completely new message object
                    // FIXME: This entire block is cargo-cult reactivity code - message is already a store reference,
                    // Vue 3 Proxy detects mutations automatically. The ternary is unnecessary (files already mutated above),
                    // and spreading parts/files just wastes CPU creating shallow copies of already-mutated arrays.
                    const updatedMessage = {
                      ...message,
                      files: message.files ? [...message.files] : undefined,
                      parts: [...message.parts],
                      timestamp: new Date(message.timestamp)
                    }

                    // Replace in store
                    historyStore.messages.splice(messageIndex, 1, updatedMessage)

                    console.log('📄 Message updated with new references')
                  }
                })
              }
              
              // ✨ NEW: Parse JSON response if AI responded in JSON format
              // NOTE: againData is now generated by frontend in ChatMessage.vue
              // based on available models and message type (image/video/audio)
              
              if (data.messageId) {
                console.log('🆔 Setting backendMessageId:', data.messageId)
                message.backendMessageId = data.messageId
              }
              
              // Store search results if provided
              if (data.searchResults && Array.isArray(data.searchResults) && data.searchResults.length > 0) {
                console.log('🔍 Setting searchResults:', data.searchResults.length, 'results')
                message.searchResults = data.searchResults
                
                // Also set webSearch metadata for assistant message
                message.webSearch = {
                  query: data.searchResults[0]?.query || '',
                  resultsCount: data.searchResults.length
                }
              }
              
              // Update provider and model from backend metadata
              if (data.provider) {
                message.provider = data.provider
                console.log('🏢 Updated provider:', data.provider)
              }
              if (data.model) {
                message.modelLabel = data.model
                console.log('🤖 Updated model label:', data.model)
              }
              
              // Store topic from classification
              if (data.topic) {
                message.topic = data.topic
                console.log('🏷️ Updated topic:', data.topic)
              }
              
              // Mark reasoning parts as complete (remove streaming flag)
              message.parts.forEach(part => {
                if (part.type === 'thinking' && part.isStreaming) {
                  delete part.isStreaming
                }
              })
            } else {
              console.error('❌ Could not find message with id:', messageId)
            }
            
            // Generate chat title from first message
            generateChatTitleFromFirstMessage(userMessage)
            
            historyStore.finishStreamingMessage(messageId)
            
            // Clean up streaming resources after successful completion
            console.log('🧹 Cleaning up after successful stream completion')
            streamingAbortController = null
            stopStreamingFn = null
            currentTrackId = undefined
          } else if (data.status === 'error') {
            const errorMsg = data.error || data.message || 'Unknown error'
            console.error('Error:', errorMsg, data)
            processingStatus.value = ''
            processingMetadata.value = {}

            // Handle rate limit errors with modal
            if (errorMsg.toLowerCase().includes('rate limit')) {
              historyStore.removeMessage(messageId)
              checkAndShowLimit({
                allowed: false,
                limitType: data.limit_type || 'lifetime',
                actionType: data.action_type || 'MESSAGES',
                used: data.used || 0,
                limit: data.limit || 0,
                remaining: data.remaining || 0,
                resetTime: data.reset_at || null,
                userLevel: data.user_level || authStore.user?.level || 'NEW',
                phoneVerified: data.phone_verified || false
              })
              return
            }
            
            // Format user-friendly error message with installation instructions
            let displayError = '## ⚠️ ' + errorMsg + '\n\n'
            
            if (data.install_command && data.suggested_models) {
              displayError += '### 📦 ' + t('aiProvider.error.noModelTitle') + '\n\n'
              
              if (data.suggested_models.quick) {
                displayError += '**' + t('aiProvider.error.quickModels') + ':**\n'
                data.suggested_models.quick.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }
              
              if (data.suggested_models.medium) {
                displayError += '**' + t('aiProvider.error.mediumModels') + ':**\n'
                data.suggested_models.medium.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }
              
              if (data.suggested_models.large) {
                displayError += '**' + t('aiProvider.error.largeModels') + ':**\n'
                data.suggested_models.large.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }
              
              displayError += '### 💡 ' + t('aiProvider.error.exampleCommand') + '\n\n'
              displayError += '```bash\n' + data.install_command + '\n```\n\n'
              displayError += '*' + t('aiProvider.error.restartNote') + '*'
            }
            
            // Always show error as message (not in streaming message, but as new assistant message)
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message && message.parts.length > 0) {
              // If there's already content, finish it and create a new error message
              historyStore.finishStreamingMessage(messageId)
            } else {
              // No content yet, replace with error message
              historyStore.updateStreamingMessage(messageId, displayError)
              historyStore.finishStreamingMessage(messageId)
            }
            
            // Clean up streaming resources after error
            console.log('🧹 Cleaning up after streaming error')
            streamingAbortController = null
            stopStreamingFn = null
            currentTrackId = undefined
          } else {
            console.log('⚠️ Unknown status:', data.status, data)
          }
        },
        includeReasoning,
        webSearch,
        finalModelId,
        fileIds // Pass array of fileIds
      )
      
      // Store EventSource cleanup function globally
      stopStreamingFn = stopStreaming
      
      // Store cleanup function
      streamingAbortController.signal.addEventListener('abort', () => {
        stopStreaming()
        stopStreamingFn = null
      })
    }
  } catch (error) {
    console.error('❌ Streaming error:', error)
    historyStore.updateStreamingMessage(messageId, 'Sorry, an error occurred.')
    historyStore.finishStreamingMessage(messageId)
    // Clean up on error
    streamingAbortController = null
    stopStreamingFn = null
    currentTrackId = undefined
  }
  // NOTE: Don't clean up in finally block! The streaming is async and still running.
  // Cleanup happens in the 'complete' event handler or in handleStopStreaming()
}

const handleStopStreaming = async () => {
  console.log('🛑 Stop streaming requested', { 
    hasAbortController: !!streamingAbortController, 
    hasStopFn: !!stopStreamingFn,
    currentTrackId,
    typeOfTrackId: typeof currentTrackId,
    isUndefined: currentTrackId === undefined,
    isNull: currentTrackId === null,
    isFalsy: !currentTrackId
  })
  
  // CRITICAL: Abort signal FIRST to prevent any further chunk processing
  if (streamingAbortController) {
    streamingAbortController.abort()
    console.log('✅ Abort signal sent')
  }
  
  // Close the EventSource connection IMMEDIATELY
  if (stopStreamingFn) {
    stopStreamingFn()
    console.log('✅ EventSource closed')
    stopStreamingFn = null
  }
  
  // Notify backend to stop streaming
  if (currentTrackId) {
    console.log('📤 Sending stop request to backend with trackId:', currentTrackId)
    try {
      await chatApi.stopStream(currentTrackId)
      console.log('✅ Backend notified to stop streaming')
    } catch (error) {
      console.error('❌ Failed to notify backend:', error)
    }
  } else {
    console.warn('⚠️ No currentTrackId - skipping backend notification')
  }
  
  // Clear processing status
  processingStatus.value = ''
  processingMetadata.value = {}
  
  // Finish any streaming message and add cancellation notice
  const streamingMessage = historyStore.messages.find(m => m.isStreaming)
  if (streamingMessage) {
    const cancelMessage = t('message.cancelledByUser')
    
    // Collect the current content for saving to backend
    let finalContent = ''
    
    // Add cancellation message if there's no content yet
    if (streamingMessage.parts.length === 0 || 
        (streamingMessage.parts.length === 1 && streamingMessage.parts[0].content === '')) {
      historyStore.updateStreamingMessage(streamingMessage.id, cancelMessage)
      finalContent = cancelMessage
    } else {
      // Collect existing text content
      finalContent = streamingMessage.parts
        .filter(p => p.type === 'text')
        .map(p => p.content || '')
        .join('\n\n')
      
      // Append cancellation notice to existing content
      const lastPart = streamingMessage.parts[streamingMessage.parts.length - 1]
      if (lastPart && lastPart.type === 'text') {
        lastPart.content += `\n\n${cancelMessage}`
      } else {
        streamingMessage.parts.push({
          type: 'text',
          content: `\n\n${cancelMessage}`
        })
      }
      
      finalContent += `\n\n${cancelMessage}`
    }
    
    historyStore.finishStreamingMessage(streamingMessage.id)
    console.log('✅ Streaming message finished with cancellation notice')
    
    // Save the cancelled message to backend so it persists after refresh
    // IMPORTANT: Use the trackId and chatId BEFORE clearing them
    const trackIdToSave = currentTrackId
    const chatIdToSave = chatsStore.activeChatId
    
    if (trackIdToSave && chatIdToSave) {
      console.log('📤 Saving cancelled message to backend', { trackId: trackIdToSave, chatId: chatIdToSave })
      // Save and update message with backend ID, pass current metadata
      const metadata = {
        provider: streamingMessage.provider,
        model: streamingMessage.modelLabel,
        topic: streamingMessage.topic
      }
      saveCancelledMessageToBackend(trackIdToSave, chatIdToSave, finalContent, streamingMessage.id, metadata)
        .catch(error => console.error('❌ Failed to save cancelled message to backend:', error))
    } else {
      console.warn('⚠️ Cannot save cancelled message - missing trackId or chatId', { trackIdToSave, chatIdToSave })
    }
  }
  
  // Clear references AFTER saving
  streamingAbortController = null
  currentTrackId = undefined
}

// Helper function to save cancelled message to backend
async function saveCancelledMessageToBackend(
  trackId: number, 
  chatId: number, 
  content: string, 
  messageId: string,
  metadata?: { provider?: string, model?: string, topic?: string }
) {
  console.log('📡 saveCancelledMessageToBackend called', { trackId, chatId, contentLength: content.length, messageId, metadata })

  try {
    const data = await httpClient<any>('/api/v1/messages/save-cancelled', {
      method: 'POST',
      body: JSON.stringify({
        trackId,
        chatId,
        content,
        provider: metadata?.provider,
        model: metadata?.model,
        topic: metadata?.topic
      })
    })

    console.log('✅ Cancelled message saved to backend:', data)

    // Update the message with backend message ID and metadata so the footer buttons appear
    const message = historyStore.messages.find(m => m.id === messageId)
    if (message && data.messageId) {
      message.backendMessageId = data.messageId

      // Update metadata if provided by backend
      if (data.topic) {
        message.topic = data.topic
      }
      if (data.provider) {
        message.provider = data.provider
      }
      if (data.model) {
        message.modelLabel = data.model
      }

      // Set aiModels object for proper display of model badges
      if (data.provider && data.model) {
        message.aiModels = {
          chat: {
            provider: data.provider,
            model: data.model,
            model_id: null // We don't have the model_id from cancelled message
          }
        }
      }

      console.log('✅ Updated message with metadata:', {
        backendMessageId: data.messageId,
        topic: data.topic,
        provider: data.provider,
        model: data.model,
        aiModels: message.aiModels
      })
    }
  } catch (error) {
    console.error('❌ Error saving cancelled message:', error)
  }
}

// Handle "Again" with specific model from backend
const handleAgain = async (backendMessageId: number, modelId?: number) => {
  console.log('🔄 Handle Again:', backendMessageId, modelId)
  
  // Find the original user message for this assistant response
  const assistantMessage = historyStore.messages.find(
    m => m.backendMessageId === backendMessageId && m.role === 'assistant'
  )
  
  if (!assistantMessage) {
    console.error('❌ Could not find assistant message with backendMessageId:', backendMessageId)
    return
  }
  
  // Mark previous response as superseded
  historyStore.markSuperseded(assistantMessage.id)
  
  // Find the user message (should be right before the assistant message)
  const messageIndex = historyStore.messages.indexOf(assistantMessage)
  const userMessage = messageIndex > 0 ? historyStore.messages[messageIndex - 1] : null
  
  if (!userMessage || userMessage.role !== 'user') {
    console.error('❌ Could not find user message before assistant message')
    return
  }
  
  // Extract user text from parts
  const userText = userMessage.parts
    .filter(p => p.type === 'text')
    .map(p => p.content)
    .join('\n')
  
  if (!userText) {
    console.error('❌ No text found in user message')
    return
  }
  
  console.log('✅ Re-sending user message:', userText.substring(0, 50) + '...')
  
  // Re-send the user message with the selected model
  // This will trigger normal streaming flow
  await handleSendMessage(userText, { modelId })
}

const handleRegenerate = async (message: Message, modelOption: ModelOption) => {
  console.log('Regenerating with model:', modelOption)
  
  streamingAbortController = new AbortController()
  
  // Mark the current message as superseded
  historyStore.markSuperseded(message.id)
  
  // Find the original user message that triggered this assistant response
  const messageIndex = historyStore.messages.findIndex(m => m.id === message.id)
  if (messageIndex > 0) {
    const previousMessage = historyStore.messages[messageIndex - 1]
    if (previousMessage.role === 'user') {
      // Extract text content from user message
      const content = previousMessage.parts
        .filter(part => part.type === 'text')
        .map(part => part.content || '')
        .join('\n')

      // Re-send the user message with the selected model
      // This will trigger normal streaming flow
      await handleSendMessage(content, { modelId: modelOption.id })
    }
  }
}
</script>
