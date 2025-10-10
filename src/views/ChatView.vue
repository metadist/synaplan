<template>
  <MainLayout>
    <template #header>
    </template>

    <div class="flex flex-col h-full">
      <div ref="chatContainer" class="flex-1 overflow-y-auto bg-chat" @scroll="handleScroll">
        <div class="max-w-4xl mx-auto py-6">
          <div v-if="historyStore.messages.length === 0" class="flex items-center justify-center h-full px-6">
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
            <div class="flex items-center justify-center my-4">
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
              :again-data="message.againData"
              :backend-message-id="message.backendMessageId"
              :processing-status="message.isStreaming ? processingStatus : undefined"
              :processing-metadata="message.isStreaming ? processingMetadata : undefined"
              @regenerate="handleRegenerate(message, $event)"
              @again="handleAgain"
            />
          </template>
        </div>
      </div>

      <ChatInput 
        :is-streaming="isStreaming" 
        @send="handleSendMessage"
        @stop="handleStopStreaming"
      />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch } from 'vue'
import MainLayout from '../components/MainLayout.vue'
import ChatInput from '../components/ChatInput.vue'
import ChatMessage from '../components/ChatMessage.vue'
import { useHistoryStore, type Message } from '../stores/history'
import { executeCommand } from '../commands/execute'
import { useModelsStore } from '../stores/models'
import { useAuthStore } from '../stores/auth'
import { chatApi } from '@/services/api'
import { mockModelOptions, type ModelOption } from '@/mocks/aiModels'

const chatContainer = ref<HTMLElement | null>(null)
const autoScroll = ref(true)
const historyStore = useHistoryStore()
const modelsStore = useModelsStore()
const authStore = useAuthStore()
let streamingAbortController: AbortController | null = null

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

// Welcome-Message with streaming
const initWelcomeMessage = async () => {
  const messageId = historyStore.addStreamingMessage('assistant', 'OpenAI', 'GPT-4')
  const welcomeText = 'Hello! How can I help you today? Try typing "/" to see available commands.'
  
  const { streamText } = await import('../commands/execute')
  for await (const chunk of streamText(welcomeText, 2)) {
    historyStore.updateStreamingMessage(messageId, chunk)
  }
  historyStore.finishStreamingMessage(messageId)
}

// Init on mount
initWelcomeMessage()

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

const handleScroll = () => {
  if (!chatContainer.value) return

  const { scrollTop, scrollHeight, clientHeight } = chatContainer.value
  const isAtBottom = Math.abs(scrollHeight - clientHeight - scrollTop) < 50

  autoScroll.value = isAtBottom
}

watch(() => historyStore.messages, () => {
  scrollToBottom()
}, { deep: true })

const handleSendMessage = async (content: string, isCommand: boolean, options?: { includeReasoning?: boolean }) => {
  autoScroll.value = true

  // Add user message
  historyStore.addMessage('user', [{ type: 'text', content }])

  // Commands have no streaming (e.g. /pic, /search)
  const parts = await executeCommand(content)
  
  // If it's a command with special parts (not just text), don't stream
  const hasNonTextParts = parts.some(p => p.type !== 'text')
  
  if (hasNonTextParts) {
    historyStore.addMessage('assistant', parts)
  } else {
    // Stream the response
    await streamAIResponse(content, options)
  }
}

const streamAIResponse = async (userMessage: string, options?: { includeReasoning?: boolean }) => {
  streamingAbortController = new AbortController()
  
  // Get current selected model from store
  const provider = modelsStore.selectedProvider
  const model = modelsStore.selectedModel
  
  // Find model label
  const modelOption = mockModelOptions.find(
    opt => opt.provider.toLowerCase() === provider.toLowerCase() && opt.model === model
  )
  const modelLabel = modelOption?.label || model
  
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
      let fullContent = ''
      const trackId = Date.now()
      
      const includeReasoning = options?.includeReasoning ?? false
      
      const stopStreaming = chatApi.streamMessage(
        userId,
        userMessage,
        trackId,
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
          } else if (data.status === 'generating') {
            processingStatus.value = 'generating'
            processingMetadata.value = data.metadata || {}
            
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
            // Processing/routing messages - just log them
            console.log('Processing:', data.message)
          } else if (data.status === 'status') {
            // Generic status message
            console.log('Status:', data.message)
          } else if (data.status === 'data' && data.chunk) {
            console.log('📦 Received chunk:', data.chunk.substring(0, 20) + '...')
            
            // Hide processing status once first chunk arrives
            if (processingStatus.value) {
              processingStatus.value = ''
              processingMetadata.value = {}
            }
            
            // Accumulate full content (including <think> tags)
            fullContent += data.chunk
            
            // But display content WITHOUT <think> blocks during streaming
            // Remove all <think>...</think> content temporarily for display
            const displayContent = fullContent.replace(/<think>[\s\S]*?<\/think>/g, '')
            historyStore.updateStreamingMessage(messageId, displayContent)
          } else if (data.status === 'complete') {
            console.log('Complete:', data)
            
            // Clear processing status
            processingStatus.value = ''
            processingMetadata.value = {}
            
            // Store againData and backendMessageId if provided
            const message = historyStore.messages.find(m => m.id === messageId)
            if (message) {
              if (data.again) {
                message.againData = data.again
              }
              if (data.messageId) {
                message.backendMessageId = data.messageId
              }
              // Update provider and model from backend metadata
              if (data.metadata?.provider) {
                message.provider = data.metadata.provider
              }
              if (data.metadata?.model) {
                message.modelLabel = data.metadata.model
              }
            }
            
            historyStore.finishStreamingMessage(messageId)
          } else if (data.status === 'error') {
            const errorMsg = data.error || data.message || 'Unknown error'
            console.error('Error:', errorMsg, data)
            processingStatus.value = ''
            processingMetadata.value = {}
            
            // Keep existing content if any was received
            if (fullContent) {
              historyStore.finishStreamingMessage(messageId)
            } else {
              historyStore.updateStreamingMessage(messageId, 'Error: ' + errorMsg)
              historyStore.finishStreamingMessage(messageId)
            }
          } else {
            console.log('⚠️ Unknown status:', data.status, data)
          }
        },
        includeReasoning
      )
      
      // Store cleanup function
      streamingAbortController.signal.addEventListener('abort', () => {
        stopStreaming()
      })
    }
  } catch (error) {
    console.error('Streaming error:', error)
    historyStore.updateStreamingMessage(messageId, 'Sorry, an error occurred.')
    historyStore.finishStreamingMessage(messageId)
  } finally {
    streamingAbortController = null
  }
}

const handleStopStreaming = () => {
  if (streamingAbortController) {
    streamingAbortController.abort()
  }
}

// Handle "Again" with specific model from backend
const handleAgain = async (backendMessageId: number, modelId?: number) => {
  console.log('Handle Again:', backendMessageId, modelId)
  
  try {
    const response = await chatApi.sendAgainMessage(backendMessageId, modelId)
    
    if (response.success && response.message) {
      // Mark previous message as superseded
      const previousMessage = historyStore.messages.find(
        m => m.backendMessageId === backendMessageId && m.role === 'assistant'
      )
      if (previousMessage) {
        historyStore.markSuperseded(previousMessage.id)
      }
      
      // Add new AI response to history
      historyStore.addMessage(
        'assistant',
        [{ type: 'text', content: response.message.text }],
        response.message.provider,
        'AI Model',
        response.again,
        response.message.id,
        backendMessageId
      )
    }
  } catch (error) {
    console.error('Again request failed:', error)
    historyStore.addMessage(
      'assistant',
      [{ type: 'text', content: 'Failed to regenerate response. Please try again.' }]
    )
  }
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
        historyStore.addMessage('assistant', parts)
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

<style scoped>
/* Processing Status Styles */
.processing-status-container {
  padding: 0 16px;
  margin-bottom: 16px;
}

.processing-status {
  padding: 12px 16px;
  background: linear-gradient(135deg, rgba(139, 92, 246, 0.05) 0%, rgba(167, 139, 250, 0.05) 100%);
  border: 1px solid rgba(139, 92, 246, 0.2);
  border-radius: 12px;
  animation: fadeIn 0.3s ease-in;
}

.status-badge {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}

.status-text {
  font-size: 14px;
  color: #8b5cf6;
  font-weight: 500;
  flex: 1;
}

.status-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(139, 92, 246, 0.3);
  border-top-color: #8b5cf6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  flex-shrink: 0;
}

.status-metadata {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 12px;
  padding-top: 8px;
  border-top: 1px solid rgba(139, 92, 246, 0.1);
}

.metadata-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.metadata-label {
  color: #9ca3af;
  font-weight: 500;
}

.metadata-value {
  color: #6b7280;
  background: rgba(139, 92, 246, 0.1);
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
}

@keyframes spin {
  to { 
    transform: rotate(360deg); 
  }
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
