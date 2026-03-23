<template>
  <div
    :class="[
      'flex flex-col h-full overflow-hidden transition-all duration-500',
      fullscreen
        ? 'max-w-2xl mx-auto w-full'
        : 'bg-chat rounded-2xl border border-light-border/30 dark:border-dark-border/20',
    ]"
  >
    <!-- Header -->
    <div
      :class="[
        'flex items-center justify-between flex-shrink-0 border-b border-light-border/30 dark:border-dark-border/20',
        fullscreen ? 'px-6 py-5' : 'px-4 py-3',
      ]"
    >
      <div class="flex items-center gap-3">
        <div
          :class="[
            'rounded-full bg-[var(--brand-alpha-light)] flex items-center justify-center',
            fullscreen ? 'w-10 h-10' : 'w-8 h-8',
          ]"
        >
          <Icon
            icon="heroicons:sparkles"
            :class="[fullscreen ? 'w-5 h-5' : 'w-4 h-4', 'txt-brand']"
          />
        </div>
        <div>
          <h3 :class="['font-semibold txt-primary', fullscreen ? 'text-lg' : 'text-sm']">
            {{ $t('widgets.detail.aiPanel.title') }}
          </h3>
          <p :class="['txt-secondary leading-tight', fullscreen ? 'text-xs' : 'text-[10px]']">
            {{ $t('widgets.detail.aiPanel.subtitle') }}
          </p>
        </div>
      </div>
      <button
        v-if="messages.length > 1"
        class="p-1.5 rounded-lg txt-secondary hover:txt-primary hover:bg-gray-100 dark:hover:bg-white/5 transition-colors"
        :title="$t('widgets.detail.aiPanel.restart')"
        @click="restartChat"
      >
        <Icon icon="heroicons:arrow-path" class="w-4 h-4" />
      </button>
    </div>

    <!-- Messages -->
    <div
      ref="messagesContainer"
      :class="[
        'flex-1 overflow-y-auto space-y-4 scroll-thin',
        fullscreen ? 'px-6 py-6' : 'px-4 py-4',
      ]"
    >
      <div
        v-for="msg in visibleMessages"
        :key="msg.id"
        :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']"
      >
        <div
          :class="[
            'rounded-2xl',
            fullscreen ? 'max-w-[75%] px-5 py-3 text-[15px]' : 'max-w-[85%] px-4 py-2.5 text-sm',
            msg.role === 'user'
              ? 'bg-[var(--brand)] text-white rounded-br-md'
              : 'bg-gray-100 dark:bg-white/5 txt-primary rounded-bl-md',
          ]"
        >
          <div
            v-if="msg.role === 'assistant'"
            class="break-words markdown-content"
            v-html="renderMarkdown(msg.displayText || msg.content)"
          ></div>
          <p v-else class="whitespace-pre-wrap break-words">
            {{ msg.displayText || msg.content }}
          </p>

          <div v-if="msg.flowItems && msg.flowItems.length > 0" class="mt-2.5 space-y-1.5">
            <div
              v-for="item in msg.flowItems"
              :key="item.trigger + item.response"
              :class="[
                'flex items-center gap-1.5 rounded-lg bg-[var(--brand)]/10 border border-[var(--brand)]/20',
                fullscreen ? 'text-sm px-3 py-2' : 'text-xs px-2.5 py-1.5',
              ]"
            >
              <Icon icon="heroicons:check-circle" class="w-3.5 h-3.5 txt-brand flex-shrink-0" />
              <span class="txt-primary font-medium truncate">{{ item.trigger }}</span>
              <Icon icon="heroicons:arrow-right" class="w-3 h-3 txt-secondary flex-shrink-0" />
              <span class="txt-secondary truncate">{{ item.response }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Typing indicator -->
      <div v-if="isTyping" class="flex justify-start">
        <div
          :class="[
            'bg-gray-100 dark:bg-white/5 rounded-2xl rounded-bl-md',
            fullscreen ? 'px-5 py-4' : 'px-4 py-3',
          ]"
        >
          <div class="flex gap-1">
            <span
              class="w-2 h-2 rounded-full bg-gray-400 animate-bounce"
              style="animation-delay: 0ms"
            />
            <span
              class="w-2 h-2 rounded-full bg-gray-400 animate-bounce"
              style="animation-delay: 150ms"
            />
            <span
              class="w-2 h-2 rounded-full bg-gray-400 animate-bounce"
              style="animation-delay: 300ms"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div
      v-if="messages.length <= 2 && !isTyping"
      :class="[
        'flex flex-wrap gap-1.5 border-t border-light-border/20 dark:border-dark-border/10',
        fullscreen ? 'px-6 pt-3 pb-0' : 'px-4 pt-2 pb-0',
      ]"
    >
      <button
        v-for="chip in quickChips"
        :key="chip.key"
        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border border-[var(--brand)]/20 bg-[var(--brand)]/5 txt-primary hover:bg-[var(--brand)]/15 transition-colors"
        @click="applyChip(chip)"
      >
        <Icon :icon="chip.icon" class="w-3.5 h-3.5 txt-brand" />
        {{ $t(chip.labelKey) }}
      </button>
    </div>

    <!-- Input -->
    <div
      :class="[
        'border-t border-light-border/30 dark:border-dark-border/20 flex-shrink-0',
        fullscreen ? 'px-6 py-4' : 'px-4 py-3',
      ]"
    >
      <form class="flex gap-2" @submit.prevent="send">
        <textarea
          ref="inputRef"
          v-model="inputText"
          :placeholder="$t('widgets.detail.aiPanel.placeholder')"
          :disabled="isTyping"
          rows="1"
          :class="[
            'flex-1 rounded-xl border border-light-border/30 dark:border-dark-border/20 surface-card txt-primary resize-none focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/40 disabled:opacity-50',
            fullscreen ? 'px-4 py-3 text-[15px]' : 'px-3 py-2 text-sm',
          ]"
          style="max-height: 100px"
          @keydown.enter.exact.prevent="send"
          @input="autoResize"
        />
        <button
          type="submit"
          :disabled="!inputText.trim() || isTyping"
          :class="[
            'rounded-xl bg-[var(--brand)] text-white hover:opacity-90 transition-opacity disabled:opacity-30 disabled:cursor-not-allowed flex-shrink-0',
            fullscreen ? 'px-4 py-3' : 'px-3 py-2',
          ]"
        >
          <Icon icon="heroicons:paper-airplane" :class="fullscreen ? 'w-5 h-5' : 'w-4 h-4'" />
        </button>
      </form>
    </div>

    <WidgetMemorySuggestionsModal
      ref="memoryModalRef"
      :suggestions="memorySuggestions"
      :visible="showMemoryModal"
      @close="handleMemoryModalClose"
      @apply="handleMemoryApply"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { Icon } from '@iconify/vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import type { MemorySuggestion } from '@/services/api/widgetsApi'
import WidgetMemorySuggestionsModal from './WidgetMemorySuggestionsModal.vue'
import { useI18n } from 'vue-i18n'
import { getMarkdownRenderer } from '@/composables/useMarkdown'

interface FlowNode {
  id: string
  label: string
  type?: 'link' | 'api' | 'text' | 'list' | 'pdf' | 'custom'
  meta?: { url?: string; method?: string }
}
interface FlowConnection {
  from: string
  to: string
}
interface FlowData {
  triggers: FlowNode[]
  responses: FlowNode[]
  connections: FlowConnection[]
  widgetName?: string
}

interface FlowItem {
  trigger: string
  response: string
}

interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  content: string
  displayText?: string
  flowItems?: FlowItem[]
}

const props = withDefaults(
  defineProps<{
    widgetId: string
    fullscreen?: boolean
    currentFlow?: { triggers: FlowNode[]; responses: FlowNode[]; connections: FlowConnection[] }
  }>(),
  { fullscreen: false, currentFlow: undefined }
)

const emit = defineEmits<{
  'update-flow': [data: FlowData]
  'first-flow-received': []
  'update-widget-name': [name: string]
  'open-settings': [tab: string]
}>()

const { t, locale } = useI18n()
const md = getMarkdownRenderer()

function renderMarkdown(text: string): string {
  return md.render(text)
}

const messages = ref<ChatMessage[]>([])
const inputText = ref('')
const isTyping = ref(false)
const messagesContainer = ref<HTMLElement | null>(null)
const inputRef = ref<HTMLTextAreaElement | null>(null)
const conversationHistory = ref<widgetsApi.SetupMessage[]>([])
const hasEmittedFirstFlow = ref(false)

const FLOW_START = '<<<FLOW_UPDATE>>>'
const FLOW_END = '<<<END_FLOW_UPDATE>>>'

const storageKey = computed(() => `synaplan-ai-setup-${props.widgetId}`)

const memorySuggestions = ref<MemorySuggestion[]>([])
const showMemoryModal = ref(false)
const memoryModalShown = ref(false)
const memoryModalRef = ref<InstanceType<typeof WidgetMemorySuggestionsModal> | null>(null)

const visibleMessages = computed(() => messages.value)

interface QuickChip {
  key: string
  icon: string
  labelKey: string
  prefill: string
}

const quickChips: QuickChip[] = [
  {
    key: 'website',
    icon: 'heroicons:globe-alt',
    labelKey: 'widgets.detail.aiPanel.chips.website',
    prefill: '',
  },
  {
    key: 'api',
    icon: 'heroicons:server-stack',
    labelKey: 'widgets.detail.aiPanel.chips.userApi',
    prefill: '',
  },
]

function applyChip(chip: QuickChip) {
  if (chip.key === 'website') {
    inputText.value = t('widgets.detail.aiPanel.chips.websitePrefill')
    nextTick(() => {
      inputRef.value?.focus()
      inputRef.value?.setSelectionRange(inputText.value.length, inputText.value.length)
    })
  } else if (chip.key === 'api') {
    emit('open-settings', 'security')
  }
}

function saveToSession() {
  try {
    sessionStorage.setItem(
      storageKey.value,
      JSON.stringify({
        messages: messages.value,
        history: conversationHistory.value,
        hasEmittedFirstFlow: hasEmittedFirstFlow.value,
        memoryModalShown: memoryModalShown.value,
      })
    )
  } catch {
    /* storage full or unavailable */
  }
}

function restoreFromSession(): boolean {
  try {
    const raw = sessionStorage.getItem(storageKey.value)
    if (!raw) return false
    const data = JSON.parse(raw) as {
      messages: ChatMessage[]
      history: widgetsApi.SetupMessage[]
      hasEmittedFirstFlow: boolean
      memoryModalShown?: boolean
    }
    if (!data.messages?.length) return false
    messages.value = data.messages
    conversationHistory.value = data.history || []
    hasEmittedFirstFlow.value = data.hasEmittedFirstFlow ?? false
    memoryModalShown.value = data.memoryModalShown ?? false
    if (hasEmittedFirstFlow.value) {
      emit('first-flow-received')
    }
    return true
  } catch {
    return false
  }
}

function clearSession() {
  try {
    sessionStorage.removeItem(storageKey.value)
  } catch {
    /* ignore */
  }
}

function parseFlowUpdate(text: string): {
  displayText: string
  flowData: FlowData | null
  flowItems: FlowItem[]
} {
  const startIdx = text.indexOf(FLOW_START)
  const endIdx = text.indexOf(FLOW_END)

  if (startIdx === -1 || endIdx === -1 || endIdx <= startIdx) {
    return { displayText: text, flowData: null, flowItems: [] }
  }

  const jsonStr = text.substring(startIdx + FLOW_START.length, endIdx).trim()
  const displayText = (
    text.substring(0, startIdx) + text.substring(endIdx + FLOW_END.length)
  ).trim()

  try {
    const parsed = JSON.parse(jsonStr) as FlowData
    const flowItems: FlowItem[] = []
    for (const conn of parsed.connections || []) {
      const trig = parsed.triggers?.find((tr) => tr.id === conn.from)
      const resp = parsed.responses?.find((r) => r.id === conn.to)
      if (trig && resp) {
        flowItems.push({ trigger: trig.label, response: resp.label })
      }
    }
    return { displayText, flowData: parsed, flowItems }
  } catch {
    return { displayText: text, flowData: null, flowItems: [] }
  }
}

function handleFlowData(flowData: FlowData) {
  emit('update-flow', flowData)
  if (flowData.widgetName) {
    emit('update-widget-name', flowData.widgetName)
  }
  if (!hasEmittedFirstFlow.value) {
    hasEmittedFirstFlow.value = true
    emit('first-flow-received')
  }
}

async function fetchMemorySuggestions() {
  if (memoryModalShown.value) return
  try {
    const suggestions = await widgetsApi.suggestMemories(props.widgetId)
    if (suggestions.length > 0) {
      memorySuggestions.value = suggestions
      showMemoryModal.value = true
      memoryModalShown.value = true
      saveToSession()
      nextTick(() => memoryModalRef.value?.initSelection(suggestions))
    }
  } catch (err) {
    console.warn('[WidgetAiSetup] Memory suggestions unavailable:', err)
  }
}

function handleMemoryModalClose() {
  showMemoryModal.value = false
}

async function handleMemoryApply(accepted: MemorySuggestion[]) {
  showMemoryModal.value = false
  if (accepted.length === 0) return

  const lines = accepted.map((m) => {
    if (m.responseType === 'link' && m.meta?.url) {
      return `${m.widgetField}: ${m.meta.url}`
    }
    return `${m.widgetField}: ${m.value}`
  })

  const contextMessage =
    `Here is information from my stored profile that should be used for the widget:\n` +
    lines.join('\n') +
    '\n\nPlease create Q&A entries from ALL of this information. Use the correct response types (link for URLs, text for descriptions, etc.).'

  messages.value.push({
    id: `msg-${Date.now()}-memory`,
    role: 'user',
    content: contextMessage,
    displayText: t('widgets.detail.memorySuggestions.appliedMessage', { count: accepted.length }),
  })

  conversationHistory.value.push({ role: 'user', content: contextMessage })
  saveToSession()
  isTyping.value = true
  scrollToBottom()

  try {
    const result = await widgetsApi.sendSetupMessage(
      props.widgetId,
      contextMessage,
      conversationHistory.value,
      locale.value,
      'flow-builder',
      props.currentFlow
    )

    const { displayText, flowData, flowItems } = parseFlowUpdate(result.text)

    messages.value.push({
      id: `msg-${Date.now()}-ai-mem`,
      role: 'assistant',
      content: result.text,
      displayText,
      flowItems,
    })

    conversationHistory.value.push({ role: 'assistant', content: result.text })
    saveToSession()

    if (flowData) {
      handleFlowData(flowData)
    }
  } catch {
    messages.value.push({
      id: `msg-${Date.now()}-err`,
      role: 'assistant',
      content: t('widgets.detail.aiPanel.error'),
    })
  } finally {
    isTyping.value = false
    scrollToBottom()
  }
}

function scrollToBottom() {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

function autoResize() {
  if (inputRef.value) {
    inputRef.value.style.height = 'auto'
    inputRef.value.style.height = Math.min(inputRef.value.scrollHeight, 100) + 'px'
  }
}

async function send() {
  const text = inputText.value.trim()
  if (!text || isTyping.value) return

  messages.value.push({
    id: `msg-${Date.now()}`,
    role: 'user',
    content: text,
  })

  conversationHistory.value.push({ role: 'user', content: text })
  saveToSession()
  inputText.value = ''
  if (inputRef.value) inputRef.value.style.height = 'auto'
  isTyping.value = true
  scrollToBottom()

  try {
    const result = await widgetsApi.sendSetupMessage(
      props.widgetId,
      text,
      conversationHistory.value,
      locale.value,
      'flow-builder',
      props.currentFlow
    )

    const { displayText, flowData, flowItems } = parseFlowUpdate(result.text)

    messages.value.push({
      id: `msg-${Date.now()}-ai`,
      role: 'assistant',
      content: result.text,
      displayText,
      flowItems,
    })

    conversationHistory.value.push({ role: 'assistant', content: result.text })
    saveToSession()

    if (flowData) {
      handleFlowData(flowData)
    }
  } catch {
    messages.value.push({
      id: `msg-${Date.now()}-err`,
      role: 'assistant',
      content: t('widgets.detail.aiPanel.error'),
    })
  } finally {
    isTyping.value = false
    scrollToBottom()
  }
}

async function startChat() {
  if (restoreFromSession()) {
    scrollToBottom()
    if (!memoryModalShown.value) {
      fetchMemorySuggestions()
    }
    return
  }

  isTyping.value = true
  try {
    const result = await widgetsApi.sendSetupMessage(
      props.widgetId,
      '__START_FLOW_BUILDER__',
      [],
      locale.value,
      'flow-builder',
      props.currentFlow
    )

    const { displayText, flowData, flowItems } = parseFlowUpdate(result.text)

    messages.value.push({
      id: `msg-init`,
      role: 'assistant',
      content: result.text,
      displayText,
      flowItems,
    })

    conversationHistory.value.push({ role: 'assistant', content: result.text })
    saveToSession()

    if (flowData) {
      handleFlowData(flowData)
    }

    fetchMemorySuggestions()
  } catch {
    messages.value.push({
      id: `msg-init-err`,
      role: 'assistant',
      content: t('widgets.detail.aiPanel.error'),
    })
  } finally {
    isTyping.value = false
    scrollToBottom()
  }
}

function restartChat() {
  messages.value = []
  conversationHistory.value = []
  hasEmittedFirstFlow.value = false
  memoryModalShown.value = false
  showMemoryModal.value = false
  clearSession()
  startChat()
}

watch(
  () => props.widgetId,
  () => {
    restartChat()
  }
)

onMounted(() => {
  startChat()
})
</script>
