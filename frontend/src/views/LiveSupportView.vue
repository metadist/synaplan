<template>
  <MainLayout>
    <div class="h-full flex bg-chat" data-testid="page-live-support">
      <!-- Left Panel: Session List -->
      <div class="w-80 border-r border-light-border/30 dark:border-dark-border/20 flex flex-col">
        <!-- Header -->
        <div class="p-4 border-b border-light-border/30 dark:border-dark-border/20">
          <h1 class="text-lg font-semibold txt-primary flex items-center gap-2">
            <Icon icon="heroicons:chat-bubble-left-ellipsis" class="w-5 h-5 txt-brand" />
            {{ $t('liveSupport.title') }}
          </h1>
          <p class="text-xs txt-secondary mt-1">{{ $t('liveSupport.subtitle') }}</p>
        </div>

        <!-- Widget Selector -->
        <div class="p-3 border-b border-light-border/30 dark:border-dark-border/20">
          <select
            v-model="selectedWidgetId"
            class="w-full px-3 py-2 rounded-lg surface-chip text-sm txt-primary"
            @change="loadSessions"
          >
            <option value="">{{ $t('liveSupport.allWidgets') }}</option>
            <option v-for="widget in widgets" :key="widget.widgetId" :value="widget.widgetId">
              {{ widget.name }}
            </option>
          </select>
        </div>

        <!-- Session Tabs -->
        <div class="flex border-b border-light-border/30 dark:border-dark-border/20">
          <button
            :class="[
              'flex-1 px-3 py-2 text-xs font-medium transition-colors',
              activeTab === 'waiting'
                ? 'txt-brand border-b-2 border-[var(--brand)]'
                : 'txt-secondary hover:txt-primary',
            ]"
            @click="activeTab = 'waiting'"
          >
            {{ $t('liveSupport.waiting') }}
            <span
              v-if="waitingCount > 0"
              class="ml-1 px-1.5 py-0.5 rounded-full bg-yellow-500/20 text-yellow-600 text-xs"
            >
              {{ waitingCount }}
            </span>
          </button>
          <button
            :class="[
              'flex-1 px-3 py-2 text-xs font-medium transition-colors',
              activeTab === 'active'
                ? 'txt-brand border-b-2 border-[var(--brand)]'
                : 'txt-secondary hover:txt-primary',
            ]"
            @click="activeTab = 'active'"
          >
            {{ $t('liveSupport.myChats') }}
            <span
              v-if="activeCount > 0"
              class="ml-1 px-1.5 py-0.5 rounded-full bg-green-500/20 text-green-600 text-xs"
            >
              {{ activeCount }}
            </span>
          </button>
        </div>

        <!-- Session List -->
        <div class="flex-1 overflow-y-auto scroll-thin">
          <div v-if="loading" class="p-4 text-center">
            <div
              class="animate-spin w-6 h-6 border-2 border-[var(--brand)] border-t-transparent rounded-full mx-auto"
            ></div>
          </div>

          <div v-else-if="filteredSessions.length === 0" class="p-4 text-center">
            <Icon icon="heroicons:inbox" class="w-10 h-10 txt-secondary opacity-30 mx-auto mb-2" />
            <p class="text-sm txt-secondary">{{ $t('liveSupport.noSessions') }}</p>
          </div>

          <div v-else>
            <div
              v-for="session in filteredSessions"
              :key="session.id"
              :class="[
                'p-3 border-b border-light-border/20 dark:border-dark-border/10 cursor-pointer transition-colors',
                selectedSession?.id === session.id
                  ? 'bg-[var(--brand-alpha-light)]'
                  : 'hover:bg-black/5 dark:hover:bg-white/5',
              ]"
              @click="selectSession(session)"
            >
              <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-mono txt-secondary truncate max-w-[120px]">
                  {{ session.sessionIdDisplay || session.sessionId }}
                </span>
                <span
                  :class="[
                    'px-1.5 py-0.5 rounded text-xs font-medium',
                    session.mode === 'waiting'
                      ? 'bg-yellow-500/10 text-yellow-600'
                      : 'bg-green-500/10 text-green-600',
                  ]"
                >
                  {{
                    session.mode === 'waiting'
                      ? $t('liveSupport.waiting')
                      : $t('liveSupport.active')
                  }}
                </span>
              </div>
              <p class="text-sm txt-primary line-clamp-2 mb-1">
                {{ session.lastMessagePreview || $t('liveSupport.noMessages') }}
              </p>
              <p class="text-xs txt-secondary">
                {{ formatTime(session.lastMessage) }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Panel: Chat -->
      <div class="flex-1 flex flex-col">
        <template v-if="selectedSession">
          <!-- Chat Header -->
          <div
            class="p-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between"
          >
            <div>
              <h2 class="text-base font-semibold txt-primary">
                {{ $t('liveSupport.chatWith') }}
                {{ selectedSession.sessionIdDisplay || selectedSession.sessionId }}
              </h2>
              <p class="text-xs txt-secondary">
                {{ selectedSession.messageCount }} {{ $t('liveSupport.messagesCount') }}
              </p>
            </div>
            <button
              v-if="selectedSession.mode === 'human'"
              class="px-3 py-1.5 rounded-lg bg-blue-500/10 text-blue-600 text-sm font-medium hover:bg-blue-500/20 transition-colors"
              @click="handBackToAi"
            >
              <Icon icon="heroicons:arrow-uturn-left" class="w-4 h-4 inline mr-1" />
              {{ $t('liveSupport.handBack') }}
            </button>
          </div>

          <!-- Messages -->
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
                  message.direction === 'IN' ? 'bg-[var(--brand)]/10 ml-auto' : 'surface-chip',
                ]"
              >
                <p class="text-xs txt-secondary mb-1">
                  {{
                    message.direction === 'IN' ? $t('liveSupport.visitor') : $t('liveSupport.you')
                  }}
                  · {{ formatMessageTime(message.timestamp) }}
                </p>
                <p class="txt-primary text-sm whitespace-pre-wrap">{{ message.text }}</p>
              </div>
            </template>
          </div>

          <!-- Input -->
          <div class="p-4 border-t border-light-border/30 dark:border-dark-border/20">
            <div class="flex gap-2">
              <textarea
                v-model="replyText"
                :placeholder="$t('liveSupport.typePlaceholder')"
                rows="2"
                class="flex-1 px-3 py-2 rounded-lg surface-chip txt-primary resize-none focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                @keydown.enter.ctrl.prevent="sendReply"
              ></textarea>
              <button
                :disabled="!replyText.trim() || sending"
                class="px-4 py-2 rounded-lg btn-primary disabled:opacity-50 disabled:cursor-not-allowed"
                @click="sendReply"
              >
                <Icon v-if="sending" icon="heroicons:arrow-path" class="w-5 h-5 animate-spin" />
                <Icon v-else icon="heroicons:paper-airplane" class="w-5 h-5" />
              </button>
            </div>
          </div>
        </template>

        <!-- Empty State -->
        <div v-else class="flex-1 flex items-center justify-center">
          <div class="text-center">
            <Icon
              icon="heroicons:chat-bubble-left-ellipsis"
              class="w-16 h-16 txt-secondary opacity-20 mx-auto mb-4"
            />
            <p class="txt-secondary">{{ $t('liveSupport.selectSession') }}</p>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import * as widgetSessionsApi from '@/services/api/widgetSessionsApi'
import { useNotification } from '@/composables/useNotification'
import { subscribeToNotifications, type EventSubscription } from '@/services/sseClient'

const { t } = useI18n()
const { success, error } = useNotification()

const widgets = ref<widgetsApi.Widget[]>([])
const selectedWidgetId = ref('')
const sessions = ref<widgetSessionsApi.WidgetSession[]>([])
const selectedSession = ref<widgetSessionsApi.WidgetSession | null>(null)
const sessionMessages = ref<widgetSessionsApi.SessionMessage[]>([])
const loading = ref(false)
const loadingMessages = ref(false)
const activeTab = ref<'waiting' | 'active'>('waiting')
const replyText = ref('')
const sending = ref(false)
const messagesContainer = ref<HTMLElement | null>(null)

let notificationSubscriptions: EventSubscription[] = []

const waitingCount = computed(() => sessions.value.filter((s) => s.mode === 'waiting').length)
const activeCount = computed(() => sessions.value.filter((s) => s.mode === 'human').length)

const filteredSessions = computed(() => {
  if (activeTab.value === 'waiting') {
    return sessions.value.filter((s) => s.mode === 'waiting')
  }
  return sessions.value.filter((s) => s.mode === 'human')
})

const loadWidgets = async () => {
  try {
    widgets.value = await widgetsApi.listWidgets()
    // Subscribe to notifications for all widgets
    for (const widget of widgets.value) {
      const sub = subscribeToNotifications(
        widget.widgetId,
        handleNotification
      )
      notificationSubscriptions.push(sub)
    }
  } catch (err: any) {
    error(err.message || 'Failed to load widgets')
  }
}

const loadSessions = async () => {
  loading.value = true
  try {
    if (selectedWidgetId.value) {
      const response = await widgetSessionsApi.listWidgetSessions(selectedWidgetId.value, {
        mode: activeTab.value === 'waiting' ? 'waiting' : 'human',
        limit: 50,
      })
      sessions.value = response.sessions
    } else {
      // Load sessions from all widgets
      sessions.value = []
      for (const widget of widgets.value) {
        const response = await widgetSessionsApi.listWidgetSessions(widget.widgetId, {
          limit: 20,
        })
        sessions.value.push(...response.sessions.map((s) => ({ ...s, widgetId: widget.widgetId })))
      }
      sessions.value.sort((a, b) => b.lastMessage - a.lastMessage)
    }
  } catch (err: any) {
    error(err.message || 'Failed to load sessions')
  } finally {
    loading.value = false
  }
}

const selectSession = async (session: widgetSessionsApi.WidgetSession) => {
  selectedSession.value = session
  loadingMessages.value = true

  try {
    // Find the widget ID for this session
    const widgetId =
      selectedWidgetId.value || (session as any).widgetId || widgets.value[0]?.widgetId
    if (!widgetId) return

    const response = await widgetSessionsApi.getWidgetSession(widgetId, session.sessionId)
    sessionMessages.value = response.messages
    await nextTick()
    scrollToBottom()
  } catch (err: any) {
    error(err.message || 'Failed to load messages')
  } finally {
    loadingMessages.value = false
  }
}

const sendReply = async () => {
  if (!replyText.value.trim() || !selectedSession.value) return

  const widgetId =
    selectedWidgetId.value || (selectedSession.value as any).widgetId || widgets.value[0]?.widgetId
  if (!widgetId) return

  sending.value = true
  try {
    // Take over if not already in human mode
    if (selectedSession.value.mode !== 'human') {
      await widgetSessionsApi.takeOverSession(widgetId, selectedSession.value.sessionId)
      selectedSession.value.mode = 'human'
    }

    await widgetSessionsApi.sendHumanMessage(
      widgetId,
      selectedSession.value.sessionId,
      replyText.value
    )

    // Add message to local list
    sessionMessages.value.push({
      id: Date.now(),
      direction: 'OUT',
      text: replyText.value,
      timestamp: Math.floor(Date.now() / 1000),
      sender: 'human',
    })

    replyText.value = ''
    await nextTick()
    scrollToBottom()
    success(t('liveSupport.messageSent'))
  } catch (err: any) {
    error(err.message || 'Failed to send message')
  } finally {
    sending.value = false
  }
}

const handBackToAi = async () => {
  if (!selectedSession.value) return

  const widgetId =
    selectedWidgetId.value || (selectedSession.value as any).widgetId || widgets.value[0]?.widgetId
  if (!widgetId) return

  try {
    await widgetSessionsApi.handBackSession(widgetId, selectedSession.value.sessionId)
    selectedSession.value.mode = 'ai'
    success(t('liveSupport.handBackSuccess'))
    loadSessions()
  } catch (err: any) {
    error(err.message || 'Failed to hand back to AI')
  }
}

const handleNotification = (data: any) => {
  if (data.type === 'new_message') {
    // Reload sessions to show new messages
    loadSessions()
  }
}

const scrollToBottom = () => {
  if (messagesContainer.value) {
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
  }
}

const formatTime = (timestamp: number) => {
  if (!timestamp) return '-'
  const date = new Date(timestamp * 1000)
  const now = new Date()
  const diff = now.getTime() - date.getTime()

  if (diff < 60000) return t('common.justNow')
  if (diff < 3600000) return t('common.minutesAgo', { count: Math.floor(diff / 60000) })
  if (diff < 86400000) return t('common.hoursAgo', { count: Math.floor(diff / 3600000) })
  return date.toLocaleDateString()
}

const formatMessageTime = (timestamp: number) => {
  return new Date(timestamp * 1000).toLocaleTimeString()
}

onMounted(async () => {
  await loadWidgets()
  await loadSessions()
})

onBeforeUnmount(() => {
  for (const sub of notificationSubscriptions) {
    sub.unsubscribe()
  }
  notificationSubscriptions = []
})
</script>
