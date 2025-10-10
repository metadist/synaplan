<template>
  <div :class="[isPreview ? 'absolute' : 'fixed', 'z-[9999]', positionClass]">
    <!-- Chat Button -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="scale-0 opacity-0"
      enter-to-class="scale-100 opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="scale-100 opacity-100"
      leave-to-class="scale-0 opacity-0"
    >
      <button
        v-if="!isOpen"
        @click="toggleChat"
        :style="{ backgroundColor: primaryColor }"
        class="w-16 h-16 rounded-full shadow-2xl hover:scale-110 transition-transform flex items-center justify-center group"
        aria-label="Open chat"
      >
        <ChatBubbleLeftRightIcon :style="{ color: iconColor }" class="w-8 h-8" />
        <span
          v-if="unreadCount > 0"
          class="absolute -top-1 -right-1 w-6 h-6 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center"
        >
          {{ unreadCount }}
        </span>
      </button>
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
        :class="[
          'flex flex-col overflow-hidden shadow-2xl',
          isMobile && !isPreview ? 'fixed inset-0' : 'rounded-2xl w-[400px] h-[600px]'
        ]"
        :style="{
          backgroundColor: widgetTheme === 'dark' ? '#1a1a1a' : '#ffffff'
        }"
      >
        <!-- Header -->
        <div
          :style="{ backgroundColor: primaryColor }"
          class="flex items-center justify-between px-4 py-3"
        >
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
              <ChatBubbleLeftRightIcon class="w-6 h-6 text-white" />
            </div>
            <div>
              <h3 class="text-white font-semibold">{{ $t('widget.title') }}</h3>
              <p class="text-white/80 text-xs">{{ $t('widget.subtitle') }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="toggleTheme"
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              :aria-label="widgetTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
            >
              <SunIcon v-if="widgetTheme === 'dark'" class="w-5 h-5 text-white" />
              <MoonIcon v-else class="w-5 h-5 text-white" />
            </button>
            <button
              @click="toggleChat"
              class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 transition-colors flex items-center justify-center"
              aria-label="Close chat"
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
            backgroundColor: widgetTheme === 'dark' ? '#1a1a1a' : '#ffffff'
          }"
        >
          <div
            v-for="message in messages"
            :key="message.id"
            :class="[
              'flex',
              message.role === 'user' ? 'justify-end' : 'justify-start'
            ]"
          >
            <div
              :class="[
                'max-w-[80%] rounded-2xl px-4 py-2',
                message.role === 'user' ? '' : ''
              ]"
              :style="message.role === 'user' 
                ? { backgroundColor: primaryColor, color: iconColor }
                : { backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }"
            >
              <p
                v-if="message.type === 'text'"
                class="text-sm whitespace-pre-wrap break-words"
                :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937') }"
              >
                {{ message.content }}
              </p>
              <div v-else-if="message.type === 'file'" class="flex items-center gap-2">
                <DocumentIcon class="w-5 h-5" :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937') }" />
                <span class="text-sm" :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937') }">
                  {{ message.fileName }}
                </span>
              </div>
              <p
                v-if="message.timestamp"
                class="text-xs mt-1 opacity-70"
                :style="{ color: message.role === 'user' ? iconColor : (widgetTheme === 'dark' ? '#9ca3af' : '#6b7280') }"
              >
                {{ formatTime(message.timestamp) }}
              </p>
            </div>
          </div>

          <div v-if="isTyping" class="flex justify-start">
            <div class="rounded-2xl px-4 py-3" :style="{ backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }">
              <div class="flex gap-1">
                <div class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce" style="animation-delay: 0ms"></div>
                <div class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce" style="animation-delay: 150ms"></div>
                <div class="w-2 h-2 rounded-full bg-[var(--brand)] animate-bounce" style="animation-delay: 300ms"></div>
              </div>
            </div>
          </div>

          <!-- Limit Warning -->
          <div v-if="showLimitWarning" class="flex justify-center">
            <div class="bg-orange-500/10 border border-orange-500/30 rounded-xl px-4 py-3 max-w-[90%]">
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
        <div class="border-t p-3" :style="{ borderColor: widgetTheme === 'dark' ? '#333' : '#e5e7eb' }">
          <div v-if="selectedFile" class="mb-2 flex items-center gap-2 p-2 rounded-lg" :style="{ backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6' }">
            <DocumentIcon class="w-5 h-5" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }" />
            <span class="text-sm flex-1 truncate" :style="{ color: widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937' }">{{ selectedFile.name }}</span>
            <span class="text-xs" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }">{{ formatFileSize(selectedFile.size) }}</span>
            <button
              @click="removeFile"
              class="w-6 h-6 rounded hover:bg-black/10 dark:hover:bg-white/10 flex items-center justify-center"
            >
              <XMarkIcon class="w-4 h-4 txt-secondary" />
            </button>
          </div>

          <!-- File Size Error -->
          <div v-if="fileSizeError" class="mb-2 p-2 bg-red-500/10 border border-red-500/30 rounded-lg">
            <div class="flex items-start gap-2">
              <ExclamationTriangleIcon class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" />
              <p class="text-xs text-red-600 dark:text-red-400">
                {{ $t('widget.fileTooLarge', { max: maxFileSize }) }}
              </p>
            </div>
          </div>

          <div class="flex items-end gap-2">
            <input
              ref="fileInput"
              type="file"
              @change="handleFileSelect"
              accept="image/*,.pdf,.doc,.docx,.txt"
              class="hidden"
            />
            <button
              @click="fileInput?.click()"
              :disabled="limitReached"
              class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed"
              :aria-label="$t('widget.attachFile')"
            >
              <PaperClipIcon class="w-5 h-5" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }" />
            </button>
            <textarea
              v-model="inputMessage"
              @keydown.enter.exact.prevent="sendMessage"
              :disabled="limitReached"
              :placeholder="limitReached ? $t('widget.limitReachedPlaceholder') : $t('widget.placeholder')"
              rows="1"
              class="flex-1 px-4 py-2 rounded-lg resize-none focus:outline-none focus:ring-2 disabled:opacity-50 disabled:cursor-not-allowed"
              :style="{
                backgroundColor: widgetTheme === 'dark' ? '#2a2a2a' : '#f3f4f6',
                color: widgetTheme === 'dark' ? '#e5e5e5' : '#1f2937',
                borderColor: primaryColor,
                maxHeight: '120px',
                minHeight: '40px'
              }"
            />
            <button
              @click="sendMessage"
              :disabled="!canSend"
              :style="canSend ? { backgroundColor: primaryColor } : {}"
              :class="[
                'w-10 h-10 rounded-lg transition-all flex items-center justify-center',
                canSend ? 'hover:scale-110 shadow-lg' : 'bg-gray-300 dark:bg-gray-600 cursor-not-allowed'
              ]"
              :aria-label="$t('widget.send')"
            >
              <PaperAirplaneIcon :class="['w-5 h-5', canSend ? 'text-white' : 'text-gray-500']" />
            </button>
          </div>
        </div>

        <!-- Powered By -->
        <div class="px-4 py-2 text-center border-t" :style="{ borderColor: widgetTheme === 'dark' ? '#333' : '#e5e7eb' }">
          <p class="text-xs" :style="{ color: widgetTheme === 'dark' ? '#9ca3af' : '#6b7280' }">
            Powered by <span class="font-semibold" :style="{ color: primaryColor }">synaplan</span>
          </p>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import {
  ChatBubbleLeftRightIcon,
  XMarkIcon,
  PaperAirplaneIcon,
  PaperClipIcon,
  DocumentIcon,
  SunIcon,
  MoonIcon,
  ExclamationTriangleIcon,
  XCircleIcon
} from '@heroicons/vue/24/outline'

interface Props {
  primaryColor?: string
  iconColor?: string
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  autoOpen?: boolean
  autoMessage?: string
  messageLimit?: number
  maxFileSize?: number
  defaultTheme?: 'light' | 'dark'
  isPreview?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  primaryColor: '#007bff',
  iconColor: '#ffffff',
  position: 'bottom-right',
  autoOpen: false,
  autoMessage: 'Hello! How can I help you today?',
  messageLimit: 50,
  maxFileSize: 10,
  defaultTheme: 'light',
  isPreview: false
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
const selectedFile = ref<File | null>(null)
const fileSizeError = ref(false)
const messages = ref<Message[]>([])
const isTyping = ref(false)
const unreadCount = ref(0)
const messagesContainer = ref<HTMLElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const messageCount = ref(0)

const isMobile = computed(() => window.innerWidth < 768)

const positionClass = computed(() => {
  const positions = {
    'bottom-left': 'bottom-6 left-6',
    'bottom-right': 'bottom-6 right-6',
    'top-left': 'top-6 left-6',
    'top-right': 'top-6 right-6'
  }
  return positions[props.position]
})

const canSend = computed(() => {
  return !limitReached.value && (inputMessage.value.trim() !== '' || selectedFile.value !== null)
})

const showLimitWarning = computed(() => {
  const warningThreshold = props.messageLimit * 0.8
  return messageCount.value >= warningThreshold && messageCount.value < props.messageLimit
})

const limitReached = computed(() => {
  return messageCount.value >= props.messageLimit
})

const toggleChat = () => {
  isOpen.value = !isOpen.value
  if (isOpen.value) {
    unreadCount.value = 0
    if (messages.value.length === 0 && props.autoMessage) {
      addBotMessage(props.autoMessage)
    }
  }
}

const toggleTheme = () => {
  widgetTheme.value = widgetTheme.value === 'dark' ? 'light' : 'dark'
}

const handleFileSelect = (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  
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
}

const sendMessage = async () => {
  if (!canSend.value) return

  if (selectedFile.value) {
    messages.value.push({
      id: Date.now().toString(),
      role: 'user',
      type: 'file',
      content: selectedFile.value.name,
      fileName: selectedFile.value.name,
      timestamp: new Date()
    })
    selectedFile.value = null
    messageCount.value++
  }

  if (inputMessage.value.trim()) {
    messages.value.push({
      id: Date.now().toString(),
      role: 'user',
      type: 'text',
      content: inputMessage.value,
      timestamp: new Date()
    })
    messageCount.value++
    
    const userInput = inputMessage.value
    inputMessage.value = ''
    
    await scrollToBottom()
    
    if (!limitReached.value) {
      isTyping.value = true
      await new Promise(resolve => setTimeout(resolve, 1000 + Math.random() * 1000))
      isTyping.value = false
      
      addBotMessage(generateResponse(userInput))
    }
  }
}

const addBotMessage = (text: string) => {
  messages.value.push({
    id: Date.now().toString(),
    role: 'assistant',
    type: 'text',
    content: text,
    timestamp: new Date()
  })
  
  if (!isOpen.value) {
    unreadCount.value++
  }
  
  scrollToBottom()
}

const generateResponse = (_input: string): string => {
  const responses = [
    'Thank you for your message. Our AI is processing your request.',
    'I understand. Let me help you with that.',
    'That\'s a great question! Here\'s what I can tell you...',
    'I\'m here to assist you. Could you provide more details?',
    'Based on your input, I recommend the following steps...'
  ]
  return responses[Math.floor(Math.random() * responses.length)]
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

// Auto-open
if (props.autoOpen) {
  setTimeout(() => {
    toggleChat()
  }, 3000)
}

watch(isOpen, (newVal) => {
  if (newVal) {
    scrollToBottom()
  }
})
</script>

