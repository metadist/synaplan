<template>
  <div
    class="surface-card overflow-hidden flex h-[calc(100vh-16rem)] min-h-[28rem]"
    data-testid="page-live-support"
  >
    <!-- Session list; on small screens it gives way to the open conversation. -->
    <div
      class="w-full md:w-80 md:flex-shrink-0 border-r border-light-border/30 dark:border-dark-border/20 flex-col"
      :class="selectedSession ? 'hidden md:flex' : 'flex'"
    >
      <div class="p-4 border-b border-light-border/30 dark:border-dark-border/20 space-y-3">
        <div class="flex items-start justify-between gap-2">
          <p class="text-xs txt-secondary">{{ $t('liveSupport.subtitle') }}</p>
          <ConnectionStatusBadge />
        </div>
        <select
          v-model="selectedWidgetId"
          :aria-label="$t('liveSupport.allWidgets')"
          class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="select-live-support-widget"
          @change="loadSessions"
        >
          <option value="">{{ $t('liveSupport.allWidgets') }}</option>
          <option v-for="widget in widgets" :key="widget.widgetId" :value="widget.widgetId">
            {{ widget.name }}
          </option>
        </select>
      </div>

      <div class="tab-nav !mb-0 px-2 pt-2" role="tablist" :aria-label="$t('liveSupport.title')">
        <button
          type="button"
          role="tab"
          :aria-selected="activeTab === 'waiting'"
          :class="[
            'tab-nav-item flex-1 justify-center',
            activeTab === 'waiting' && 'tab-nav-item--active',
          ]"
          data-testid="tab-live-support-waiting"
          @click="activeTab = 'waiting'"
        >
          {{ $t('liveSupport.waiting') }}
          <span
            v-if="waitingCount > 0"
            class="ml-1 px-1.5 py-0.5 rounded-full bg-[var(--status-warning-muted)] text-[var(--status-warning-text)] text-xs"
          >
            {{ waitingCount }}
          </span>
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="activeTab === 'active'"
          :class="[
            'tab-nav-item flex-1 justify-center',
            activeTab === 'active' && 'tab-nav-item--active',
          ]"
          data-testid="tab-live-support-mine"
          @click="activeTab = 'active'"
        >
          {{ $t('liveSupport.myChats') }}
          <span
            v-if="activeCount > 0"
            class="ml-1 px-1.5 py-0.5 rounded-full bg-[var(--status-success-muted)] text-[var(--status-success-text)] text-xs"
          >
            {{ activeCount }}
          </span>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto scroll-thin">
        <div v-if="loading" class="p-4 text-center">
          <div
            class="animate-spin w-6 h-6 border-2 border-[var(--brand)] border-t-transparent rounded-full mx-auto"
          ></div>
        </div>

        <div
          v-else-if="loadFailed"
          class="p-4 text-center space-y-3"
          data-testid="live-support-load-error"
        >
          <p class="text-sm txt-secondary">{{ $t('liveSupport.loadFailed') }}</p>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium"
            @click="reload"
          >
            {{ $t('common.retry') }}
          </button>
        </div>

        <p
          v-else-if="filteredSessions.length === 0"
          class="p-4 text-center text-sm txt-secondary"
          data-testid="live-support-empty"
        >
          {{ activeTab === 'waiting' ? $t('liveSupport.noSessions') : $t('liveSupport.noMine') }}
        </p>

        <div v-else>
          <button
            v-for="session in filteredSessions"
            :key="session.id"
            type="button"
            :class="[
              'w-full text-left p-3 border-b border-light-border/20 dark:border-dark-border/10 transition-colors',
              selectedSession?.id === session.id
                ? 'bg-[var(--brand-alpha-light)]'
                : 'hover:bg-black/5 dark:hover:bg-white/5',
            ]"
            data-testid="item-live-support-session"
            @click="selectSession(session)"
          >
            <span class="flex items-center justify-between mb-1">
              <span class="text-xs font-mono txt-secondary truncate max-w-[120px]">
                {{ session.sessionIdDisplay || session.sessionId }}
              </span>
              <span
                :class="[
                  'px-1.5 py-0.5 rounded text-xs font-medium',
                  session.mode === 'waiting'
                    ? 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]'
                    : 'bg-[var(--status-success-muted)] text-[var(--status-success-text)]',
                ]"
              >
                {{
                  session.mode === 'waiting' ? $t('liveSupport.waiting') : $t('liveSupport.active')
                }}
              </span>
            </span>
            <span class="block text-sm txt-primary line-clamp-2 mb-1">
              {{ session.lastMessagePreview || $t('liveSupport.noMessages') }}
            </span>
            <span class="block text-xs txt-secondary">{{ formatTime(session.lastMessage) }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Open conversation -->
    <div class="flex-1 min-w-0 flex-col" :class="selectedSession ? 'flex' : 'hidden md:flex'">
      <template v-if="selectedSession">
        <div
          class="p-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between gap-3"
        >
          <div class="flex items-center gap-2 min-w-0">
            <button
              type="button"
              class="md:hidden icon-ghost p-1.5 rounded-lg"
              :aria-label="$t('liveSupport.backToList')"
              data-testid="btn-live-support-back"
              @click="selectedSession = null"
            >
              <Icon icon="heroicons:arrow-left" class="w-5 h-5" />
            </button>
            <div class="min-w-0">
              <h2 class="text-base font-semibold txt-primary truncate">
                {{ $t('liveSupport.chatWith') }}
                {{ selectedSession.sessionIdDisplay || selectedSession.sessionId }}
              </h2>
              <p class="text-xs txt-secondary">
                {{ selectedSession.messageCount }} {{ $t('liveSupport.messagesCount') }}
              </p>
            </div>
          </div>
          <button
            v-if="selectedSession.mode === 'human'"
            type="button"
            class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2 flex-shrink-0"
            data-testid="btn-live-support-hand-back"
            @click="handBackToAi"
          >
            <Icon icon="heroicons:arrow-uturn-left" class="w-4 h-4" />
            {{ $t('liveSupport.handBack') }}
          </button>
        </div>

        <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3 scroll-thin">
          <div v-if="loadingMessages" class="text-center py-8">
            <div
              class="animate-spin w-6 h-6 border-2 border-[var(--brand)] border-t-transparent rounded-full mx-auto"
            ></div>
          </div>
          <template v-else>
            <div
              v-for="message in sessionMessages"
              :key="message.id"
              :class="[
                'p-3 rounded-lg max-w-[80%]',
                message.direction === 'IN'
                  ? 'bg-[var(--brand-alpha-light)] ml-auto'
                  : 'surface-chip',
              ]"
            >
              <p class="text-xs txt-secondary mb-1">
                {{ message.direction === 'IN' ? $t('liveSupport.visitor') : $t('liveSupport.you') }}
                · {{ formatMessageTime(message.timestamp) }}
              </p>
              <p
                class="txt-primary text-sm whitespace-pre-wrap"
                data-quotable
                :data-message-id="message.id"
                :data-message-role="message.direction === 'IN' ? 'visitor' : 'agent'"
              >
                {{ message.text }}
              </p>
            </div>
          </template>
        </div>

        <div class="p-4 border-t border-light-border/30 dark:border-dark-border/20">
          <QuoteChip
            v-if="quoting.pendingQuote.value"
            :quote="quoting.pendingQuote.value"
            class="mb-2"
            @remove="quoting.clearPendingQuote"
          />
          <div class="flex gap-2">
            <textarea
              ref="replyInputRef"
              v-model="replyText"
              :placeholder="$t('liveSupport.typePlaceholder')"
              :aria-label="$t('liveSupport.typePlaceholder')"
              rows="2"
              class="flex-1 min-w-0 px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm resize-none focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-live-support-reply"
              @keydown.enter.ctrl.prevent="sendReply"
            ></textarea>
            <button
              type="button"
              :disabled="!replyText.trim() || sending"
              :aria-label="$t('liveSupport.send')"
              class="btn-primary px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="btn-live-support-send"
              @click="sendReply"
            >
              <Icon v-if="sending" icon="heroicons:arrow-path" class="w-5 h-5 animate-spin" />
              <Icon v-else icon="heroicons:arrow-up" class="w-5 h-5" />
            </button>
          </div>
        </div>
      </template>

      <div v-else class="flex-1 flex items-center justify-center p-6">
        <div class="text-center">
          <Icon
            icon="heroicons:chat-bubble-left-ellipsis"
            class="w-16 h-16 txt-secondary opacity-20 mx-auto mb-4"
          />
          <p class="txt-secondary">{{ $t('liveSupport.selectSession') }}</p>
        </div>
      </div>
    </div>

    <QuoteSelectionButton
      :visible="quoting.floatingVisible.value"
      :position="quoting.floatingPosition.value"
      @quote="quoting.confirmQuote"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import * as widgetsApi from '@/services/api/widgetsApi'
import * as widgetSessionsApi from '@/services/api/widgetSessionsApi'
import { useNotification } from '@/composables/useNotification'
import {
  subscribeToWidgetOperatorChannel,
  type WidgetSubscription,
} from '@/services/realtime/widgetOperatorRealtime'
import ConnectionStatusBadge from '@/components/realtime/ConnectionStatusBadge.vue'
import QuoteSelectionButton from '@/components/QuoteSelectionButton.vue'
import QuoteChip from '@/components/QuoteChip.vue'
import { useMessageQuoting, formatQuoteAsBlockquote } from '@/composables/useMessageQuoting'

/** Widgets the current user owns; the parent page already loaded them. */
const props = defineProps<{
  widgets: widgetsApi.Widget[]
}>()

const emit = defineEmits<{
  'waiting-count': [count: number]
}>()

const SESSIONS_PER_LIST = 50

const { t } = useI18n()
const { formatRelativeTime, formatTime: formatTimeStr } = useDateFormat()
const { success, error } = useNotification()

const selectedWidgetId = ref('')
const sessions = ref<widgetSessionsApi.WidgetSession[]>([])
const selectedSession = ref<widgetSessionsApi.WidgetSession | null>(null)
const sessionMessages = ref<widgetSessionsApi.SessionMessage[]>([])
const loading = ref(false)
const loadFailed = ref(false)
const loadingMessages = ref(false)
const activeTab = ref<'waiting' | 'active'>('waiting')
const replyText = ref('')
const replyInputRef = ref<HTMLTextAreaElement | null>(null)
const sending = ref(false)
const messagesContainer = ref<HTMLElement | null>(null)
const quoting = useMessageQuoting(messagesContainer)

let notificationSubscriptions: WidgetSubscription[] = []

const waitingCount = computed(() => sessions.value.filter((s) => s.mode === 'waiting').length)
const activeCount = computed(() => sessions.value.filter((s) => s.mode === 'human').length)

const filteredSessions = computed(() =>
  sessions.value.filter((s) => s.mode === (activeTab.value === 'waiting' ? 'waiting' : 'human'))
)

watch(waitingCount, (count) => {
  if (!selectedWidgetId.value) emit('waiting-count', count)
})

function widgetIdFor(session: widgetSessionsApi.WidgetSession): string | undefined {
  return selectedWidgetId.value || session.widgetId || props.widgets[0]?.widgetId
}

function subscribe(): void {
  // All channels share the realtime store's single Centrifuge connection.
  for (const widget of props.widgets) {
    notificationSubscriptions.push(
      subscribeToWidgetOperatorChannel(widget.widgetId, handleNotification)
    )
  }
}

function unsubscribe(): void {
  for (const sub of notificationSubscriptions) sub.unsubscribe()
  notificationSubscriptions = []
}

async function loadSessions(): Promise<void> {
  loading.value = true
  try {
    const targets = selectedWidgetId.value
      ? props.widgets.filter((widget) => widget.widgetId === selectedWidgetId.value)
      : props.widgets
    // Both lists are fetched by mode so a waiting visitor is never pushed out by newer AI chats.
    const responses = await Promise.all(
      targets.flatMap((widget) =>
        (['waiting', 'human'] as const).map(async (mode) => {
          const response = await widgetSessionsApi.listWidgetSessions(widget.widgetId, {
            mode,
            limit: SESSIONS_PER_LIST,
          })
          return response.sessions.map((s) => ({ ...s, widgetId: widget.widgetId }))
        })
      )
    )
    sessions.value = responses.flat().sort((a, b) => b.lastMessage - a.lastMessage)
    loadFailed.value = false
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

async function reload(): Promise<void> {
  await loadSessions()
}

async function selectSession(session: widgetSessionsApi.WidgetSession): Promise<void> {
  selectedSession.value = session
  const widgetId = widgetIdFor(session)
  if (!widgetId) return
  loadingMessages.value = true
  try {
    const response = await widgetSessionsApi.getWidgetSession(widgetId, session.sessionId)
    sessionMessages.value = response.messages
    await nextTick()
    scrollToBottom()
  } catch {
    error(t('liveSupport.loadMessagesFailed'))
  } finally {
    loadingMessages.value = false
  }
}

async function sendReply(): Promise<void> {
  const session = selectedSession.value
  if (!replyText.value.trim() || !session) return
  const widgetId = widgetIdFor(session)
  if (!widgetId) return

  const quote = quoting.pendingQuote.value
  const outgoingText = quote
    ? `${formatQuoteAsBlockquote(quote.text)}\n\n${replyText.value}`
    : replyText.value

  sending.value = true
  let tookOver = false
  try {
    if (session.mode !== 'human') {
      await widgetSessionsApi.takeOverSession(widgetId, session.sessionId)
      session.mode = 'human'
      tookOver = true
    }
    await widgetSessionsApi.sendHumanMessage(widgetId, session.sessionId, outgoingText)
    sessionMessages.value.push({
      id: Date.now(),
      direction: 'OUT',
      text: outgoingText,
      timestamp: Math.floor(Date.now() / 1000),
      sender: 'human',
    })
    replyText.value = ''
    quoting.clearPendingQuote()
    await nextTick()
    scrollToBottom()
    success(t('liveSupport.messageSent'))
  } catch {
    error(tookOver ? t('liveSupport.sendFailedTakenOver') : t('liveSupport.sendFailed'))
  } finally {
    sending.value = false
    // The send button takes focus; put the cursor back into the reply box.
    await nextTick()
    replyInputRef.value?.focus()
  }
}

async function handBackToAi(): Promise<void> {
  const session = selectedSession.value
  if (!session) return
  const widgetId = widgetIdFor(session)
  if (!widgetId) return
  try {
    await widgetSessionsApi.handBackSession(widgetId, session.sessionId)
    session.mode = 'ai'
    success(t('liveSupport.handBackSuccess'))
    void loadSessions()
  } catch {
    error(t('liveSupport.handBackFailed'))
  }
}

// Operator-channel envelopes arrive flattened as { type, ...data }; the payload
// kind discriminates (see WidgetPublicController's human-mode branch).
function handleNotification(data: { type?: string; kind?: string }): void {
  if (data.type === 'notification' && data.kind === 'new_message') {
    void loadSessions()
  }
}

function scrollToBottom(): void {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

function formatTime(timestamp: number): string {
  if (!timestamp) return '-'
  return formatRelativeTime(new Date(timestamp * 1000))
}

function formatMessageTime(timestamp: number): string {
  return formatTimeStr(new Date(timestamp * 1000))
}

onMounted(async () => {
  subscribe()
  await loadSessions()
})

onBeforeUnmount(unsubscribe)
</script>
