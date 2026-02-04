<template>
  <div
    :class="[
      testMode ? 'relative w-full h-full' : isPreview ? 'absolute' : 'fixed',
      testMode ? '' : 'z-[9999]',
      testMode ? '' : positionClass,
    ]"
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
            v-if="buttonIcon === 'custom' && buttonIconUrl"
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
              v-if="messages.length > 0"
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              :aria-label="$t('widget.exportChat')"
              :title="$t('widget.exportChat')"
              data-testid="btn-export"
              @click="exportChat"
            >
              <ArrowDownTrayIcon class="w-5 h-5 text-white" />
            </button>
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
          class="flex-1 overflow-y-auto p-4 flex flex-col gap-3"
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
            :data-testid="`message-${message.role}`"
          >
            <div
              :class="['max-w-[80%] rounded-2xl px-4 py-2', message.role === 'user' ? '' : '']"
              :style="
                message.role === 'user'
                  ? { backgroundColor: primaryColor, color: '#ffffff' }
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
                  :data-testid="message.role === 'assistant' && message.content === autoMessage ? 'message-auto-text' : message.role === 'assistant' ? 'message-ai-text' : 'message-user-text'"
                  :style="{
                    color:
                      message.role === 'user'
                        ? '#ffffff'
                        : widgetTheme === 'dark'
                          ? '#e5e5e5'
                          : '#1f2937',
                  }"
                  v-html="renderMessageContent(message.content)"
                ></div>
              </template>
              <div v-else-if="message.type === 'file'" class="space-y-2">
                <!-- File attachments (clickable for download) -->
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="file in message.files || [
                      { id: message.fileId, filename: message.fileName },
                    ]"
                    :key="file.id"
                    class="flex items-center gap-2 px-2 py-1 rounded-md bg-black/5 dark:bg-white/5 hover:bg-black/10 dark:hover:bg-white/10 transition-colors cursor-pointer"
                    :title="$t('widget.downloadFile')"
                    @click="downloadFileById(file.id, file.filename)"
                  >
                    <DocumentIcon
                      class="w-4 h-4 flex-shrink-0"
                      :style="{
                        color:
                          message.role === 'user'
                            ? '#ffffff'
                            : widgetTheme === 'dark'
                              ? '#e5e5e5'
                              : '#1f2937',
                      }"
                    />
                    <span
                      class="text-sm underline truncate max-w-[150px]"
                      :style="{
                        color:
                          message.role === 'user'
                            ? '#ffffff'
                            : widgetTheme === 'dark'
                              ? '#e5e5e5'
                              : '#1f2937',
                      }"
                    >
                      {{ file.filename }}
                    </span>
                  </button>
                </div>
                <!-- Text content (question about the file) -->
                <p
                  v-if="message.content && message.content !== message.fileName"
                  class="text-sm"
                  :style="{
                    color:
                      message.role === 'user'
                        ? '#ffffff'
                        : widgetTheme === 'dark'
                          ? '#e5e5e5'
                          : '#1f2937',
                  }"
                  v-html="renderMessageContent(message.content)"
                />
              </div>
              <p
                v-if="message.timestamp"
                class="text-xs mt-1 opacity-70"
                :style="{
                  color:
                    message.role === 'user'
                      ? '#ffffff'
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
              data-testid="warning-message-limit"
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
            <div data-testid="error-message-limit-reached" class="bg-red-500/10 border border-red-500/30 rounded-xl px-4 py-3 max-w-[90%]">
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
            data-testid="error-file-upload"
            class="mb-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg"
          >
            <div class="flex items-start gap-2">
              <XCircleIcon class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-red-600 dark:text-red-400">{{ fileUploadError }}</p>
            </div>
          </div>

          <div
            v-if="allowFileUploads && fileLimitReached && selectedFiles.length === 0"
            data-testid="error-file-upload-limit"
            class="mb-2 p-2 bg-amber-500/10 border border-amber-500/30 rounded-lg"
          >
            <div class="flex items-start gap-2">
              <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-amber-600 dark:text-amber-400">
                {{ $t('widget.fileUploadLimitReached') }}
              </p>
            </div>
          </div>

          <div v-if="selectedFiles.length > 0" class="mb-2 space-y-1">
            <div
              v-for="(file, index) in selectedFiles"
              :key="`${file.name}-${file.size}`"
              class="flex items-center gap-2 p-2 rounded-lg"
              :style="{ backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }"
            >
              <DocumentIcon
                class="w-5 h-5 flex-shrink-0"
                :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }"
              />
              <span
                class="text-sm flex-1 truncate"
                :style="{ color: widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937' }"
                >{{ file.name }}</span
              >
              <span
                class="text-xs flex-shrink-0"
                :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }"
                >{{ formatFileSize(file.size) }}</span
              >
              <button
                class="w-6 h-6 rounded hover:bg-black/10 dark:hover:bg-white/10 flex items-center justify-center flex-shrink-0"
                :data-testid="`btn-remove-file-${index}`"
                @click="removeFile(index)"
              >
                <XMarkIcon class="w-4 h-4 txt-secondary" />
              </button>
            </div>
          </div>

          <!-- File Size Error -->
          <div
            v-if="fileSizeError"
            data-testid="error-file-size"
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
                multiple
                data-testid="input-file"
                @change="handleFileSelect"
              />
              <button
                :disabled="limitReached || !canAddMoreFiles"
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
            Powered by
            <a
              href="https://www.synaplan.com/"
              target="_blank"
              rel="noopener noreferrer"
              class="font-semibold hover:underline"
              :style="{ color: primaryColor }"
              >synaplan</a
            >
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
  ArrowDownTrayIcon,
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
  testMode?: boolean
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
  testMode: false,
})

const emit = defineEmits<{
  (e: 'close'): void
}>()

interface MessageFile {
  id: number
  filename: string
  fileType?: string
  filePath?: string
  fileSize?: number
  fileMime?: string
}

interface Message {
  id: string
  role: 'user' | 'assistant'
  type: 'text' | 'file'
  content: string
  fileName?: string
  fileId?: number
  files?: MessageFile[]
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
const selectedFiles = ref<File[]>([])
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

const allowFileUploads = computed(
  () => !!props.allowFileUpload && (!props.isPreview || props.testMode)
)
const fileUploadLimit = computed(() => props.fileUploadLimit ?? 0)
const isTestEnvironment = computed(() => props.testMode || props.isPreview)
const testModeHeaders = computed(
  (): Record<string, string> => (isTestEnvironment.value ? { 'X-Widget-Test-Mode': 'true' } : {})
)
const fileUploadCount = ref(0)
const uploadingFile = ref(false)
const fileUploadError = ref<string | null>(null)
const fileLimitReached = computed(() => {
  if (!allowFileUploads.value) return false
  const limit = fileUploadLimit.value
  // 0 means unlimited
  if (limit <= 0) {
    return false
  }
  return fileUploadCount.value >= limit
})

const remainingFileSlots = computed(() => {
  if (!allowFileUploads.value) return 0
  const limit = fileUploadLimit.value
  // 0 means unlimited - return a large number
  if (limit <= 0) return 999
  // Remaining = total limit - already uploaded - currently selected
  return Math.max(0, limit - fileUploadCount.value - selectedFiles.value.length)
})

const canAddMoreFiles = computed(() => {
  return remainingFileSlots.value > 0
})

const updateIsMobile = () => {
  if (typeof window === 'undefined') return
  isMobile.value = window.matchMedia('(max-width: 768px)').matches
}

const chatWindowClasses = computed(() => {
  if (isMobile.value && !props.isPreview) {
    return ['fixed inset-0 rounded-none w-screen h-screen']
  }
  // Test mode: fill parent container completely
  if (props.testMode) {
    return ['rounded-2xl w-full h-full']
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

  // Test mode: fill the parent container completely
  if (props.testMode) {
    return {
      width: '100%',
      height: '100%',
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
  const hasFiles = allowFileUploads.value && selectedFiles.value.length > 0
  if (!hasText && !hasFiles) {
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
    // In test mode, emit close event for parent to handle
    if (props.testMode) {
      emit('close')
    }
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

// Format a date for the export
const formatExportDate = (date: Date): string => {
  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// Escape HTML special characters
const escapeHtml = (text: string): string => {
  const div = document.createElement('div')
  div.textContent = text
  return div.innerHTML
}

// Validate and sanitize hex color to prevent CSS injection
const sanitizeHexColor = (color: string, fallback: string): string => {
  // Match valid hex colors: #RGB, #RRGGBB, #RRGGBBAA
  const hexPattern = /^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/
  return hexPattern.test(color) ? color : fallback
}

// Export chat as PDF (via print dialog)
const exportChat = () => {
  if (messages.value.length === 0) return

  const exportDate = new Date()
  const chatTitle = props.widgetTitle || t('widget.title')
  const defaultColor = '#6366f1'
  const themeColor = sanitizeHexColor(props.primaryColor || defaultColor, defaultColor)

  // Build HTML content
  let html = `
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>${escapeHtml(chatTitle)} - ${t('widget.exportChat')}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      line-height: 1.6;
      color: #1a1a1a;
      background: #fff;
      padding: 40px;
      max-width: 800px;
      margin: 0 auto;
    }
    .header {
      border-bottom: 3px solid ${themeColor};
      padding-bottom: 20px;
      margin-bottom: 30px;
    }
    .header h1 {
      color: ${themeColor};
      font-size: 28px;
      margin-bottom: 15px;
    }
    .meta {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      font-size: 14px;
      color: #666;
    }
    .meta-item { display: flex; gap: 6px; }
    .meta-label { font-weight: 600; color: #333; }
    .messages { display: flex; flex-direction: column; gap: 20px; }
    .message {
      padding: 16px 20px;
      border-radius: 12px;
      max-width: 85%;
      page-break-inside: avoid;
    }
    .message-user {
      background: ${themeColor};
      color: white;
      margin-left: auto;
      border-bottom-right-radius: 4px;
    }
    .message-assistant {
      background: #f3f4f6;
      color: #1a1a1a;
      margin-right: auto;
      border-bottom-left-radius: 4px;
    }
    .message-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 10px;
      font-size: 13px;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .message-sender { font-weight: 600; }
    .message-time { white-space: nowrap; }
    .message-user .message-header { color: #333; }
    .message-content {
      font-size: 15px;
      white-space: pre-wrap;
      word-wrap: break-word;
    }
    .message-user .message-content { color: white; }
    .attachment {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(0,0,0,0.1);
      padding: 6px 12px;
      border-radius: 6px;
      font-size: 13px;
      margin-bottom: 8px;
    }
    .message-user .attachment { background: rgba(255,255,255,0.2); }
    .footer {
      margin-top: 40px;
      padding-top: 20px;
      border-top: 1px solid #e5e7eb;
      text-align: center;
      font-size: 12px;
      color: #999;
    }
    @media print {
      body { padding: 20px; }
      .message { break-inside: avoid; }
    }
  </style>
</head>
<body>
  <div class="header">
    <h1>${escapeHtml(chatTitle)}</h1>
    <div class="meta">
      <div class="meta-item">
        <span class="meta-label">${t('widget.exportChatId')}:</span>
        <span>${chatId.value || 'N/A'}</span>
      </div>
      <div class="meta-item">
        <span class="meta-label">${t('widget.exportDate')}:</span>
        <span>${formatExportDate(exportDate)}</span>
      </div>
      <div class="meta-item">
        <span class="meta-label">${t('widget.exportMessageCount')}:</span>
        <span>${messages.value.length}</span>
      </div>
    </div>
  </div>
  <div class="messages">
`

  // Add messages
  for (const message of messages.value) {
    const isUser = message.role === 'user'
    const sender = isUser ? t('widget.you') : t('widget.assistant')
    const icon = isUser ? '👤' : '🤖'
    const time = formatExportDate(message.timestamp)

    html += `
    <div class="message message-${message.role}">
      <div class="message-header">
        <span class="message-sender">${icon} ${sender}</span>
        <span class="message-time">${time}</span>
      </div>
`

    // Handle file attachments
    if (message.files && message.files.length > 0) {
      for (const file of message.files) {
        html += `      <div class="attachment">📎 ${escapeHtml(file.filename)}</div>\n`
      }
    } else if (message.fileName) {
      html += `      <div class="attachment">📎 ${escapeHtml(message.fileName)}</div>\n`
    }

    // Add message content
    if (message.content) {
      html += `      <div class="message-content">${escapeHtml(message.content)}</div>\n`
    }

    html += `    </div>\n`
  }

  html += `
  </div>
  <div class="footer">${t('widget.exportFooter')}</div>
</body>
</html>`

  // Open in new window and trigger print
  const printWindow = window.open('', '_blank')
  if (printWindow) {
    printWindow.document.write(html)
    printWindow.document.close()
    // Wait for content to load then print
    printWindow.onload = () => {
      printWindow.print()
    }
    // Fallback if onload doesn't fire
    setTimeout(() => {
      printWindow.print()
    }, 500)
  }
}

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  const files = target.files
  fileUploadError.value = null

  if (!allowFileUploads.value) {
    target.value = ''
    return
  }

  if (!files || files.length === 0) {
    target.value = ''
    return
  }

  // Check if we can still add files
  if (!canAddMoreFiles.value) {
    fileUploadError.value = t('widget.fileUploadLimitReached')
    target.value = ''
    return
  }

  // Process selected files
  const filesToAdd: File[] = []
  let hasError = false

  for (let i = 0; i < files.length; i++) {
    const file = files[i]

    // Check if we've reached the limit
    if (filesToAdd.length >= remainingFileSlots.value) {
      fileUploadError.value = t('widget.fileUploadLimitReached')
      break
    }

    // Check file size
    const fileSizeMB = file.size / (1024 * 1024)
    if (fileSizeMB > props.maxFileSize) {
      hasError = true
      continue // Skip this file but continue with others
    }

    // Check if file with same name is already selected
    const isDuplicate = selectedFiles.value.some(
      (f) => f.name === file.name && f.size === file.size
    )
    if (!isDuplicate) {
      filesToAdd.push(file)
    }
  }

  if (hasError && filesToAdd.length === 0) {
    fileSizeError.value = true
    setTimeout(() => {
      fileSizeError.value = false
    }, 3000)
  }

  // Add valid files to the selection
  if (filesToAdd.length > 0) {
    // Limit to remaining slots
    const slotsAvailable = remainingFileSlots.value
    const filesToActuallyAdd = filesToAdd.slice(0, slotsAvailable)
    selectedFiles.value = [...selectedFiles.value, ...filesToActuallyAdd]
    fileSizeError.value = false
  }

  // Reset input so the same file can be selected again if removed
  target.value = ''
}

const removeFile = (index: number) => {
  selectedFiles.value = selectedFiles.value.filter((_, i) => i !== index)
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

const sendMessage = async () => {
  if (!canSend.value || uploadingFile.value) return

  const fileIds: number[] = []
  const uploadedFiles: MessageFile[] = []
  fileUploadError.value = null

  // Upload files if selected
  if (allowFileUploads.value && selectedFiles.value.length > 0) {
    if (fileLimitReached.value) {
      fileUploadError.value = t('widget.fileUploadLimitReached')
      return
    }

    try {
      uploadingFile.value = true
      fileUploadError.value = null

      // Upload each file and collect info (don't create separate messages)
      for (const file of selectedFiles.value) {
        const uploadResult = await uploadWidgetFile(props.widgetId, sessionId.value, file, {
          apiUrl: props.apiUrl,
          headers: testModeHeaders.value,
        })

        fileIds.push(uploadResult.file.id)
        fileUploadCount.value += 1

        // Collect file info for the combined message
        uploadedFiles.push({
          id: uploadResult.file.id,
          filename: file.name,
          fileSize: file.size,
          fileMime: file.type,
        })
      }

      selectedFiles.value = []
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

  // Create a single message with both text and files (if any)
  const hasFiles = uploadedFiles.length > 0
  messages.value.push({
    id: Date.now().toString(),
    role: 'user',
    type: hasFiles ? 'file' : 'text',
    content: userMessage,
    fileName: hasFiles ? uploadedFiles[0].filename : undefined,
    fileId: hasFiles ? uploadedFiles[0].id : undefined,
    files: hasFiles ? uploadedFiles : undefined,
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
      headers: testModeHeaders.value,
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
      // Skip localStorage in test mode
      if (!isTestEnvironment.value) {
        const key = getChatStorageKey()
        if (key) {
          localStorage.setItem(key, result.chatId.toString())
        }
      }
      if (!historyLoaded.value) {
        await loadConversationHistory()
      }
    }

    if (typeof result.remainingUploads === 'number') {
      const limit = fileUploadLimit.value
      // Only track count if there's an actual limit (0 = unlimited)
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

const downloadFileById = async (fileId: number | undefined, filename: string | undefined) => {
  if (!fileId) {
    console.error('No file ID found for download')
    return
  }

  if (!sessionId.value) {
    console.error('No session ID for download')
    return
  }

  const downloadFilename = filename || 'file'

  try {
    // Use public widget file download endpoint (no auth required)
    const downloadUrl = `${props.apiUrl}/api/v1/widget/${props.widgetId}/files/${fileId}/download?sessionId=${encodeURIComponent(sessionId.value)}`
    const response = await fetch(downloadUrl, {
      method: 'GET',
      credentials: props.testMode ? 'include' : 'omit',
      headers: testModeHeaders.value,
    })

    if (!response.ok) {
      throw new Error(`Download failed: ${response.status}`)
    }

    const blob = await response.blob()
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = downloadFilename
    document.body.appendChild(a)
    a.click()
    window.URL.revokeObjectURL(url)
    document.body.removeChild(a)
  } catch (error) {
    console.error('Download failed:', error)
    // Show error in chat instead of alert
    addBotMessage(t('widget.downloadFailed'))
  }
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

  // Check if message has attached files
  const files: MessageFile[] =
    raw.files && Array.isArray(raw.files)
      ? raw.files.map((f: any) => ({
          id: f.id,
          filename: f.filename,
          fileType: f.fileType,
          filePath: f.filePath,
          fileSize: f.fileSize,
          fileMime: f.fileMime,
        }))
      : []

  // If message has files and is from user, mark as file message but KEEP the text content
  const hasFiles = files.length > 0
  const isFileMessage = hasFiles && role === 'user'

  return {
    id: String(raw.id ?? crypto.randomUUID()),
    role,
    type: isFileMessage ? 'file' : 'text',
    // Keep the original text content - the file info is shown separately via files array
    content,
    fileName: isFileMessage && files[0] ? files[0].filename : undefined,
    fileId: isFileMessage && files[0] ? files[0].id : undefined,
    files,
    timestamp: new Date(timestampSeconds * 1000),
  }
}

const loadConversationHistory = async (force = false) => {
  // In test/preview mode, skip loading real history but mark as loaded for auto message
  if (isTestEnvironment.value) {
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

// Decode HTML entities using browser's built-in decoder
function decodeHtmlEntities(html: string): string {
  const textarea = document.createElement('textarea')
  textarea.innerHTML = html
  return textarea.value
}

// Handle clicks on messages container (for copy buttons)
async function handleMessagesClick(event: MouseEvent): Promise<void> {
  const target = event.target as HTMLElement
  if (target.classList.contains('widget-copy-btn')) {
    event.preventDefault()
    const code = target.getAttribute('data-code') || ''
    const decodedCode = decodeHtmlEntities(code)

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

// Load session ID from localStorage on mount (skip for test mode)
onMounted(() => {
  updateIsMobile()
  if (typeof window !== 'undefined') {
    window.addEventListener('resize', updateIsMobile)
    window.addEventListener('orientationchange', updateIsMobile)
  }

  window.addEventListener('synaplan-widget-open', handleOpenEvent)
  window.addEventListener('synaplan-widget-close', handleCloseEvent)

  // In test mode, create a temporary session without localStorage persistence
  if (isTestEnvironment.value) {
    sessionId.value = createSessionId()
    // Still call loadConversationHistory to trigger ensureAutoMessage (but it won't load actual history)
    loadConversationHistory()
    return
  }

  // Normal mode: use localStorage for session persistence
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

// Save chatId to localStorage when it changes (skip for test mode)
watch(chatId, (newChatId) => {
  if (!newChatId || isTestEnvironment.value) return
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
/*
 * Widget-specific markdown styles
 *
 * NOTE: The widget is a separate bundle embedded on external sites,
 * so it cannot use the global markdown.css from the main app.
 * These styles are necessary for the widget to render markdown correctly.
 */

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
