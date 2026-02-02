<template>
  <MainLayout>
    <div class="h-full flex flex-col bg-chat" data-testid="page-widget-sessions">
      <!-- Header -->
      <div
        class="px-4 lg:px-6 py-4 lg:py-5 border-b border-light-border/30 dark:border-dark-border/20 bg-chat"
      >
        <div class="max-w-7xl mx-auto">
          <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div>
              <div class="flex items-center gap-2 mb-1">
                <button
                  class="p-1 rounded hover-surface transition-colors"
                  :title="$t('common.back')"
                  @click="goBack"
                >
                  <Icon icon="heroicons:arrow-left" class="w-5 h-5 txt-secondary" />
                </button>
                <h1 class="text-xl lg:text-2xl font-semibold txt-primary flex items-center gap-2">
                  <Icon icon="heroicons:users" class="w-6 h-6 txt-brand" />
                  {{ $t('widgetSessions.title') }}
                </h1>
              </div>
              <p class="txt-secondary text-xs lg:text-sm ml-8">
                {{ widget?.name || widgetId }}
              </p>
            </div>

            <!-- Stats Badges and Export -->
            <div class="flex items-center gap-2 flex-wrap">
              <span class="px-3 py-1 rounded-full text-xs font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400">
                {{ $t('widgetSessions.aiActive') }}: {{ stats.ai }}
              </span>
              <span class="px-3 py-1 rounded-full text-xs font-medium bg-green-500/10 text-green-600 dark:text-green-400">
                {{ $t('widgetSessions.humanActive') }}: {{ stats.human }}
              </span>
              <span class="px-3 py-1 rounded-full text-xs font-medium bg-yellow-500/10 text-yellow-600 dark:text-yellow-400">
                {{ $t('widgetSessions.waiting') }}: {{ stats.waiting }}
              </span>
              <button
                class="px-3 py-1.5 rounded-lg btn-primary text-xs font-medium flex items-center gap-1"
                @click="showExportDialog = true"
              >
                <Icon icon="heroicons:arrow-down-tray" class="w-4 h-4" />
                {{ $t('export.title') }}
              </button>
            </div>
          </div>

          <!-- Filters -->
          <div class="mt-4 flex flex-wrap gap-3">
            <select
              v-model="filters.status"
              class="px-3 py-1.5 rounded-lg surface-chip text-sm txt-primary"
              @change="loadSessions"
            >
              <option value="">{{ $t('widgetSessions.allStatus') }}</option>
              <option value="active">{{ $t('widgetSessions.active') }}</option>
              <option value="expired">{{ $t('widgetSessions.expired') }}</option>
            </select>

            <select
              v-model="filters.mode"
              class="px-3 py-1.5 rounded-lg surface-chip text-sm txt-primary"
              @change="loadSessions"
            >
              <option value="">{{ $t('widgetSessions.allModes') }}</option>
              <option value="ai">{{ $t('widgetSessions.modeAi') }}</option>
              <option value="human">{{ $t('widgetSessions.modeHuman') }}</option>
              <option value="waiting">{{ $t('widgetSessions.modeWaiting') }}</option>
            </select>

            <select
              v-model="filters.sort"
              class="px-3 py-1.5 rounded-lg surface-chip text-sm txt-primary"
              @change="loadSessions"
            >
              <option value="lastMessage">{{ $t('widgetSessions.sortLastMessage') }}</option>
              <option value="created">{{ $t('widgetSessions.sortCreated') }}</option>
              <option value="messageCount">{{ $t('widgetSessions.sortMessageCount') }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Content Area -->
      <div class="flex-1 overflow-y-auto px-4 lg:px-6 py-4 lg:py-6 scroll-thin">
        <div class="max-w-7xl mx-auto">
          <!-- Loading State -->
          <div v-if="loading" class="surface-card p-8 text-center">
            <div
              class="animate-spin w-8 h-8 border-4 border-[var(--brand)] border-t-transparent rounded-full mx-auto mb-4"
            ></div>
            <p class="txt-secondary text-sm">{{ $t('common.loading') }}</p>
          </div>

          <!-- Empty State -->
          <div v-else-if="sessions.length === 0" class="surface-card p-8 lg:p-12 text-center">
            <Icon
              icon="heroicons:chat-bubble-left-right"
              class="w-12 h-12 txt-secondary opacity-30 mx-auto mb-4"
            />
            <h3 class="text-lg font-semibold txt-primary mb-2">
              {{ $t('widgetSessions.noSessions') }}
            </h3>
            <p class="txt-secondary text-sm">{{ $t('widgetSessions.noSessionsDescription') }}</p>
          </div>

          <!-- Sessions Grid -->
          <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <WidgetSessionCard
              v-for="session in sessions"
              :key="session.id"
              :session="session"
              :widget-id="widgetId"
              @view="viewSession"
              @takeover="takeOver"
            />
          </div>

          <!-- Summary Panel -->
          <WidgetSummaryPanel
            v-if="sessions.length > 0"
            :widget-id="widgetId"
            class="mt-8 mb-6"
          />

          <!-- Pagination -->
          <div v-if="pagination.total > pagination.limit" class="mt-6 flex justify-center gap-2">
            <button
              class="px-4 py-2 rounded-lg surface-chip txt-secondary hover-surface transition-colors disabled:opacity-50"
              :disabled="pagination.offset === 0"
              @click="prevPage"
            >
              {{ $t('common.previous') }}
            </button>
            <span class="px-4 py-2 txt-secondary text-sm">
              {{ currentPage }} / {{ totalPages }}
            </span>
            <button
              class="px-4 py-2 rounded-lg surface-chip txt-secondary hover-surface transition-colors disabled:opacity-50"
              :disabled="!pagination.hasMore"
              @click="nextPage"
            >
              {{ $t('common.next') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Session Detail Panel (Slide-over) -->
      <Teleport to="body">
        <div
          v-if="selectedSession"
          class="fixed inset-0 bg-black/50 z-50 flex justify-end"
          @click.self="closeSessionDetail"
        >
          <div class="w-full max-w-2xl h-full bg-[var(--bg-card)] shadow-2xl flex flex-col">
            <!-- Panel Header -->
            <div class="p-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between">
              <div>
                <h2 class="text-lg font-semibold txt-primary">
                  {{ $t('widgetSessions.sessionDetail') }}
                </h2>
                <p class="text-xs txt-secondary">{{ selectedSession.sessionIdDisplay || selectedSession.sessionId }}</p>
              </div>
              <button
                class="p-2 rounded-lg hover-surface transition-colors"
                @click="closeSessionDetail"
              >
                <Icon icon="heroicons:x-mark" class="w-5 h-5 txt-secondary" />
              </button>
            </div>

            <!-- Session Info -->
            <div class="p-4 border-b border-light-border/30 dark:border-dark-border/20">
              <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgetSessions.messages') }}</p>
                  <p class="text-lg font-bold txt-primary">{{ selectedSession.messageCount }}</p>
                </div>
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgetSessions.files') }}</p>
                  <p class="text-lg font-bold txt-primary">{{ selectedSession.fileCount }}</p>
                </div>
                <div>
                  <p class="text-xs txt-secondary">{{ $t('widgetSessions.status') }}</p>
                  <span
                    :class="[
                      'px-2 py-0.5 rounded-full text-xs font-medium',
                      modeClass(selectedSession.mode),
                    ]"
                  >
                    {{ modeLabel(selectedSession.mode) }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Messages -->
            <div class="flex-1 overflow-y-auto p-4 space-y-3 scroll-thin">
              <div v-if="loadingDetail" class="text-center py-8">
                <div
                  class="animate-spin w-6 h-6 border-2 border-[var(--brand)] border-t-transparent rounded-full mx-auto"
                ></div>
              </div>
              <template v-else>
                <div
                  v-for="message in sessionMessages"
                  :key="message.id"
                  :class="[
                    'p-3 rounded-lg max-w-[85%]',
                    message.direction === 'IN'
                      ? 'bg-[var(--brand)]/10 ml-auto'
                      : 'surface-chip',
                  ]"
                >
                  <p class="text-xs txt-secondary mb-1">
                    {{ getSenderLabel(message) }}
                    · {{ formatTime(message.timestamp) }}
                  </p>
                  <p class="txt-primary text-sm whitespace-pre-wrap">{{ message.text }}</p>
                </div>
              </template>
            </div>

            <!-- Message Input (Human Mode) -->
            <div
              v-if="selectedSession.mode === 'human'"
              class="p-4 border-t border-light-border/30 dark:border-dark-border/20"
            >
              <form @submit.prevent="sendMessage" class="flex gap-2">
                <input
                  v-model="messageText"
                  type="text"
                  class="flex-1 px-4 py-2 rounded-lg surface-chip txt-primary text-sm placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  :placeholder="$t('widgetSessions.typeMessage')"
                  :disabled="sendingMessage"
                />
                <button
                  type="submit"
                  class="px-4 py-2 rounded-lg btn-primary text-sm font-medium disabled:opacity-50"
                  :disabled="!messageText.trim() || sendingMessage"
                >
                  <Icon
                    v-if="sendingMessage"
                    icon="heroicons:arrow-path"
                    class="w-4 h-4 animate-spin"
                  />
                  <Icon v-else icon="heroicons:paper-airplane" class="w-4 h-4" />
                </button>
              </form>
            </div>

            <!-- Actions -->
            <div class="p-4 border-t border-light-border/30 dark:border-dark-border/20 flex gap-2">
              <button
                v-if="selectedSession.mode === 'ai'"
                class="flex-1 btn-primary py-2 rounded-lg font-medium text-sm"
                @click="takeOver(selectedSession)"
              >
                <Icon icon="heroicons:hand-raised" class="w-4 h-4 inline mr-1" />
                {{ $t('widgetSessions.takeOver') }}
              </button>
              <button
                v-if="selectedSession.mode === 'human'"
                class="flex-1 px-4 py-2 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 font-medium text-sm"
                @click="handBack(selectedSession)"
              >
                <Icon icon="heroicons:arrow-uturn-left" class="w-4 h-4 inline mr-1" />
                {{ $t('widgetSessions.handBack') }}
              </button>
              <button
                v-if="selectedSession.mode === 'waiting'"
                class="flex-1 btn-primary py-2 rounded-lg font-medium text-sm"
                @click="takeOver(selectedSession)"
              >
                <Icon icon="heroicons:chat-bubble-left-ellipsis" class="w-4 h-4 inline mr-1" />
                {{ $t('widgetSessions.respond') }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Export Dialog -->
      <WidgetExportDialog
        v-if="showExportDialog"
        :widget-id="widgetId"
        @close="showExportDialog = false"
      />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import WidgetSessionCard from '@/components/widgets/WidgetSessionCard.vue'
import WidgetExportDialog from '@/components/widgets/WidgetExportDialog.vue'
import WidgetSummaryPanel from '@/components/widgets/WidgetSummaryPanel.vue'
import * as widgetSessionsApi from '@/services/api/widgetSessionsApi'
import * as widgetsApi from '@/services/api/widgetsApi'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { subscribeToSession, type EventSubscription, type WidgetEvent } from '@/services/sseClient'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { success, error } = useNotification()
const { confirm } = useDialog()

const widgetId = computed(() => route.params.widgetId as string)
const widget = ref<widgetsApi.Widget | null>(null)
const loading = ref(false)
const loadingDetail = ref(false)
const sessions = ref<widgetSessionsApi.WidgetSession[]>([])
const selectedSession = ref<widgetSessionsApi.WidgetSession | null>(null)
const sessionMessages = ref<widgetSessionsApi.SessionMessage[]>([])
const showExportDialog = ref(false)
const messageText = ref('')
const sendingMessage = ref(false)
const eventSubscription = ref<EventSubscription | null>(null)

const filters = ref({
  status: '' as '' | 'active' | 'expired',
  mode: '' as '' | 'ai' | 'human' | 'waiting',
  sort: 'lastMessage' as 'lastMessage' | 'created' | 'messageCount',
})

const pagination = ref({
  total: 0,
  limit: 20,
  offset: 0,
  hasMore: false,
})

const stats = ref({
  ai: 0,
  human: 0,
  waiting: 0,
})

const currentPage = computed(() => Math.floor(pagination.value.offset / pagination.value.limit) + 1)
const totalPages = computed(() => Math.ceil(pagination.value.total / pagination.value.limit))

const goBack = () => {
  router.push({ name: 'tools-chat-widget' })
}

const loadWidget = async () => {
  try {
    widget.value = await widgetsApi.getWidget(widgetId.value)
  } catch (err: any) {
    error(err.message || 'Failed to load widget')
  }
}

const loadSessions = async () => {
  loading.value = true
  try {
    const params: widgetSessionsApi.ListSessionsParams = {
      limit: pagination.value.limit,
      offset: pagination.value.offset,
      sort: filters.value.sort,
      order: 'DESC',
    }
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.mode) params.mode = filters.value.mode

    const response = await widgetSessionsApi.listWidgetSessions(widgetId.value, params)
    sessions.value = response.sessions
    pagination.value = response.pagination
    stats.value = response.stats
  } catch (err: any) {
    error(err.message || 'Failed to load sessions')
  } finally {
    loading.value = false
  }
}

const viewSession = async (session: widgetSessionsApi.WidgetSession) => {
  // Unsubscribe from previous session if any
  if (eventSubscription.value) {
    eventSubscription.value.unsubscribe()
    eventSubscription.value = null
  }

  selectedSession.value = session
  loadingDetail.value = true
  try {
    const response = await widgetSessionsApi.getWidgetSession(widgetId.value, session.sessionId)
    sessionMessages.value = response.messages

    // Subscribe to real-time updates for this session
    eventSubscription.value = subscribeToSession(
      widgetId.value,
      session.sessionId,
      handleSessionEvent,
      (err) => console.warn('[Admin SSE] Error:', err)
    )
  } catch (err: any) {
    error(err.message || 'Failed to load session details')
  } finally {
    loadingDetail.value = false
  }
}

const handleSessionEvent = (event: WidgetEvent) => {
  console.debug('[Admin SSE] Event received:', event)
  
  if (event.type === 'message') {
    // Check if message already exists (avoid duplicates)
    const existingIndex = sessionMessages.value.findIndex(m => m.id === event.messageId)
    if (existingIndex === -1) {
      sessionMessages.value.push({
        id: event.messageId as number,
        direction: event.direction as string,
        text: event.text as string,
        timestamp: event.timestamp as number,
        sender: event.sender as string,
      })
    }
  } else if (event.type === 'takeover') {
    if (selectedSession.value) {
      selectedSession.value.mode = 'human'
    }
  } else if (event.type === 'handback') {
    if (selectedSession.value) {
      selectedSession.value.mode = 'ai'
    }
  }
}

const closeSessionDetail = () => {
  // Unsubscribe from SSE
  if (eventSubscription.value) {
    eventSubscription.value.unsubscribe()
    eventSubscription.value = null
  }
  selectedSession.value = null
  sessionMessages.value = []
}

const takeOver = async (session: widgetSessionsApi.WidgetSession) => {
  const confirmed = await confirm({
    title: t('widgetSessions.takeOverTitle'),
    message: t('widgetSessions.takeOverWarning'),
    confirmText: t('widgetSessions.takeOverConfirm'),
    cancelText: t('common.cancel'),
  })

  if (confirmed) {
    try {
      await widgetSessionsApi.takeOverSession(widgetId.value, session.sessionId)
      success(t('widgetSessions.takeOverSuccess'))
      await loadSessions()
      if (selectedSession.value?.id === session.id) {
        selectedSession.value.mode = 'human'
      }
    } catch (err: any) {
      error(err.message || 'Failed to take over session')
    }
  }
}

const handBack = async (session: widgetSessionsApi.WidgetSession) => {
  try {
    await widgetSessionsApi.handBackSession(widgetId.value, session.sessionId)
    success(t('widgetSessions.handBackSuccess'))
    await loadSessions()
    if (selectedSession.value?.id === session.id) {
      selectedSession.value.mode = 'ai'
    }
  } catch (err: any) {
    error(err.message || 'Failed to hand back session')
  }
}

const sendMessage = async () => {
  if (!selectedSession.value || !messageText.value.trim()) return

  sendingMessage.value = true
  try {
    const response = await widgetSessionsApi.sendHumanMessage(
      widgetId.value,
      selectedSession.value.sessionId,
      messageText.value.trim()
    )

    // Add the message to the local list (direction OUT = system response to user)
    sessionMessages.value.push({
      id: response.messageId,
      direction: 'OUT',
      text: messageText.value.trim(),
      timestamp: Math.floor(Date.now() / 1000),
      sender: 'human',
    })

    messageText.value = ''
  } catch (err: any) {
    error(err.message || 'Failed to send message')
  } finally {
    sendingMessage.value = false
  }
}

const prevPage = () => {
  if (pagination.value.offset > 0) {
    pagination.value.offset = Math.max(0, pagination.value.offset - pagination.value.limit)
    loadSessions()
  }
}

const nextPage = () => {
  if (pagination.value.hasMore) {
    pagination.value.offset += pagination.value.limit
    loadSessions()
  }
}

const modeClass = (mode: string) => {
  switch (mode) {
    case 'ai':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400'
    case 'human':
      return 'bg-green-500/10 text-green-600 dark:text-green-400'
    case 'waiting':
      return 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400'
    default:
      return 'bg-gray-500/10 text-gray-600 dark:text-gray-400'
  }
}

const modeLabel = (mode: string) => {
  switch (mode) {
    case 'ai':
      return t('widgetSessions.modeAi')
    case 'human':
      return t('widgetSessions.modeHuman')
    case 'waiting':
      return t('widgetSessions.modeWaiting')
    default:
      return mode
  }
}

const formatTime = (timestamp: number) => {
  return new Date(timestamp * 1000).toLocaleString()
}

const getSenderLabel = (message: widgetSessionsApi.SessionMessage) => {
  // IN = message from user (visitor), OUT = response from system (AI or human operator)
  if (message.direction === 'IN') {
    return t('widgetSessions.visitor')
  }
  if (message.sender === 'human') {
    return t('widgetSessions.operator')
  }
  return t('widgetSessions.assistant')
}

onMounted(() => {
  loadWidget()
  loadSessions()
})

onUnmounted(() => {
  // Clean up SSE subscription
  if (eventSubscription.value) {
    eventSubscription.value.unsubscribe()
    eventSubscription.value = null
  }
})
</script>
