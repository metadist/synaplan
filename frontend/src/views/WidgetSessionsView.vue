<template>
  <MainLayout>
    <div class="h-full flex flex-col bg-chat" data-testid="page-widget-sessions">
      <!-- Header -->
      <div class="px-4 lg:px-6 py-4 bg-chat flex-shrink-0">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <button
              class="p-2 rounded-xl hover:bg-white/5 dark:hover:bg-white/5 transition-all duration-200"
              :title="$t('common.back')"
              @click="goBack"
            >
              <Icon icon="heroicons:arrow-left" class="w-5 h-5 txt-secondary" />
            </button>
            <div>
              <h1 class="text-lg font-semibold txt-primary flex items-center gap-2">
                <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-[var(--brand)] to-[var(--brand-light)] flex items-center justify-center">
                  <Icon icon="heroicons:chat-bubble-left-right" class="w-4 h-4 text-white" />
                </div>
                {{ widget?.name || $t('widgetSessions.title') }}
              </h1>
              <div class="flex items-center gap-3 text-xs txt-secondary mt-1 ml-10">
                <span class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-blue-500 shadow-sm shadow-blue-500/50"></span>
                  {{ stats.ai }} {{ $t('widgetSessions.aiShort') }}
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-emerald-500 shadow-sm shadow-emerald-500/50"></span>
                  {{ stats.human }} {{ $t('widgetSessions.humanShort') }}
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="w-2 h-2 rounded-full bg-amber-500 shadow-sm shadow-amber-500/50"></span>
                  {{ stats.waiting }} {{ $t('widgetSessions.waitingShort') }}
                </span>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-1">
            <button
              class="p-2.5 rounded-xl hover:bg-white/5 dark:hover:bg-white/5 transition-all duration-200"
              :title="$t('export.title')"
              @click="showExportDialog = true"
            >
              <Icon icon="heroicons:arrow-down-tray" class="w-5 h-5 txt-secondary" />
            </button>
            <button
              :class="[
                'p-2.5 rounded-xl transition-all duration-200',
                showSummaryPanel ? 'bg-[var(--brand)]/10 text-[var(--brand)]' : 'hover:bg-white/5 dark:hover:bg-white/5 txt-secondary'
              ]"
              :title="$t('widgetSessions.summary')"
              @click="showSummaryPanel = !showSummaryPanel"
            >
              <Icon icon="heroicons:sparkles" class="w-5 h-5" />
            </button>
          </div>
        </div>
      </div>

      <!-- Main Content: Split View -->
      <div class="flex-1 flex overflow-hidden px-4 lg:px-6 pb-4 lg:pb-6 gap-4">
        <!-- Left: Session List -->
        <div
          :class="[
            'flex-shrink-0 flex flex-col overflow-hidden transition-all duration-300 rounded-2xl bg-[var(--bg-card)] shadow-sm',
            selectedSession ? 'w-80 hidden md:flex' : 'w-full md:w-80',
          ]"
        >
          <!-- Filters -->
          <div class="p-3 flex gap-2">
            <button
              :class="[
                'p-2 rounded-xl transition-all duration-200 flex-shrink-0',
                filters.favorite
                  ? 'bg-amber-500/20 text-amber-500'
                  : 'bg-white/5 txt-secondary hover:text-amber-500',
              ]"
              :title="$t('widgetSessions.favorites')"
              @click="toggleFavoriteFilter"
            >
              <Icon :icon="filters.favorite ? 'heroicons:star-solid' : 'heroicons:star'" class="w-4 h-4" />
            </button>
            <select
              v-model="filters.mode"
              class="flex-1 px-3 py-2 rounded-xl bg-white/5 dark:bg-white/5 text-xs txt-primary border-0 focus:ring-2 focus:ring-[var(--brand)]/30 transition-all"
              @change="loadSessions"
            >
              <option value="">{{ $t('widgetSessions.allModes') }}</option>
              <option value="ai">{{ $t('widgetSessions.modeAi') }}</option>
              <option value="human">{{ $t('widgetSessions.modeHuman') }}</option>
              <option value="waiting">{{ $t('widgetSessions.modeWaiting') }}</option>
            </select>
            <select
              v-model="filters.status"
              class="flex-1 px-3 py-2 rounded-xl bg-white/5 dark:bg-white/5 text-xs txt-primary border-0 focus:ring-2 focus:ring-[var(--brand)]/30 transition-all"
              @change="loadSessions"
            >
              <option value="">{{ $t('widgetSessions.allStatus') }}</option>
              <option value="active">{{ $t('widgetSessions.active') }}</option>
              <option value="expired">{{ $t('widgetSessions.expired') }}</option>
            </select>
          </div>

          <!-- Session List -->
          <div class="flex-1 overflow-y-auto scroll-thin px-2 pb-2">
            <!-- Loading -->
            <div v-if="loading" class="p-8 text-center">
              <div
                class="animate-spin w-6 h-6 border-2 border-[var(--brand)] border-t-transparent rounded-full mx-auto"
              ></div>
            </div>

            <!-- Empty State -->
            <div v-else-if="sessions.length === 0" class="p-8 text-center">
              <div class="w-16 h-16 rounded-2xl bg-white/5 dark:bg-white/5 flex items-center justify-center mx-auto mb-4">
                <Icon
                  icon="heroicons:chat-bubble-left-right"
                  class="w-8 h-8 txt-secondary opacity-50"
                />
              </div>
              <p class="txt-secondary text-sm">{{ $t('widgetSessions.noSessions') }}</p>
            </div>

            <!-- Sessions -->
            <div v-else class="space-y-1">
              <button
                v-for="session in sessions"
                :key="session.id"
                :class="[
                  'w-full p-3 text-left rounded-xl transition-all duration-200 group',
                  selectedSession?.id === session.id
                    ? 'bg-[var(--brand)]/10 shadow-sm'
                    : 'hover:bg-white/5 dark:hover:bg-white/5',
                ]"
                @click="viewSession(session)"
              >
                <div class="flex items-start gap-3">
                  <!-- Mode Icon -->
                  <div
                    :class="[
                      'w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0 shadow-sm',
                      getModeGradient(session.mode),
                    ]"
                  >
                    <Icon :icon="getModeIcon(session.mode)" class="w-5 h-5 text-white" />
                  </div>

                  <!-- Content -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1 gap-2">
                      <div class="flex items-center gap-1.5 min-w-0 flex-1">
                        <span class="text-sm font-medium txt-primary truncate">
                          {{ session.title || getModeLabel(session.mode) }}
                        </span>
                        <button
                          class="p-0.5 rounded transition-all duration-200 flex-shrink-0"
                          :class="session.isFavorite ? 'text-amber-500' : 'txt-secondary opacity-0 group-hover:opacity-100 hover:text-amber-500'"
                          :title="session.isFavorite ? $t('widgetSessions.unfavorite') : $t('widgetSessions.favorite')"
                          @click.stop="toggleSessionFavorite(session)"
                        >
                          <Icon :icon="session.isFavorite ? 'heroicons:star-solid' : 'heroicons:star'" class="w-3.5 h-3.5" />
                        </button>
                      </div>
                      <span class="text-[11px] txt-secondary flex-shrink-0 whitespace-nowrap">{{ getTimeAgo(session.lastMessage) }}</span>
                    </div>
                    <p class="text-xs txt-secondary line-clamp-2 leading-relaxed">
                      {{ stripMarkdown(session.lastMessagePreview) || $t('widgetSessions.noMessages') }}
                    </p>
                  </div>

                  <!-- Waiting Indicator -->
                  <div v-if="session.mode === 'waiting'" class="flex-shrink-0 mt-1">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 block animate-pulse shadow-sm shadow-amber-500/50"></span>
                  </div>
                </div>
              </button>

              <!-- Load More -->
              <div v-if="pagination.hasMore" class="pt-2 pb-1 text-center">
                <button
                  class="text-xs txt-brand hover:underline px-4 py-2 rounded-lg hover:bg-[var(--brand)]/5 transition-colors"
                  @click="loadMore"
                >
                  {{ $t('common.loadMore') }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Chat View -->
        <div class="flex-1 flex flex-col overflow-hidden rounded-2xl bg-[var(--bg-card)] shadow-sm">
          <!-- No Selection -->
          <div
            v-if="!selectedSession"
            class="flex-1 flex items-center justify-center"
          >
            <div class="text-center p-8">
              <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-white/5 to-white/10 dark:from-white/5 dark:to-white/10 flex items-center justify-center mx-auto mb-5">
                <Icon
                  icon="heroicons:chat-bubble-left-right"
                  class="w-10 h-10 txt-secondary opacity-40"
                />
              </div>
              <h3 class="text-lg font-medium txt-primary mb-2">
                {{ $t('widgetSessions.selectSession') }}
              </h3>
              <p class="text-sm txt-secondary opacity-70 max-w-xs mx-auto">
                {{ $t('widgetSessions.selectSessionDescription') }}
              </p>
            </div>
          </div>

          <!-- Selected Session Chat -->
          <template v-else>
            <!-- Chat Header -->
            <div class="p-4 flex items-center justify-between flex-shrink-0">
              <div class="flex items-center gap-3">
                <!-- Back button on mobile -->
                <button
                  class="p-2 rounded-xl hover:bg-white/5 transition-all duration-200 md:hidden"
                  @click="closeSessionDetail"
                >
                  <Icon icon="heroicons:arrow-left" class="w-5 h-5 txt-secondary" />
                </button>

                <div
                  :class="[
                    'w-11 h-11 rounded-xl flex items-center justify-center shadow-sm',
                    getModeGradient(selectedSession.mode),
                  ]"
                >
                  <Icon :icon="getModeIcon(selectedSession.mode)" class="w-5 h-5 text-white" />
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-medium txt-primary">
                      {{ selectedSession.title || getModeLabel(selectedSession.mode) }}
                    </p>
                    <span
                      class="text-[10px] px-1.5 py-0.5 rounded-md"
                      :class="getModeChipClass(selectedSession.mode)"
                    >{{ getModeLabel(selectedSession.mode) }}</span>
                  </div>
                  <p class="text-xs txt-secondary">
                    <template v-if="selectedSession.country">
                      {{ getCountryFlag(selectedSession.country) }} {{ getCountryName(selectedSession.country) }} ·
                    </template>
                    {{ $t('widgetSessions.lastActivity') }}: {{ getTimeAgo(selectedSession.lastMessage) }}
                  </p>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex items-center gap-2">
                <button
                  v-if="selectedSession.mode === 'ai' && !selectedSession.isExpired"
                  class="px-4 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white text-xs font-medium transition-all duration-200 shadow-sm shadow-emerald-500/25 flex items-center gap-1.5"
                  @click="takeOver(selectedSession)"
                >
                  <Icon icon="heroicons:hand-raised" class="w-4 h-4" />
                  {{ $t('widgetSessions.takeOver') }}
                </button>
                <button
                  v-else-if="selectedSession.mode === 'waiting' && !selectedSession.isExpired"
                  class="px-4 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white text-xs font-medium transition-all duration-200 shadow-sm shadow-amber-500/25 flex items-center gap-1.5"
                  @click="takeOver(selectedSession)"
                >
                  <Icon icon="heroicons:chat-bubble-left-ellipsis" class="w-4 h-4" />
                  {{ $t('widgetSessions.respond') }}
                </button>
                <button
                  v-else-if="selectedSession.mode === 'human'"
                  class="px-4 py-2 rounded-xl bg-blue-500/10 hover:bg-blue-500/20 text-blue-500 text-xs font-medium transition-all duration-200 flex items-center gap-1.5"
                  @click="handBack(selectedSession)"
                >
                  <Icon icon="heroicons:arrow-uturn-left" class="w-4 h-4" />
                  {{ $t('widgetSessions.handBack') }}
                </button>
              </div>
            </div>

            <!-- Messages -->
            <div ref="messagesContainer" class="flex-1 overflow-y-auto px-4 py-2 scroll-thin">
              <div v-if="loadingDetail" class="text-center py-8">
                <div
                  class="animate-spin w-6 h-6 border-2 border-[var(--brand)] border-t-transparent rounded-full mx-auto"
                ></div>
              </div>
              <div v-else class="space-y-3">
                <div
                  v-for="message in sessionMessages"
                  :key="message.id"
                  :class="[
                    'max-w-[80%]',
                    message.direction === 'OUT' ? 'ml-auto' : 'mr-auto',
                  ]"
                >
                  <div
                    :class="[
                      'px-4 py-3 shadow-sm',
                      message.direction === 'OUT'
                        ? 'bg-gradient-to-br from-emerald-600 to-emerald-500 text-white rounded-2xl rounded-br-md'
                        : 'bg-white/5 dark:bg-white/5 rounded-2xl rounded-bl-md',
                    ]"
                  >
                    <MessageText
                      :content="message.text"
                      :readonly="true"
                      :class="[
                        'text-sm leading-relaxed',
                        message.direction === 'OUT' ? 'text-white' : 'txt-primary',
                      ]"
                    />
                  </div>
                  <p
                    :class="[
                      'text-[10px] mt-1.5 px-1 txt-secondary opacity-70',
                      message.direction === 'OUT' ? 'text-right' : 'text-left',
                    ]"
                  >
                    {{ getSenderLabel(message) }} · {{ formatTime(message.timestamp) }}
                  </p>
                </div>

                <!-- Typing Preview -->
                <div
                  v-if="typingPreview?.text"
                  class="max-w-[80%] mr-auto animate-pulse"
                >
                  <div
                    class="px-4 py-3 shadow-sm bg-white/5 dark:bg-white/5 rounded-2xl rounded-bl-md border border-dashed border-white/20"
                  >
                    <p class="text-sm leading-relaxed txt-secondary italic">
                      {{ typingPreview.text }}
                    </p>
                  </div>
                  <p class="text-[10px] mt-1.5 px-1 txt-secondary opacity-50 text-left">
                    {{ $t('widgetSessions.typing') }}...
                  </p>
                </div>
              </div>
            </div>

            <!-- Message Input (Human Mode) -->
            <div
              v-if="selectedSession.mode === 'human'"
              class="p-4 flex-shrink-0"
            >
              <form @submit.prevent="sendMessage" class="flex gap-3">
                <input
                  v-model="messageText"
                  type="text"
                  class="flex-1 px-5 py-3 rounded-2xl bg-white/5 dark:bg-white/5 txt-primary text-sm placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30 transition-all"
                  :placeholder="$t('widgetSessions.typeMessage')"
                  :disabled="sendingMessage"
                />
                <button
                  type="submit"
                  class="w-12 h-12 rounded-2xl bg-gradient-to-br from-[var(--brand)] to-[var(--brand-light)] flex items-center justify-center disabled:opacity-50 transition-all duration-200 shadow-sm shadow-[var(--brand)]/25 hover:shadow-md hover:shadow-[var(--brand)]/30"
                  :disabled="!messageText.trim() || sendingMessage"
                >
                  <Icon
                    v-if="sendingMessage"
                    icon="heroicons:arrow-path"
                    class="w-5 h-5 text-white animate-spin"
                  />
                  <Icon v-else icon="heroicons:paper-airplane" class="w-5 h-5 text-white" />
                </button>
              </form>
            </div>
          </template>
        </div>

        <!-- AI Summary Slide Panel -->
        <Transition
          enter-active-class="transition-all duration-300 ease-out"
          enter-from-class="opacity-0 translate-x-4"
          enter-to-class="opacity-100 translate-x-0"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="opacity-100 translate-x-0"
          leave-to-class="opacity-0 translate-x-4"
        >
          <div
            v-if="showSummaryPanel"
            class="w-80 lg:w-96 flex-shrink-0 rounded-2xl bg-[var(--bg-card)] shadow-sm overflow-hidden flex flex-col"
          >
            <div class="p-4 flex items-center justify-between">
              <h3 class="text-sm font-semibold txt-primary flex items-center gap-2">
                <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                  <Icon icon="heroicons:sparkles" class="w-4 h-4 text-white" />
                </div>
                {{ $t('widgetSessions.aiSummary') }}
              </h3>
              <button
                class="p-1.5 rounded-lg hover:bg-white/5 transition-colors"
                @click="showSummaryPanel = false"
              >
                <Icon icon="heroicons:x-mark" class="w-4 h-4 txt-secondary" />
              </button>
            </div>
            <div class="flex-1 overflow-y-auto px-4 pb-4 scroll-thin">
              <WidgetSummaryPanel :widget-id="widgetId" :compact="true" />
            </div>
          </div>
        </Transition>
      </div>

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
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import MessageText from '@/components/MessageText.vue'
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
const typingPreview = ref<{ text: string; timestamp: number } | null>(null)
const showExportDialog = ref(false)
const showSummaryPanel = ref(false)
const messageText = ref('')
const sendingMessage = ref(false)
const eventSubscription = ref<EventSubscription | null>(null)
const messagesContainer = ref<HTMLElement | null>(null)

const filters = ref({
  status: '' as '' | 'active' | 'expired',
  mode: '' as '' | 'ai' | 'human' | 'waiting',
  favorite: false,
})

const pagination = ref({
  total: 0,
  limit: 30,
  offset: 0,
  hasMore: false,
})

const stats = ref({
  ai: 0,
  human: 0,
  waiting: 0,
})

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
  pagination.value.offset = 0
  try {
    const params: widgetSessionsApi.ListSessionsParams = {
      limit: pagination.value.limit,
      offset: 0,
      sort: 'lastMessage',
      order: 'DESC',
    }
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.mode) params.mode = filters.value.mode
    if (filters.value.favorite) params.favorite = true

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

const loadMore = async () => {
  pagination.value.offset += pagination.value.limit
  try {
    const params: widgetSessionsApi.ListSessionsParams = {
      limit: pagination.value.limit,
      offset: pagination.value.offset,
      sort: 'lastMessage',
      order: 'DESC',
    }
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.mode) params.mode = filters.value.mode
    if (filters.value.favorite) params.favorite = true

    const response = await widgetSessionsApi.listWidgetSessions(widgetId.value, params)
    sessions.value.push(...response.sessions)
    pagination.value = response.pagination
  } catch (err: any) {
    error(err.message || 'Failed to load more sessions')
  }
}

const viewSession = async (session: widgetSessionsApi.WidgetSession) => {
  // Unsubscribe from previous session's SSE
  if (eventSubscription.value) {
    eventSubscription.value.unsubscribe()
    eventSubscription.value = null
  }

  // Set initial session data from list (may be stale)
  selectedSession.value = { ...session }
  loadingDetail.value = true

  try {
    const response = await widgetSessionsApi.getWidgetSession(widgetId.value, session.sessionId)

    // Update selectedSession with fresh data from server (this is the key fix!)
    // This ensures the mode and other properties are accurate
    selectedSession.value = response.session
    sessionMessages.value = response.messages

    // Update session in list with fresh data from server
    const sessionIndex = sessions.value.findIndex(s => s.id === response.session.id)
    if (sessionIndex !== -1) {
      // Update only specific fields, don't replace entire object
      sessions.value[sessionIndex].mode = response.session.mode
      sessions.value[sessionIndex].lastMessagePreview = response.session.lastMessagePreview
    }

    // Subscribe to SSE for this specific session
    const currentSessionId = response.session.sessionId
    eventSubscription.value = subscribeToSession(
      widgetId.value,
      currentSessionId,
      (event) => {
        // Guard: Only process events for the currently selected session
        if (selectedSession.value?.sessionId !== currentSessionId) {
          return
        }
        handleSessionEvent(event)
      },
      (err) => console.warn('[Admin SSE] Error:', err)
    )

  } catch (err: any) {
    error(err.message || 'Failed to load session details')
  } finally {
    loadingDetail.value = false
    // Scroll after messages are rendered (after loadingDetail is false)
    await nextTick()
    scrollToBottom()
  }
}

const scrollToBottom = () => {
  if (messagesContainer.value) {
    // Use setTimeout to ensure DOM is fully rendered including markdown content
    setTimeout(() => {
      if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
      }
    }, 1)
  }
}

const handleSessionEvent = (event: WidgetEvent) => {
  if (event.type === 'message') {
    const messageId = event.messageId as number
    const direction = event.direction as 'IN' | 'OUT'
    const sender = event.sender as 'user' | 'ai' | 'human' | 'system'

    if (!messageId || sessionMessages.value.some(m => m.id === messageId)) {
      return
    }

    sessionMessages.value.push({
      id: messageId,
      direction,
      text: event.text as string,
      timestamp: event.timestamp as number,
      sender,
    })

    // Clear typing preview when user sends a message
    if (direction === 'IN') {
      typingPreview.value = null
    }

    if (selectedSession.value) {
      // Always update last message preview with the newest message
      const previewText = (event.text as string)?.substring(0, 100)
      selectedSession.value.lastMessagePreview = previewText

      // Only count user messages for message limit
      const isUserMessage = direction === 'IN' && sender === 'user'
      if (isUserMessage) {
        selectedSession.value.messageCount = (selectedSession.value.messageCount || 0) + 1
      }

      // Also update the session in the list to keep it in sync
      const sessionIndex = sessions.value.findIndex(s => s.id === selectedSession.value?.id)
      if (sessionIndex !== -1) {
        sessions.value[sessionIndex].lastMessagePreview = previewText
        if (isUserMessage) {
          sessions.value[sessionIndex].messageCount = (sessions.value[sessionIndex].messageCount || 0) + 1
        }
      }
    }

    nextTick(() => scrollToBottom())
  } else if (event.type === 'takeover') {
    const takeoverText = (event.message as string) ?? 'You are now connected with a support agent.'
    if (selectedSession.value) {
      selectedSession.value.mode = 'human'
      // DON'T update lastMessagePreview here - it should only reflect actual messages

      // Update mode in the list (but NOT the preview)
      const sessionIndex = sessions.value.findIndex(s => s.id === selectedSession.value?.id)
      if (sessionIndex !== -1) {
        sessions.value[sessionIndex].mode = 'human'
      }
    }
    const takeoverMsgId = event.messageId as number
    if (takeoverMsgId && !sessionMessages.value.some(m => m.id === takeoverMsgId)) {
      sessionMessages.value.push({
        id: takeoverMsgId,
        direction: 'OUT',
        text: takeoverText,
        timestamp: (event.timestamp as number) ?? Math.floor(Date.now() / 1000),
        sender: 'system',
      })
      nextTick(() => scrollToBottom())
    }
  } else if (event.type === 'handback') {
    const handbackText = (event.message as string) ?? 'You are now chatting with our AI assistant.'
    if (selectedSession.value) {
      selectedSession.value.mode = 'ai'
      // DON'T update lastMessagePreview here - it should only reflect actual messages

      // Update mode in the list (but NOT the preview)
      const sessionIndex = sessions.value.findIndex(s => s.id === selectedSession.value?.id)
      if (sessionIndex !== -1) {
        sessions.value[sessionIndex].mode = 'ai'
      }
    }
    const handbackMsgId = event.messageId as number
    if (handbackMsgId && !sessionMessages.value.some(m => m.id === handbackMsgId)) {
      sessionMessages.value.push({
        id: handbackMsgId,
        direction: 'OUT',
        text: handbackText,
        timestamp: (event.timestamp as number) ?? Math.floor(Date.now() / 1000),
        sender: 'system',
      })
      nextTick(() => scrollToBottom())
    }
  } else if (event.type === 'typing') {
    // Handle typing preview from widget user
    const text = (event.text as string) ?? ''
    const timestamp = (event.timestamp as number) ?? Math.floor(Date.now() / 1000)

    if (text) {
      typingPreview.value = { text, timestamp }
      nextTick(() => scrollToBottom())
    } else {
      // Empty text means user cleared input or sent message
      typingPreview.value = null
    }
  }
}

const closeSessionDetail = () => {
  if (eventSubscription.value) {
    eventSubscription.value.unsubscribe()
    eventSubscription.value = null
  }
  typingPreview.value = null
  selectedSession.value = null
  sessionMessages.value = []
}

const takeOver = async (session: widgetSessionsApi.WidgetSession) => {
  const confirmed = await confirm({
    title: t('widgetSessions.takeOverTitle'),
    message: t('widgetSessions.takeOverConfirm'),
  })
  if (!confirmed) return

  try {
    await widgetSessionsApi.takeOverSession(widgetId.value, session.sessionId)

    // Update selectedSession if it's the current one
    if (selectedSession.value?.id === session.id) {
      selectedSession.value.mode = 'human'
    }

    // Update the session in the list
    const sessionIndex = sessions.value.findIndex(s => s.id === session.id)
    if (sessionIndex !== -1) {
      sessions.value[sessionIndex].mode = 'human'
    }

    success(t('widgetSessions.takeOverSuccess'))
  } catch (err: any) {
    error(err.message || 'Failed to take over session')
  }
}

const handBack = async (session: widgetSessionsApi.WidgetSession) => {
  const confirmed = await confirm({
    title: t('widgetSessions.handBackTitle'),
    message: t('widgetSessions.handBackConfirm'),
  })
  if (!confirmed) return

  try {
    await widgetSessionsApi.handBackSession(widgetId.value, session.sessionId)

    // Update selectedSession if it's the current one
    if (selectedSession.value?.id === session.id) {
      selectedSession.value.mode = 'ai'
    }

    // Update the session in the list
    const sessionIndex = sessions.value.findIndex(s => s.id === session.id)
    if (sessionIndex !== -1) {
      sessions.value[sessionIndex].mode = 'ai'
    }

    success(t('widgetSessions.handBackSuccess'))
  } catch (err: any) {
    error(err.message || 'Failed to hand back session')
  }
}

const sendMessage = async () => {
  if (!selectedSession.value || !messageText.value.trim()) return

  sendingMessage.value = true
  try {
    await widgetSessionsApi.sendHumanMessage(
      widgetId.value,
      selectedSession.value.sessionId,
      messageText.value.trim()
    )
    messageText.value = ''
  } catch (err: any) {
    error(err.message || 'Failed to send message')
  } finally {
    sendingMessage.value = false
  }
}

const toggleFavoriteFilter = () => {
  filters.value.favorite = !filters.value.favorite
  loadSessions()
}

const toggleSessionFavorite = async (session: widgetSessionsApi.WidgetSession) => {
  try {
    const response = await widgetSessionsApi.toggleFavorite(widgetId.value, session.sessionId)
    session.isFavorite = response.isFavorite

    // If we're filtering by favorites and this session was unfavorited, remove it from the list
    if (filters.value.favorite && !response.isFavorite) {
      sessions.value = sessions.value.filter(s => s.id !== session.id)
    }
  } catch (err: any) {
    error(err.message || 'Failed to toggle favorite')
  }
}

// Helper functions
const getModeIcon = (mode: string) => {
  switch (mode) {
    case 'ai': return 'heroicons:cpu-chip'
    case 'human': return 'heroicons:user'
    case 'waiting': return 'heroicons:clock'
    default: return 'heroicons:question-mark-circle'
  }
}

const getModeGradient = (mode: string) => {
  switch (mode) {
    case 'ai': return 'bg-gradient-to-br from-blue-500 to-blue-600'
    case 'human': return 'bg-gradient-to-br from-emerald-500 to-emerald-600'
    case 'waiting': return 'bg-gradient-to-br from-amber-500 to-amber-600'
    default: return 'bg-gradient-to-br from-gray-500 to-gray-600'
  }
}

const getModeLabel = (mode: string) => {
  switch (mode) {
    case 'ai': return t('widgetSessions.modeAi')
    case 'human': return t('widgetSessions.modeHuman')
    case 'waiting': return t('widgetSessions.modeWaiting')
    default: return mode
  }
}

const getModeChipClass = (mode: string) => {
  switch (mode) {
    case 'ai': return 'bg-blue-500/20 text-blue-400'
    case 'human': return 'bg-emerald-500/20 text-emerald-400'
    case 'waiting': return 'bg-amber-500/20 text-amber-400'
    default: return 'bg-gray-500/20 text-gray-400'
  }
}

/**
 * Strip markdown formatting for plain text preview.
 */
const stripMarkdown = (text: string | null): string => {
  if (!text) return ''
  return text
    // Remove headers
    .replace(/^#{1,6}\s+/gm, '')
    // Remove bold/italic
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/\*(.+?)\*/g, '$1')
    .replace(/__(.+?)__/g, '$1')
    .replace(/_(.+?)_/g, '$1')
    // Remove inline code
    .replace(/`(.+?)`/g, '$1')
    // Remove links, keep text
    .replace(/\[(.+?)\]\(.+?\)/g, '$1')
    // Remove bullet points
    .replace(/^[-*+]\s+/gm, '')
    // Remove numbered lists
    .replace(/^\d+\.\s+/gm, '')
    // Clean up extra whitespace
    .replace(/\s+/g, ' ')
    .trim()
}

/**
 * Convert ISO 3166-1 Alpha-2 country code to flag emoji.
 * Uses regional indicator symbols (🇦-🇿) to form flag emojis.
 */
const getCountryFlag = (countryCode: string | null): string => {
  if (!countryCode || countryCode.length !== 2) return ''
  const code = countryCode.toUpperCase()
  // Regional indicator symbols start at Unicode 0x1F1E6 (🇦)
  const offset = 0x1F1E6 - 65 // 65 is 'A'
  return String.fromCodePoint(code.charCodeAt(0) + offset, code.charCodeAt(1) + offset)
}

/**
 * Get country name from ISO 3166-1 Alpha-2 code using Intl API.
 */
const getCountryName = (countryCode: string | null): string => {
  if (!countryCode) return ''
  try {
    const displayNames = new Intl.DisplayNames(['en'], { type: 'region' })
    return displayNames.of(countryCode.toUpperCase()) || countryCode
  } catch {
    return countryCode
  }
}

const getTimeAgo = (timestamp: number | null) => {
  if (!timestamp) return '-'
  const now = Math.floor(Date.now() / 1000)
  const diff = now - timestamp
  if (diff < 60) return t('common.justNow')
  if (diff < 3600) return t('common.minutesAgo', { count: Math.floor(diff / 60) })
  if (diff < 86400) return t('common.hoursAgo', { count: Math.floor(diff / 3600) })
  return t('common.daysAgo', { count: Math.floor(diff / 86400) })
}

const formatTime = (timestamp: number) => {
  return new Date(timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

const getSenderLabel = (message: widgetSessionsApi.SessionMessage) => {
  if (message.direction === 'IN') {
    return t('widgetSessions.visitor')
  }
  if (message.sender === 'system') {
    return t('widgetSessions.system')
  }
  if (message.sender === 'human') {
    return t('widgetSessions.operator')
  }
  return t('widgetSessions.assistant')
}

// Close SSE connection before page unload to prevent browser warning
const handleBeforeUnload = () => {
  if (eventSubscription.value) {
    eventSubscription.value.unsubscribe()
    eventSubscription.value = null
  }
}

onMounted(() => {
  loadWidget()
  loadSessions()
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
  if (eventSubscription.value) {
    eventSubscription.value.unsubscribe()
  }
})
</script>
