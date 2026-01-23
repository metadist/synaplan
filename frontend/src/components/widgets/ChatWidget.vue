<template>
  <div
    :class="[isPreview ? 'absolute' : 'fixed', 'z-[9999]', positionClass]"
    data-testid="comp-chat-widget"
    style="pointer-events: auto"
  >
    <!-- Chat Button - absolute positioned to avoid layout shift -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="scale-0 opacity-0"
      enter-to-class="scale-100 opacity-100"
      leave-active-class="transition-all duration-150 ease-in"
      leave-from-class="scale-100 opacity-100"
      leave-to-class="scale-0 opacity-0"
    >
      <div v-if="!isOpen && !hideButton" class="absolute bottom-0 right-0">
        <button
          :style="{ backgroundColor: primaryColor }"
          class="w-16 h-16 rounded-full shadow-2xl hover:scale-110 transition-transform flex items-center justify-center group"
          aria-label="Open chat"
          data-testid="btn-open"
          @click="toggleChat"
        >
          <img
            v-if="buttonIconUrl"
            :src="buttonIconUrl"
            alt="Chat"
            class="w-9 h-9 object-contain"
          />
          <component
            :is="getButtonIconComponent"
            v-else
            :style="{ color: iconColor }"
            class="w-8 h-8"
          />
          <span
            v-if="unreadCount > 0"
            class="absolute -top-1 -right-1 w-6 h-6 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center"
          >
            {{ unreadCount }}
          </span>
        </button>
      </div>
    </Transition>

    <!-- Chat Window -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div
        v-if="isOpen"
        :class="['flex flex-col overflow-hidden shadow-2xl', ...chatWindowClasses]"
        :style="{
          backgroundColor: widgetTheme === 'dark' ? '#1a1a1a' : '#ffffff',
          ...chatWindowStyle,
        }"
        data-testid="section-chat-window"
      >
        <!-- Header -->
        <div
          :style="{ backgroundColor: primaryColor }"
          class="flex items-center justify-between px-4 py-3"
          :class="{
            'pt-[calc(env(safe-area-inset-top,0px)+12px)]': isMobile && !isPreview,
          }"
        >
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
              <ChatBubbleLeftRightIcon class="w-6 h-6 text-white" />
            </div>
            <div>
              <h3 class="text-white font-semibold">{{ widgetTitle || $t('widget.title') }}</h3>
              <p class="text-white/80 text-xs">{{ $t('widget.subtitle') }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              :aria-label="widgetTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
              data-testid="btn-theme"
              @click="toggleTheme"
            >
              <SunIcon v-if="widgetTheme === 'dark'" class="w-5 h-5 text-white" />
              <MoonIcon v-else class="w-5 h-5 text-white" />
            </button>
            <button
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              aria-label="Close chat"
              data-testid="btn-close"
              @click="toggleChat"
            >
              <XMarkIcon class="w-5 h-5 text-white" />
            </button>
          </div>
        </div>

        <!-- Messages -->
        <div
          ref="messagesContainer"
          class="flex-1 overflow-y-auto p-4 space-y-3"
          :style="{
            backgroundColor: widgetTheme === 'dark' ? '#1a1a1a' : '#ffffff',
          }"
          data-testid="section-messages"
          @click="handleMessagesClick"
        >
          <div
            v-for="message in messages"
            :key="message.id"
            :class="['flex', message.role === 'user' ? 'justify-end' : 'justify-start']"
          >
            <div
              :class="['max-w-[80%] rounded-2xl px-4 py-2', message.role === 'user' ? '' : '']"
              :style="
                message.role === 'user'
                  ? { backgroundColor: primaryColor, color: iconColor }
                  : { backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }
              "
            >
              <template v-if="message.type === 'text'">
                <div
                  v-if="message.role === 'assistant' && message.content === '' && isTyping"
                  class="space-y-2"
                >
                  <div
                    class="h-3 w-32 rounded animate-pulse"
                    :class="widgetTheme === 'dark' ? 'bg-white/20' : 'bg-black/10'"
                  />
                  <div
                    class="h-3 w-24 rounded animate-pulse"
                    :class="widgetTheme === 'dark' ? 'bg-white/15' : 'bg-black/5'"
                  />
                </div>
                <div
                  v-else
                  class="text-sm break-words markdown-content"
                  :style="{
                    color:
                      message.role === 'user'
                        ? iconColor
                        : widgetTheme === 'dark'
                          ? '#e5e5e5'
                          : '#1f2937',
                  }"
                  v-html="renderMessageContent(message.content)"
                ></div>
              </template>
              <div v-else-if="message.type === 'file'" class="flex items-center gap-2">
                <DocumentIcon
                  class="w-5 h-5"
                  :style="{
                    color:
                      message.role === 'user'
                        ? iconColor
                        : widgetTheme === 'dark'
                          ? '#e5e5e5'
                          : '#1f2937',
                  }"
                />
                <span
                  class="text-sm"
                  :style="{
                    color:
                      message.role === 'user'
                        ? iconColor
                        : widgetTheme === 'dark'
                          ? '#e5e5e5'
                          : '#1f2937',
                  }"
                >
                  {{ message.fileName }}
                </span>
              </div>
              <p
                v-if="message.timestamp"
                class="text-xs mt-1 opacity-70"
                :style="{
                  color:
                    message.role === 'user'
                      ? iconColor
                      : widgetTheme === 'dark'
                        ? '#9ca3af'
                        : '#6b7280',
                }"
              >
                {{ formatTime(message.timestamp) }}
              </p>
            </div>
          </div>

          <div v-if="isTyping" class="flex justify-start">
            <div
              class="rounded-2xl px-4 py-3"
              :style="{ backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }"
            >
              <div class="flex gap-1">
                <div
                  class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce"
                  style="animation-delay: 0ms"
                ></div>
                <div
                  class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce"
                  style="animation-delay: 150ms"
                ></div>
                <div
                  class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce"
                  style="animation-delay: 300ms"
                ></div>
              </div>
            </div>
          </div>

          <!-- Limit Warning -->
          <div v-if="showLimitWarning" class="flex justify-center">
            <div
              class="bg-orange-500/10 border border-orange-500/30 rounded-xl px-4 py-3 max-w-[90%]"
            >
              <div class="flex items-start gap-2">
                <ExclamationTriangleIcon class="w-5 h-5 text-orange-500 flex-shrink-0 mt-0.5" />
                <div>
                  <p class="text-sm font-medium txt-primary">{{ $t('widget.limitWarning') }}</p>
                  <p class="text-xs txt-secondary mt-1">
                    {{ $t('widget.limitDetails', { current: messageCount, max: messageLimit }) }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Limit Reached -->
          <div v-if="limitReached" class="flex justify-center">
            <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-4 py-3 max-w-[90%]">
              <div class="flex items-start gap-2">
                <XCircleIcon class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" />
                <div>
                  <p class="text-sm font-medium txt-primary">{{ $t('widget.limitReached') }}</p>
                  <p class="text-xs txt-secondary mt-1">{{ $t('widget.limitReachedDetails') }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Input Area -->
        <div
          class="border-t p-3"
          :style="{ borderColor: widgetTheme === 'dark' ? '#333' : '#e5e7eb' }"
          data-testid="section-input"
        >
          <div
            v-if="fileUploadError"
            class="mb-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg"
          >
            <div class="flex items-start gap-2">
              <XCircleIcon class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-red-600 dark:text-red-400">{{ fileUploadError }}</p>
            </div>
          </div>

          <div
            v-if="allowFileUploads && fileLimitReached"
            class="mb-2 p-2 bg-amber-500/10 border border-amber-500/30 rounded-lg"
          >
            <div class="flex items-start gap-2">
              <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-amber-600 dark:text-amber-400">
                {{ $t('widget.fileUploadLimitReached') }}
              </p>
            </div>
          </div>

          <div
            v-if="selectedFile"
            class="mb-2 flex items-center gap-2 p-2 rounded-lg"
            :style="{ backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }"
          >
            <DocumentIcon
              class="w-5 h-5"
              :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }"
            />
            <span
              class="text-sm flex-1 truncate"
              :style="{ color: widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937' }"
              >{{ selectedFile.name }}</span
            >
            <span
              class="text-xs"
              :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }"
              >{{ formatFileSize(selectedFile.size) }}</span
            >
            <button
              class="w-6 h-6 rounded hover:bg-black/10 dark:hover:bg-white/10 flex items-center justify-center"
              data-testid="btn-remove-file"
              @click="removeFile"
            >
              <XMarkIcon class="w-4 h-4 txt-secondary" />
            </button>
          </div>

          <!-- File Size Error -->
          <div
            v-if="fileSizeError"
            class="mb-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg"
          >
            <div class="flex items-start gap-2">
              <ExclamationTriangleIcon class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-red-600 dark:text-red-400">
                {{ $t('widget.fileTooLarge', { max: maxFileSize }) }}
              </p>
            </div>
          </div>

          <div class="flex items-end gap-2">
            <template v-if="allowFileUploads">
              <input
                ref="fileInput"
                type="file"
                accept="image/*,.pdf,.doc,.docx,.txt"
                class="hidden"
                data-testid="input-file"
                @change="handleFileSelect"
              />
              <button
                :disabled="limitReached || fileLimitReached"
                class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed"
                :aria-label="$t('widget.attachFile')"
                data-testid="btn-attach"
                @click="fileInput?.click()"
              >
                <PaperClipIcon
                  class="w-5 h-5"
                  :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }"
                />
              </button>
            </template>
            <textarea
              v-model="inputMessage"
              :disabled="limitReached"
              :placeholder="
                limitReached ? $t('widget.limitReachedPlaceholder') : $t('widget.placeholder')
              "
              rows="1"
              class="flex-1 px-4 py-2 rounded-lg resize-none focus:outline-none focus:ring-2 disabled:opacity-50 disabled:cursor-not-allowed"
              :style="{
                backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6',
                color: widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937',
                borderColor: primaryColor,
                maxHeight: '120px',
                minHeight: '40px',
              }"
              data-testid="input-message"
              @keydown.enter.exact.prevent="sendMessage"
            />
            <button
              :disabled="!canSend"
              :style="canSend ? { backgroundColor: primaryColor } : {}"
              :class="[
                'w-10 h-10 rounded-lg transition-all flex items-center justify-center',
                canSend
                  ? 'hover:scale-110 shadow-lg'
                  : 'bg-gray-200 dark:bg-gray-600 cursor-not-allowed',
              ]"
              :aria-label="$t('widget.send')"
              data-testid="btn-send"
              @click="sendMessage"
            >
              <span class="shrink-0">
                <PaperAirplaneIcon :class="['w-5 h-5', canSend ? 'text-white' : 'text-gray-400']" />
              </span>
            </button>
          </div>
        </div>

        <!-- Powered By -->
        <div
          class="px-4 py-2 text-center border-t"
          :class="{
            'pb-[calc(env(safe-area-inset-bottom,0px)+12px)]': isMobile && !isPreview,
          }"
          :style="{ borderColor: widgetTheme === 'dark' ? '#333' : '#e5e7eb' }"
        >
          <p class="text-xs" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }">
            Powered by <span class="font-semibold" :style="{ color: primaryColor }">synaplan</span>
          </p>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import {
  ChatBubbleLeftRightIcon,
  XMarkIcon,
  PaperClipIcon,
  PaperAirplaneIcon,
  DocumentIcon,
  SunIcon,
  MoonIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
} from '@heroicons/vue/24/outline'

import { uploadWidgetFile, sendWidgetMessage } from '@/services/api/widgetsApi'
import { useI18n } from 'vue-i18n'
import { parseAIResponse } from '@/utils/responseParser'
import { getMarkdownRenderer } from '@/composables/useMarkdown'

interface Props {
  widgetId: string
  primaryColor?: string
  iconColor?: string
  buttonIcon?: string
  buttonIconUrl?: string
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  autoOpen?: boolean
  openImmediately?: boolean
  autoMessage?: string
  messageLimit?: number
  maxFileSize?: number
  defaultTheme?: 'light' | 'dark'
  isPreview?: boolean
  widgetTitle?: string
  apiUrl: string
  allowFileUpload?: boolean
  fileUploadLimit?: number
  hideButton?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  primaryColor: '#007bff',
  iconColor: '#ffffff',
  buttonIcon: 'chat',
  position: 'bottom-right',
  autoOpen: false,
  autoMessage: 'Hello! How can I help you today?',
  messageLimit: 50,
  maxFileSize: 10,
  defaultTheme: 'light',
  isPreview: false,
  widgetTitle: '',
  allowFileUpload: false,
  fileUploadLimit: 3,
  hideButton: false,
})

interface Message {
  id: string
  role: 'user' | 'assistant'
  type: 'text' | 'file'
  content: string
  fileName?: string
  timestamp: Date
}

const isOpen = ref(false)
const widgetTheme = ref<'light' | 'dark'>(props.defaultTheme)
const inputMessage = ref('')

// Get button icon component based on buttonIcon prop
const getButtonIconComponent = computed(() => {
  // If custom icon URL is provided, it will be handled by img tag in template
  if (props.buttonIconUrl) {
    return null
  }

  // For now, always return ChatBubbleLeftRightIcon
  // In the full implementation, we would map buttonIcon values to different components
  return ChatBubbleLeftRightIcon
})
const selectedFile = ref<File | null>(null)
const fileSizeError = ref(false)
const messages = ref<Message[]>([])
const isTyping = ref(false)
const unreadCount = ref(0)
const messagesContainer = ref<HTMLElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const messageCount = ref(0)
const sessionId = ref<string>('')
const isSending = ref(false)
const chatId = ref<number | null>(null)
const historyLoaded = ref(false)
const isLoadingHistory = ref(false)

const isMobile = ref(false)
const { t } = useI18n()

const allowFileUploads = computed(() => !!props.allowFileUpload && !props.isPreview)
const fileUploadLimit = computed(() => props.fileUploadLimit ?? 0)
const fileUploadCount = ref(0)
const uploadingFile = ref(false)
const fileUploadError = ref<string | null>(null)
const fileLimitReached = computed(() => {
  if (!allowFileUploads.value) return false
  const limit = fileUploadLimit.value
  if (limit <= 0) {
    return true
  }
  return fileUploadCount.value >= limit
})

const updateIsMobile = () => {
  if (typeof window === 'undefined') return
  isMobile.value = window.matchMedia('(max-width: 768px)').matches
}

const chatWindowClasses = computed(() => {
  if (isMobile.value && !props.isPreview) {
    return ['fixed inset-0 rounded-none w-screen h-screen']
  }
  return ['rounded-2xl w-full max-w-[420px]']
})

const chatWindowStyle = computed(() => {
  if (isMobile.value && !props.isPreview) {
    return {
      width: '100vw',
      height: '100vh',
    }
  }

  return {
    width: props.isPreview ? 'min(100%, 380px)' : 'min(90vw, 420px)',
    height: props.isPreview ? 'min(80vh, 520px)' : 'min(80vh, 640px)',
  }
})

const positionClass = computed(() => {
  const positions = {
    'bottom-left': 'bottom-6 left-6',
    'bottom-right': 'bottom-6 right-6',
    'top-left': 'top-6 left-6',
    'top-right': 'top-6 right-6',
  }
  return positions[props.position]
})

const canSend = computed(() => {
  const hasText = inputMessage.value.trim() !== ''
  const hasFile = allowFileUploads.value && selectedFile.value !== null
  if (!hasText && !hasFile) {
    return false
  }
  if (uploadingFile.value) {
    return false
  }
  return !limitReached.value && !isSending.value
})

const showLimitWarning = computed(() => {
  const warningThreshold = props.messageLimit * 0.8
  return messageCount.value >= warningThreshold && messageCount.value < props.messageLimit
})

const limitReached = computed(() => {
  return messageCount.value >= props.messageLimit
})

const ensureAutoMessage = () => {
  if (!historyLoaded.value) return
  if (messages.value.length === 0 && props.autoMessage) {
    addBotMessage(props.autoMessage)
  }
}

const openChat = () => {
  if (!isOpen.value) {
    isOpen.value = true
    unreadCount.value = 0
    ensureAutoMessage()
  }
}

const closeChat = () => {
  if (isOpen.value) {
    isOpen.value = false
    // Dispatch close event for lazy-loaded widget button to reappear
    window.dispatchEvent(
      new CustomEvent('synaplan-widget-close', {
        detail: { widgetId: props.widgetId },
      })
    )
  }
}

const toggleChat = () => {
  if (isOpen.value) {
    closeChat()
  } else {
    openChat()
  }
}

const toggleTheme = () => {
  widgetTheme.value = widgetTheme.value === 'dark' ? 'light' : 'dark'
}

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  fileUploadError.value = null

  if (!allowFileUploads.value) {
    target.value = ''
    return
  }

  if (fileLimitReached.value) {
    fileUploadError.value = t('widget.fileUploadLimitReached')
    target.value = ''
    return
  }

  if (file) {
    const fileSizeMB = file.size / (1024 * 1024)
    if (fileSizeMB > props.maxFileSize) {
      fileSizeError.value = true
      setTimeout(() => {
        fileSizeError.value = false
      }, 3000)
      target.value = ''
      return
    }
    selectedFile.value = file
    fileSizeError.value = false
  }
}

const removeFile = () => {
  selectedFile.value = null
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

const sendMessage = async () => {
  if (!canSend.value || uploadingFile.value) return

  const fileIds: number[] = []
  fileUploadError.value = null

  // Upload file if selected
  if (allowFileUploads.value && selectedFile.value) {
    if (fileLimitReached.value) {
      fileUploadError.value = t('widget.fileUploadLimitReached')
      return
    }

    try {
      uploadingFile.value = true
      fileUploadError.value = null

      const uploadResult = await uploadWidgetFile(
        props.widgetId,
        sessionId.value,
        selectedFile.value,
        props.apiUrl
      )

      fileIds.push(uploadResult.file.id)
      fileUploadCount.value += 1

      messages.value.push({
        id: `file-${uploadResult.file.id}`,
        role: 'user',
        type: 'file',
        content: selectedFile.value.name,
        fileName: selectedFile.value.name,
        timestamp: new Date(),
      })

      selectedFile.value = null
      if (fileInput.value) {
        fileInput.value.value = ''
      }
    } catch (error: any) {
      console.error('Widget file upload failed:', error)
      fileUploadError.value = error?.message || t('widget.fileUploadFailed')
      return
    } finally {
      uploadingFile.value = false
    }
  }

  const trimmedInput = inputMessage.value.trim()
  let userMessage = trimmedInput

  if (!userMessage && fileIds.length > 0) {
    userMessage = t('widget.fileUploadDefaultMessage')
  }

  if (!userMessage) {
    return
  }

  messages.value.push({
    id: Date.now().toString(),
    role: 'user',
    type: 'text',
    content: userMessage,
    timestamp: new Date(),
  })
  messageCount.value++

  inputMessage.value = ''
  await scrollToBottom()

  if (limitReached.value) {
    return
  }

  isSending.value = true
  isTyping.value = true

  const assistantMessageId = Date.now().toString()
  messages.value.push({
    id: assistantMessageId,
    role: 'assistant',
    type: 'text',
    content: '',
    timestamp: new Date(),
  })

  try {
    const result = await sendWidgetMessage(props.widgetId, userMessage, sessionId.value, {
      chatId: chatId.value ?? undefined,
      fileIds,
      apiUrl: props.apiUrl,
      onChunk: async (chunk: string) => {
        if (!chunk) return
        if (isTyping.value) {
          isTyping.value = false
        }
        const lastMessage = messages.value[messages.value.length - 1]
        if (lastMessage && lastMessage.id === assistantMessageId) {
          lastMessage.content += chunk
          await scrollToBottom()
        }
      },
    })

    if (result.chatId && result.chatId > 0) {
      chatId.value = result.chatId
      const key = getChatStorageKey()
      if (key) {
        localStorage.setItem(key, result.chatId.toString())
      }
      if (!historyLoaded.value) {
        await loadConversationHistory()
      }
    }

    if (typeof result.remainingUploads === 'number') {
      const limit = fileUploadLimit.value
      if (limit > 0) {
        fileUploadCount.value = Math.max(0, limit - result.remainingUploads)
      }
    }

    const lastMessage = messages.value[messages.value.length - 1]
    if (lastMessage && lastMessage.id === assistantMessageId) {
      if (!lastMessage.content || lastMessage.content.length === 0) {
        if (result.text && result.text.length > 0) {
          lastMessage.content = result.text
        } else if (!props.isPreview) {
          await loadConversationHistory(true)
          await scrollToBottom()
          return
        } else {
          lastMessage.content = t('widget.defaultAssistantReply')
        }
      }
      await scrollToBottom()
    }

    isTyping.value = false
  } catch (error) {
    console.error('Failed to send message:', error)
    const lastMessage = messages.value.find((m) => m.id === assistantMessageId)
    let recovered = false

    if (!props.isPreview) {
      try {
        await loadConversationHistory(true)
        const latestMessage = messages.value[messages.value.length - 1]
        if (
          latestMessage &&
          latestMessage.role === 'assistant' &&
          latestMessage.content.trim().length > 0
        ) {
          recovered = true
        }
      } catch (historyError) {
        console.error('Failed to recover conversation history:', historyError)
      }
    }

    if (!recovered) {
      if (lastMessage && lastMessage.content.trim().length > 0) {
        isTyping.value = false
      } else {
        const lastMessageIndex = messages.value.findIndex((m) => m.id === assistantMessageId)
        if (lastMessageIndex !== -1) {
          messages.value.splice(lastMessageIndex, 1)
        }
        addBotMessage(t('widget.sendFailed'))
      }
    } else {
      await scrollToBottom()
    }
  } finally {
    isTyping.value = false
    isSending.value = false
  }
}

const addBotMessage = (text: string) => {
  messages.value.push({
    id: Date.now().toString(),
    role: 'assistant',
    type: 'text',
    content: text,
    timestamp: new Date(),
  })

  if (!isOpen.value) {
    unreadCount.value++
  }

  scrollToBottom()
}

const scrollToBottom = async () => {
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

const formatTime = (date: Date): string => {
  return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

const getSessionStorageKey = () => `synaplan_widget_session_${props.widgetId}`
const getChatStorageKeyForSession = (id: string) => `synaplan_widget_chatid_${props.widgetId}_${id}`
const createSessionId = () => `sess_${Date.now()}_${Math.random().toString(36).slice(2, 11)}`

const handleOpenEvent = (event: Event) => {
  const detail = (event as CustomEvent).detail
  if (detail?.widgetId && detail.widgetId !== props.widgetId) {
    return
  }
  openChat()
}

const handleCloseEvent = (event: Event) => {
  const detail = (event as CustomEvent).detail
  if (detail?.widgetId && detail.widgetId !== props.widgetId) {
    return
  }
  closeChat()
}

const normalizeServerMessage = (raw: any): Message => {
  let content = raw.text ?? ''
  if (typeof content === 'string') {
    const parsed = parseAIResponse(content)
    const textParts = parsed.parts
      .filter((part) => part.type === 'text' && part.content)
      .map((part) => part.content.trim())
      .filter(Boolean)

    if (textParts.length > 0) {
      content = textParts.join('\n\n')
    } else if (parsed.parts.length > 0) {
      content = parsed.parts.map((part) => part.content).join('\n\n')
    }
  }

  const role = raw.direction === 'IN' ? 'user' : 'assistant'
  const timestampSeconds = typeof raw.timestamp === 'number' ? raw.timestamp : Date.now() / 1000

  return {
    id: String(raw.id ?? crypto.randomUUID()),
    role,
    type: 'text',
    content,
    timestamp: new Date(timestampSeconds * 1000),
  }
}

const loadConversationHistory = async (force = false) => {
  if (props.isPreview) {
    historyLoaded.value = true
    ensureAutoMessage()
    return
  }

  if (!props.widgetId) {
    historyLoaded.value = true
    return
  }

  if (!sessionId.value || isLoadingHistory.value) {
    return
  }

  if (historyLoaded.value && !force) {
    return
  }

  isLoadingHistory.value = true

  try {
    const params = new URLSearchParams({ sessionId: sessionId.value })
    const response = await fetch(
      `${props.apiUrl}/api/v1/widget/${props.widgetId}/history?${params.toString()}`,
      {
        headers: buildWidgetHeaders(false),
      }
    )

    if (!response.ok) {
      throw new Error(`History request failed with status ${response.status}`)
    }

    const data = await response.json()
    if (data.success) {
      if (data.chatId) {
        chatId.value = data.chatId
      }

      const loadedMessages = Array.isArray(data.messages)
        ? data.messages.map((msg: any) => normalizeServerMessage(msg))
        : []

      if (loadedMessages.length > 0) {
        messages.value = loadedMessages
      }

      if (data.session && typeof data.session.messageCount === 'number') {
        messageCount.value = data.session.messageCount
        if (typeof data.session.fileCount === 'number') {
          fileUploadCount.value = data.session.fileCount
        }
      } else if (loadedMessages.length > 0) {
        messageCount.value = loadedMessages.filter((m: Message) => m.role === 'user').length
        fileUploadCount.value = 0
      }
    }
  } catch (error) {
    console.error('Failed to load widget history:', error)
  } finally {
    historyLoaded.value = true
    isLoadingHistory.value = false
    if (isOpen.value) {
      ensureAutoMessage()
    }
  }
}

// Use the shared markdown renderer (singleton for widget performance)
const markdownRenderer = getMarkdownRenderer()

interface CodeBlock {
  language: string
  code: string
  start: number
  end: number
}

// Extract code blocks from content
function extractCodeBlocks(content: string): { textParts: string[]; codeBlocks: CodeBlock[] } {
  const codeBlockRegex = /```(\w+)?\n([\s\S]*?)```/g
  const codeBlocks: CodeBlock[] = []
  let lastIndex = 0
  const textParts: string[] = []
  let match

  while ((match = codeBlockRegex.exec(content)) !== null) {
    // Add text before this code block
    if (match.index > lastIndex) {
      textParts.push(content.slice(lastIndex, match.index))
    } else {
      textParts.push('')
    }

    codeBlocks.push({
      language: match[1] || 'text',
      code: match[2].trim(),
      start: match.index,
      end: match.index + match[0].length,
    })

    lastIndex = match.index + match[0].length
  }

  // Add remaining text after last code block
  if (lastIndex < content.length) {
    textParts.push(content.slice(lastIndex))
  }

  return { textParts, codeBlocks }
}

// Handle clicks on messages container (for copy buttons)
async function handleMessagesClick(event: MouseEvent): Promise<void> {
  const target = event.target as HTMLElement
  if (target.classList.contains('widget-copy-btn')) {
    event.preventDefault()
    const code = target.getAttribute('data-code') || ''
    // Decode HTML entities
    const decodedCode = code
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"')

    try {
      await navigator.clipboard.writeText(decodedCode)
      const originalText = target.textContent
      target.textContent = t('commands.copied')
      setTimeout(() => {
        target.textContent = originalText
      }, 2000)
    } catch (err) {
      console.error('Failed to copy:', err)
    }
  }
}

// Render message content with enhanced code blocks
const renderMessageContent = (value: string): string => {
  if (!value) {
    return ''
  }

  // Parse code blocks and render with copy button
  const { textParts, codeBlocks } = extractCodeBlocks(value)

  if (codeBlocks.length === 0) {
    return markdownRenderer.render(value)
  }

  // Build HTML with enhanced code blocks
  let html = ''
  for (let i = 0; i < textParts.length; i++) {
    // Render text part
    if (textParts[i].trim()) {
      html += markdownRenderer.render(textParts[i])
    }

    // Add code block if exists
    if (i < codeBlocks.length) {
      const block = codeBlocks[i]
      const escapedCode = block.code
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')

      html += `
        <div class="widget-code-block my-2 rounded-lg overflow-hidden border border-black/10 dark:border-white/10">
          <div class="flex items-center justify-between px-3 py-1.5 bg-black/5 dark:bg-white/5 border-b border-black/10 dark:border-white/10">
            <span class="text-xs font-semibold uppercase tracking-wide opacity-70">${block.language}</span>
            <button class="widget-copy-btn text-xs px-2 py-0.5 rounded hover:bg-black/10 dark:hover:bg-white/10 transition-colors" data-code="${escapedCode.replace(/"/g, '&quot;')}">${t('commands.copy')}</button>
          </div>
          <pre class="p-3 overflow-x-auto text-xs"><code class="font-mono">${escapedCode}</code></pre>
        </div>
      `
    }
  }

  return html
}

// Load session ID from localStorage on mount
onMounted(() => {
  updateIsMobile()
  if (typeof window !== 'undefined') {
    window.addEventListener('resize', updateIsMobile)
    window.addEventListener('orientationchange', updateIsMobile)
  }

  window.addEventListener('synaplan-widget-open', handleOpenEvent)
  window.addEventListener('synaplan-widget-close', handleCloseEvent)

  const storageKey = getSessionStorageKey()
  let currentSessionId = localStorage.getItem(storageKey)
  if (!currentSessionId) {
    currentSessionId = createSessionId()
    localStorage.setItem(storageKey, currentSessionId)
  }
  sessionId.value = currentSessionId

  const sessionAwareKey = getChatStorageKeyForSession(currentSessionId)
  const legacyKey = `synaplan_widget_chatid_${props.widgetId}`
  const storedChatId = localStorage.getItem(sessionAwareKey) ?? localStorage.getItem(legacyKey)
  if (storedChatId) {
    chatId.value = parseInt(storedChatId, 10)
    if (!localStorage.getItem(sessionAwareKey)) {
      localStorage.setItem(sessionAwareKey, storedChatId)
    }
    if (localStorage.getItem(legacyKey)) {
      localStorage.removeItem(legacyKey)
    }
  }

  loadConversationHistory()
})

onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', updateIsMobile)
    window.removeEventListener('orientationchange', updateIsMobile)
  }

  window.removeEventListener('synaplan-widget-open', handleOpenEvent)
  window.removeEventListener('synaplan-widget-close', handleCloseEvent)
})

const getChatStorageKey = () => {
  if (!sessionId.value) return null
  return getChatStorageKeyForSession(sessionId.value)
}

// Save chatId to localStorage when it changes
watch(chatId, (newChatId) => {
  if (!newChatId) return
  const key = getChatStorageKey()
  if (key) {
    localStorage.setItem(key, newChatId.toString())
  }
})

// Auto-open
if (props.openImmediately) {
  // Open immediately when loaded from loader button
  nextTick(() => {
    openChat()
  })
} else if (props.autoOpen) {
  // Regular auto-open with delay
  setTimeout(() => {
    openChat()
  }, 3000)
}

watch(isOpen, (newVal) => {
  if (newVal) {
    scrollToBottom()
  }
})

function buildWidgetHeaders(includeContentType = true) {
  const headers: Record<string, string> = {}
  headers['Accept'] = 'application/json'
  if (includeContentType) {
    headers['Content-Type'] = 'application/json'
  }
  if (typeof window !== 'undefined' && window.location?.host) {
    headers['X-Widget-Host'] = window.location.host
  }
  return headers
}
</script>

<style scoped>
/* Markdown content styling for widget - scoped to .markdown-content */

/* Code blocks */
.markdown-content :deep(.code-block) {
  padding: 0.75rem;
  border-radius: 0.5rem;
  overflow-x: auto;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  background-color: rgba(0, 0, 0, 0.05);
}

.markdown-content :deep(.code-block code) {
  font-size: 0.75rem;
  font-family: ui-monospace, SFMono-Regular, monospace;
}

/* Inline code */
.markdown-content :deep(.inline-code) {
  padding: 0.125rem 0.25rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  background-color: rgba(0, 0, 0, 0.1);
}

/* Headers */
.markdown-content :deep(h1),
.markdown-content :deep(h2) {
  font-size: 1rem;
  font-weight: 600;
  margin-top: 0.5rem;
  margin-bottom: 0.25rem;
}

.markdown-content :deep(h3),
.markdown-content :deep(h4),
.markdown-content :deep(h5),
.markdown-content :deep(h6) {
  font-size: 0.875rem;
  font-weight: 600;
  margin-top: 0.5rem;
  margin-bottom: 0.25rem;
}

/* Lists */
.markdown-content :deep(ul) {
  list-style-type: disc;
  padding-left: 1.25rem;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
}

.markdown-content :deep(ol) {
  list-style-type: decimal;
  padding-left: 1.25rem;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
}

.markdown-content :deep(li) {
  margin-top: 0.25rem;
  margin-bottom: 0.25rem;
}

/* Task lists */
.markdown-content :deep(li > input[type='checkbox']) {
  margin-right: 0.375rem;
}

/* Blockquotes */
.markdown-content :deep(.markdown-blockquote) {
  border-left-width: 4px;
  border-left-style: solid;
  padding-left: 0.75rem;
  padding-top: 0.25rem;
  padding-bottom: 0.25rem;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  font-style: italic;
  border-top-right-radius: 0.25rem;
  border-bottom-right-radius: 0.25rem;
  border-color: #6b7280;
  background-color: rgba(0, 0, 0, 0.03);
}

/* Tables */
.markdown-content :deep(.markdown-table) {
  width: 100%;
  border-collapse: collapse;
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
}

.markdown-content :deep(.markdown-table th),
.markdown-content :deep(.markdown-table td) {
  border-width: 1px;
  border-style: solid;
  padding: 0.25rem 0.5rem;
  border-color: rgba(0, 0, 0, 0.1);
}

.markdown-content :deep(.markdown-table th) {
  font-weight: 600;
  background-color: rgba(0, 0, 0, 0.05);
}

/* Horizontal rule */
.markdown-content :deep(hr) {
  margin-top: 0.75rem;
  margin-bottom: 0.75rem;
  border-top-width: 1px;
  border-color: #d1d5db;
}

/* Links */
.markdown-content :deep(a) {
  color: #2563eb;
  text-decoration: underline;
}

/* Paragraphs */
.markdown-content :deep(p) {
  margin-top: 0.25rem;
  margin-bottom: 0.5rem;
}

.markdown-content :deep(p:first-child) {
  margin-top: 0;
}

.markdown-content :deep(p:last-child) {
  margin-bottom: 0;
}

/* Strikethrough */
.markdown-content :deep(del) {
  text-decoration: line-through;
  opacity: 0.6;
}

/* Bold */
.markdown-content :deep(strong) {
  font-weight: 600;
}

/* Italic */
.markdown-content :deep(em) {
  font-style: italic;
}

/* Mermaid diagrams */
.markdown-content :deep(.mermaid-diagram) {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  overflow-x: auto;
}

.markdown-content :deep(.mermaid-diagram svg) {
  max-width: 100%;
  height: auto;
}

.markdown-content :deep(.mermaid-block) {
  padding: 0.75rem;
  overflow-x: auto;
  margin: 0.5rem 0;
  border-radius: 0.5rem;
  background: rgba(0, 0, 0, 0.05);
}

/* KaTeX math */
.markdown-content :deep(.katex-block) {
  margin-top: 0.5rem;
  margin-bottom: 0.5rem;
  overflow-x: auto;
  text-align: center;
}

.markdown-content :deep(.katex-inline) {
  display: inline;
}

.markdown-content :deep(.katex) {
  font-size: 1em;
}

/* Widget code block with copy button */
.markdown-content :deep(.widget-code-block) {
  margin: 0.5rem 0;
  border-radius: 0.5rem;
  overflow: hidden;
}

.markdown-content :deep(.widget-code-block pre) {
  margin: 0;
  padding: 0.75rem;
  overflow-x: auto;
  background: rgba(0, 0, 0, 0.03);
}

.markdown-content :deep(.widget-code-block code) {
  font-size: 0.75rem;
  font-family: ui-monospace, SFMono-Regular, monospace;
}

.markdown-content :deep(.widget-copy-btn) {
  cursor: pointer;
  opacity: 0.7;
}

.markdown-content :deep(.widget-copy-btn:hover) {
  opacity: 1;
}
</style>
