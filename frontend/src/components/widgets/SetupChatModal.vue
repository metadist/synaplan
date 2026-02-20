<template>
  <Teleport to="#app">
    <div
      class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4"
      data-testid="modal-setup-chat"
    >
      <div
        class="surface-card rounded-2xl w-full max-w-2xl h-[80vh] max-h-[700px] overflow-hidden shadow-2xl flex flex-col"
        data-testid="section-setup-container"
      >
        <!-- Header -->
        <div
          class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between flex-shrink-0"
        >
          <div class="flex items-center gap-3">
            <div
              class="w-10 h-10 rounded-full bg-[var(--brand-alpha-light)] flex items-center justify-center"
            >
              <Icon icon="heroicons:sparkles" class="w-5 h-5 txt-brand" />
            </div>
            <div>
              <h2 class="text-lg font-semibold txt-primary">
                {{ $t('widgets.setupChat.title') }}
              </h2>
              <p class="text-xs txt-secondary">
                {{ $t('widgets.setupChat.subtitle') }}
              </p>
            </div>
          </div>
          <button
            class="w-9 h-9 rounded-lg hover-surface transition-colors flex items-center justify-center"
            :aria-label="$t('common.close')"
            data-testid="btn-close"
            @click="handleClose"
          >
            <Icon icon="heroicons:x-mark" class="w-5 h-5 txt-secondary" />
          </button>
        </div>

        <!-- Progress Indicator -->
        <div
          v-if="!setupComplete"
          class="px-6 py-3 border-b border-light-border/30 dark:border-dark-border/20 flex-shrink-0"
        >
          <div class="flex items-center gap-2 text-sm">
            <Icon icon="heroicons:chat-bubble-left-ellipsis" class="w-4 h-4 txt-brand" />
            <span class="txt-secondary">{{ $t('widgets.setupChat.progressLabel') }}</span>
            <div
              class="flex-1 h-1.5 bg-light-border/30 dark:bg-dark-border/20 rounded-full overflow-hidden"
            >
              <div
                class="h-full bg-[var(--brand)] transition-all duration-500"
                :style="{ width: `${progressPercent}%` }"
              ></div>
            </div>
            <span class="txt-secondary text-xs">{{ questionsAnswered }}/5</span>
          </div>
        </div>

        <!-- Chat Messages -->
        <div
          ref="messagesContainer"
          class="flex-1 overflow-y-auto scroll-thin p-4 space-y-4"
          data-testid="section-messages"
        >
          <div
            v-for="(message, index) in messages"
            :key="index"
            :class="['flex', message.role === 'user' ? 'justify-end' : 'justify-start']"
          >
            <div
              :class="[
                'max-w-[85%] rounded-2xl px-4 py-3',
                message.role === 'user'
                  ? 'bg-[var(--brand)] text-white rounded-br-md'
                  : 'surface-chip txt-primary rounded-bl-md',
              ]"
            >
              <p v-if="message.role === 'user'" class="text-sm whitespace-pre-wrap">{{ message.content }}</p>
              <div
                v-else
                class="text-sm prose prose-sm dark:prose-invert max-w-none"
                v-html="renderMarkdown(message.content)"
              />
            </div>
          </div>

          <!-- Typing Indicator -->
          <div v-if="isTyping" class="flex justify-start">
            <div class="surface-chip rounded-2xl rounded-bl-md px-4 py-3">
              <div class="flex items-center gap-1">
                <span
                  class="w-2 h-2 bg-[var(--brand)] rounded-full animate-bounce"
                  style="animation-delay: 0ms"
                ></span>
                <span
                  class="w-2 h-2 bg-[var(--brand)] rounded-full animate-bounce"
                  style="animation-delay: 150ms"
                ></span>
                <span
                  class="w-2 h-2 bg-[var(--brand)] rounded-full animate-bounce"
                  style="animation-delay: 300ms"
                ></span>
              </div>
            </div>
          </div>

          <!-- Setup Complete -->
          <div v-if="setupComplete" class="text-center py-6">
            <div
              class="w-16 h-16 rounded-full bg-green-500/20 flex items-center justify-center mx-auto mb-4"
            >
              <Icon icon="heroicons:check-circle" class="w-10 h-10 text-green-500" />
            </div>
            <h3 class="text-lg font-semibold txt-primary mb-2">
              {{ $t('widgets.setupChat.completeTitle') }}
            </h3>
            <p class="txt-secondary text-sm mb-4">
              {{ $t('widgets.setupChat.completeDescription') }}
            </p>

            <!-- Save Button -->
            <button
              :disabled="isSending"
              class="btn-primary px-6 py-2.5 rounded-lg font-medium disabled:opacity-50"
              data-testid="btn-save-prompt"
              @click="saveGeneratedPrompt"
            >
              <span v-if="isSending" class="flex items-center gap-2">
                <Icon icon="heroicons:arrow-path" class="w-4 h-4 animate-spin" />
                {{ $t('common.saving') }}
              </span>
              <span v-else>{{ $t('widgets.setupChat.saveButton') }}</span>
            </button>

            <!-- Edit Hint -->
            <div
              class="mt-6 p-4 rounded-lg bg-[var(--brand-alpha-light)] border border-[var(--brand)]/20"
            >
              <div class="flex items-start gap-3 text-left">
                <Icon icon="heroicons:light-bulb" class="w-5 h-5 txt-brand flex-shrink-0 mt-0.5" />
                <div>
                  <p class="text-sm font-medium txt-primary mb-1">
                    {{ $t('widgets.setupChat.editHintTitle') }}
                  </p>
                  <p class="text-xs txt-secondary">
                    {{ $t('widgets.setupChat.editHintDescription') }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Input Area -->
        <div
          v-if="!setupComplete"
          class="px-4 py-3 border-t border-light-border/30 dark:border-dark-border/20 flex-shrink-0"
        >
          <form class="flex items-end gap-3" @submit.prevent="sendMessage">
            <div class="flex-1">
              <textarea
                ref="inputRef"
                v-model="inputText"
                :placeholder="$t('widgets.setupChat.inputPlaceholder')"
                :disabled="isTyping || isSending"
                class="w-full px-4 py-3 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none transition-all disabled:opacity-50"
                rows="1"
                data-testid="input-message"
                @keydown.enter.exact.prevent="sendMessage"
                @input="autoResize"
              ></textarea>
            </div>
            <button
              type="submit"
              :disabled="!inputText.trim() || isTyping || isSending"
              class="w-14 h-14 rounded-xl bg-[var(--brand)] text-white flex items-center justify-center hover:bg-[var(--brand-hover)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex-shrink-0"
              data-testid="btn-send"
            >
              <Icon v-if="isSending" icon="heroicons:arrow-path" class="w-5 h-5 animate-spin" />
              <Icon v-else icon="heroicons:paper-airplane" class="w-5 h-5" />
            </button>
          </form>

          <!-- Skip Option -->
          <div class="mt-3 text-center">
            <button
              class="text-xs txt-secondary hover:txt-primary transition-colors"
              data-testid="btn-skip"
              @click="handleClose"
            >
              {{ $t('widgets.setupChat.skipButton') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useMarkdown } from '@/composables/useMarkdown'
import * as widgetsApi from '@/services/api/widgetsApi'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const props = defineProps<{
  widget: widgetsApi.Widget
}>()

const emit = defineEmits<{
  close: []
  completed: [promptTopic: string]
}>()

const { t, locale } = useI18n()
const { success, error: showError } = useNotification()
const { render: renderMarkdown } = useMarkdown()

interface Message {
  role: 'user' | 'assistant'
  content: string
}

const messages = ref<Message[]>([])
const inputText = ref('')
const isTyping = ref(false)
const isSending = ref(false)
const setupComplete = ref(false)
const generatedPrompt = ref<string | null>(null)
const questionsAnswered = ref(0)
const messagesContainer = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLTextAreaElement | null>(null)

// Conversation history - kept in memory, NOT stored in database
const conversationHistory = ref<{ role: 'user' | 'assistant'; content: string }[]>([])

const progressPercent = computed(() => Math.min(100, (questionsAnswered.value / 5) * 100))

const scrollToBottom = async () => {
  await nextTick()
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

const autoResize = () => {
  if (inputRef.value) {
    inputRef.value.style.height = 'auto'
    inputRef.value.style.height = Math.min(inputRef.value.scrollHeight, 120) + 'px'
  }
}

const handleClose = () => {
  emit('close')
}

const cleanResponseText = (text: string): string => {
  // Remove tracking markers and prompt markers from displayed text
  // Support both German (FRAGE) and English (QUESTION) markers
  return text
    .replace(/\[PROGRESS:\d+\/5\]/g, '')
    .replace(/\[FRAGE:\d+\]/gi, '')
    .replace(/\[FRAGE:DONE\]/gi, '')
    .replace(/\[QUESTION:\d+\]/gi, '')
    .replace(/\[QUESTION:DONE\]/gi, '')
    .replace(/<<<GENERATED_PROMPT>>>[\s\S]*?<<<END_PROMPT>>>/g, '')
    .trim()
}

const sendMessage = async () => {
  const text = inputText.value.trim()
  if (!text || isTyping.value || isSending.value) return

  // Add user message to display
  messages.value.push({ role: 'user', content: text })

  // Add to conversation history (for API calls)
  conversationHistory.value.push({ role: 'user', content: text })

  inputText.value = ''

  // Reset textarea height
  if (inputRef.value) {
    inputRef.value.style.height = 'auto'
  }

  await scrollToBottom()

  // Send to backend with full history
  isSending.value = true
  isTyping.value = true

  try {
    const response = await widgetsApi.sendSetupMessage(
      props.widget.widgetId,
      text,
      conversationHistory.value,
      locale.value
    )

    isTyping.value = false

    // Use progress from backend (calculated server-side)
    if (typeof response.progress === 'number') {
      questionsAnswered.value = response.progress
    }

    // Check for generated prompt marker
    const promptMatch = response.text.match(/<<<GENERATED_PROMPT>>>([\s\S]*?)<<<END_PROMPT>>>/)
    if (promptMatch) {
      generatedPrompt.value = promptMatch[1].trim()
      // Show message without markers
      const cleanMessage = cleanResponseText(response.text)
      if (cleanMessage) {
        messages.value.push({ role: 'assistant', content: cleanMessage })
        // Add to history
        conversationHistory.value.push({ role: 'assistant', content: response.text })
      }
      setupComplete.value = true
    } else {
      // Show message without progress marker
      const cleanMessage = cleanResponseText(response.text)
      if (cleanMessage) {
        messages.value.push({ role: 'assistant', content: cleanMessage })
        // Add to history (keep original text for AI context)
        conversationHistory.value.push({ role: 'assistant', content: response.text })
      }
    }

    await scrollToBottom()
  } catch (err: any) {
    console.error('Failed to send message:', err)
    showError(err.message || t('widgets.setupChat.sendError'))
    // Remove the user message from history on error
    conversationHistory.value.pop()
  } finally {
    isSending.value = false
    isTyping.value = false

    // Refocus input field after sending
    await nextTick()
    inputRef.value?.focus()
  }
}

const saveGeneratedPrompt = async () => {
  if (!generatedPrompt.value) return

  isSending.value = true
  try {
    const result = await widgetsApi.generateWidgetPrompt(
      props.widget.widgetId,
      generatedPrompt.value,
      conversationHistory.value
    )

    success(t('widgets.setupChat.saveSuccess'))
    emit('completed', result.promptTopic)
  } catch (err: any) {
    console.error('Failed to save prompt:', err)
    showError(err.message || t('widgets.setupChat.saveError'))
  } finally {
    isSending.value = false
  }
}

const startInterview = async () => {
  isTyping.value = true

  // Clear history for new interview
  conversationHistory.value = []

  try {
    // Send initial message to start the interview (pass app language)
    const response = await widgetsApi.sendSetupMessage(
      props.widget.widgetId,
      '__START_INTERVIEW__',
      [],
      locale.value
    )

    // Use progress from backend (calculated server-side)
    if (typeof response.progress === 'number') {
      questionsAnswered.value = response.progress
    }

    // Show message without progress marker
    const cleanMessage = cleanResponseText(response.text)
    if (cleanMessage) {
      messages.value.push({ role: 'assistant', content: cleanMessage })
      // Add to history (keep original for AI context)
      conversationHistory.value.push({ role: 'assistant', content: response.text })
    }

    await scrollToBottom()
  } catch (err: any) {
    console.error('Failed to start interview:', err)
    showError(err.message || t('widgets.setupChat.startError'))
  } finally {
    isTyping.value = false

    // Focus input field after interview starts
    await nextTick()
    inputRef.value?.focus()
  }
}

onMounted(() => {
  startInterview()
})
</script>
