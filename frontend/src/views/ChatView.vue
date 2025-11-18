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
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import ChatInput from '@/components/ChatInput.vue'
import ChatMessage from '@/components/ChatMessage.vue'
import { useHistoryStore, type Message } from '@/stores/history'
import { useChatsStore } from '@/stores/chats'
import { executeCommand } from '@/commands/execute'
import { useModelsStore } from '@/stores/models'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import { chatApi } from '@/services/api'
import { mockModelOptions, type ModelOption } from '@/mocks/aiModels'
import { parseAIResponse } from '@/utils/responseParser'
import { normalizeMediaUrl } from '@/utils/urlHelper'

const { t } = useI18n()

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

  // Add user message with files and webSearch info
  historyStore.addMessage(
    'user', 
    [{ type: 'text', content }], 
    files, 
    undefined, // provider 
    undefined, // modelLabel
    undefined, // againData
    undefined, // backendMessageId
    undefined, // originalMessageId
    webSearchData // webSearch
  )

  // Commands have no streaming (e.g. /pic, /search)
  const parts = await executeCommand(content)
  
  // If it's a command with special parts (not just text), don't stream
  const hasNonTextParts = parts.some(p => p.type !== 'text')
  
  if (hasNonTextParts) {
    historyStore.addMessage('assistant', parts, undefined)
  } else {
    // Stream the response
    await streamAIResponse(content, options)
  }
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
      // Generate Mock-Response for development
      const { generateMockResponse, streamText } = await import('../commands/execute')
      const fullResponse = generateMockResponse(userMessage)
      
      // Stream the response
      for await (const chunk of streamText(fullResponse)) {
        if (streamingAbortController.signal.aborted) {
          break
        }
        historyStore.updateStreamingMessage(messageId, chunk)
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
          if (streamingAbortController?.signal.aborted) {
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
          } else if (data.status === 'error') {
            const errorMsg = data.error || data.message || 'Unknown error'
            console.error('Error:', errorMsg, data)
            processingStatus.value = ''
            processingMetadata.value = {}
            
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
    console.error('Streaming error:', error)
    historyStore.updateStreamingMessage(messageId, 'Sorry, an error occurred.')
    historyStore.finishStreamingMessage(messageId)
  } finally {
    streamingAbortController = null
    stopStreamingFn = null
  }
}

const handleStopStreaming = () => {
  console.log('🛑 Stop streaming requested')
  
  // Abort the AbortController signal
  if (streamingAbortController) {
    streamingAbortController.abort()
    streamingAbortController = null
  }
  
  // Close the EventSource connection
  if (stopStreamingFn) {
    stopStreamingFn()
    stopStreamingFn = null
  }
  
  // Clear processing status
  processingStatus.value = ''
  processingMetadata.value = {}
  
  // Finish any streaming message
  const streamingMessage = historyStore.messages.find(m => m.isStreaming)
  if (streamingMessage) {
    historyStore.finishStreamingMessage(streamingMessage.id)
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
      
      // Check if it's a command
      const parts = await executeCommand(content)
      const hasNonTextParts = parts.some(p => p.type !== 'text')
      
      if (hasNonTextParts) {
        historyStore.addMessage('assistant', parts, undefined)
        streamingAbortController = null
      } else {
        try {
          // Stream the response again with selected model
          const provider = modelOption.provider
          const modelLabel = modelOption.label
          
          // Create empty streaming message with provider info
          const messageId = historyStore.addStreamingMessage('assistant', provider, modelLabel)
          
          // Generate mock response
          const { generateMockResponse, streamText } = await import('../commands/execute')
          const fullResponse = generateMockResponse(content)
          
          // Stream the response
          for await (const chunk of streamText(fullResponse)) {
            if (streamingAbortController.signal.aborted) {
              break
            }
            historyStore.updateStreamingMessage(messageId, chunk)
          }
          
          // Mark as finished
          historyStore.finishStreamingMessage(messageId)
        } catch (error) {
          console.error('Regenerate error:', error)
        } finally {
          streamingAbortController = null
        }
      }
    }
  }
}
</script>
