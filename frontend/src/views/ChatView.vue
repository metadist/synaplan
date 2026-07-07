<template>
  <MainLayout>
    <div
      class="flex flex-col h-full min-h-0 overflow-hidden relative"
      data-testid="page-chat"
      @dragenter.prevent="handleDragEnter"
      @dragover.prevent="handleDragOver"
      @dragleave="handleDragLeave"
      @drop.prevent="handleDrop"
    >
      <!-- Incognito toggle (desktop): there is no chat header, so the button
           floats over the top-right of the message area. The mobile instance
           lives in MainLayout (fixed top-right, mirroring the menu button). -->
      <div
        v-if="authStore.isAuthenticated"
        class="hidden md:block absolute top-3 right-3 z-30"
        data-testid="section-incognito-toggle-desktop"
      >
        <IncognitoToggle />
      </div>

      <!-- Drag & Drop Overlay - covers entire chat area -->
      <Transition name="fade">
        <div
          v-if="isDragging"
          class="absolute inset-0 z-50 flex items-center justify-center bg-primary/10 dark:bg-primary/20 backdrop-blur-sm border-2 border-dashed border-primary rounded-lg pointer-events-none"
        >
          <div class="flex flex-col items-center gap-3 p-6 surface-card rounded-xl shadow-lg">
            <div class="w-16 h-16 rounded-full bg-primary/20 flex items-center justify-center">
              <Icon icon="mdi:cloud-upload" class="w-8 h-8 text-primary" />
            </div>
            <div class="text-center">
              <p class="text-lg font-semibold txt-primary">{{ $t('chatInput.dropFiles') }}</p>
              <p class="text-sm txt-secondary mt-1">{{ $t('chatInput.dropFilesHint') }}</p>
            </div>
          </div>
        </div>
      </Transition>

      <div
        ref="chatContainer"
        class="flex-1 overflow-y-auto overflow-x-hidden bg-chat overscroll-contain chat-scroll-keyboard-pad"
        :class="{ 'flex flex-col items-center': isEmptyLanding }"
        data-testid="section-messages"
        @scroll="handleScroll"
      >
        <div class="max-w-4xl mx-auto py-6 px-4" :class="{ 'my-auto w-full': isEmptyLanding }">
          <!-- Loading indicator for infinite scroll -->
          <div
            v-if="historyStore.isLoadingMessages"
            class="flex items-center justify-center py-4"
            data-testid="state-loading"
          >
            <svg class="w-4 h-4 animate-spin txt-brand" fill="none" viewBox="0 0 24 24">
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              />
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              />
            </svg>
            <span class="ml-2 txt-secondary text-sm">Loading messages...</span>
          </div>

          <div
            v-if="guestStore.initFailed && !authStore.isAuthenticated"
            class="flex items-center justify-center h-full px-6"
            data-testid="state-guest-error"
          >
            <div class="text-center max-w-md">
              <div
                class="w-12 h-12 mx-auto mb-4 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center"
              >
                <svg
                  class="w-6 h-6 text-red-500"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"
                  />
                </svg>
              </div>
              <h2 class="text-xl font-semibold txt-primary mb-2">
                {{
                  guestStore.sessionExpired
                    ? $t('guest.expiredTitle')
                    : guestStore.rateLimited
                      ? $t('guest.rateLimitedTitle')
                      : $t('guest.errorTitle')
                }}
              </h2>
              <p class="txt-secondary mb-4">
                {{
                  guestStore.sessionExpired
                    ? $t('guest.expiredDescription')
                    : guestStore.rateLimited
                      ? $t('guest.rateLimitedDescription')
                      : $t('guest.errorDescription')
                }}
              </p>
              <div class="flex gap-3 justify-center">
                <button
                  class="px-4 py-2 rounded-lg btn-brand text-sm font-medium"
                  @click="guestStore.retryInit()"
                >
                  {{ $t('guest.retry') }}
                </button>
                <router-link
                  :to="{ name: 'register' }"
                  class="px-4 py-2 rounded-lg border border-[var(--border)] txt-secondary text-sm font-medium hover:bg-[var(--bg-secondary)] transition-colors"
                >
                  {{ $t('guest.createAccount') }}
                </router-link>
              </div>
            </div>
          </div>

          <div
            v-else-if="historyStore.messages.length === 0 && !historyStore.isLoadingMessages"
            class="flex flex-col items-center justify-center px-6 py-8 gap-6"
            data-testid="state-empty"
          >
            <div class="text-center">
              <div
                v-if="incognitoStore.active"
                class="w-12 h-12 mx-auto mb-3 rounded-full surface-chip flex items-center justify-center"
              >
                <Icon icon="mdi:incognito" class="w-6 h-6 txt-brand" aria-hidden="true" />
              </div>
              <h2 class="text-2xl font-semibold txt-primary mb-2">
                {{ incognitoStore.active ? $t('incognito.emptyTitle') : $t('welcome') }}
              </h2>
              <p class="txt-secondary">
                {{
                  incognitoStore.active ? $t('incognito.emptyHint') : $t('chatInput.placeholder')
                }}
              </p>
            </div>

            <!-- Centered hero composer (md+ only): the input starts high on the
                 empty screen and docks to the bottom on first send. On mobile
                 the composer lives at the bottom instead (see below), while this
                 welcome copy stays centered. -->
            <div v-if="showHeroComposer" class="w-full max-w-2xl">
              <ChatInput
                ref="chatInputRef"
                centered
                :is-streaming="isStreaming"
                :is-guest-mode="isGuestMode"
                :banner-visible="isGuestMode && guestStore.shouldShowBanner"
                :quote="quoting.pendingQuote.value"
                @send="handleSendMessage"
                @stop="handleUserStop"
                @guest-feature-gate="handleGuestFeatureGate"
                @clear-quote="quoting.clearPendingQuote"
              >
                <template v-if="isGuestMode" #banner>
                  <GuestBanner
                    :visible="guestStore.shouldShowBanner"
                    :remaining="guestStore.remainingMessages"
                    :max-messages="guestStore.maxMessages"
                    @dismiss="guestStore.dismissBanner()"
                  />
                </template>
                <template v-else-if="incognitoStore.active" #banner>
                  <div
                    class="flex items-center justify-center gap-2 px-4 py-2 mb-2 rounded-lg surface-chip text-xs txt-secondary"
                    data-testid="banner-incognito"
                  >
                    <Icon icon="mdi:incognito" class="w-4 h-4 txt-brand flex-shrink-0" />
                    <span>
                      <strong class="txt-primary">{{ $t('incognito.bannerTitle') }}</strong>
                      — {{ $t('incognito.bannerText') }}
                    </span>
                  </div>
                </template>
              </ChatInput>
            </div>

            <ExamplePrompts
              v-if="!authStore.isAuthenticated && !configStore.marketingNews.enabled"
              @pick="handleExamplePick"
            />
            <MarketingNews v-if="!authStore.isAuthenticated && configStore.marketingNews.enabled" />
          </div>

          <template v-for="(group, groupIndex) in groupedMessages" :key="groupIndex">
            <div class="flex items-center justify-center my-4" data-testid="item-message-group">
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
              :topic="message.topic"
              :original-topic="message.originalTopic"
              :original-media-type="message.originalMediaType"
              :again-data="message.againData"
              :backend-message-id="message.backendMessageId"
              :quoted-text="message.quotedText"
              :quoted-message-id="message.quotedMessageId"
              :processing-status="message.isStreaming ? processingStatus : undefined"
              :processing-metadata="message.isStreaming ? processingMetadata : undefined"
              :files="message.files"
              :search-results="message.searchResults"
              :ai-models="message.aiModels"
              :web-search="message.webSearch"
              :memory-ids="message.memoryIds"
              :feedback-ids="message.feedbackIds"
              :status="message.status"
              :error-type="message.errorType"
              :error-data="message.errorData"
              :truncated="message.truncated"
              :task-plan="message.taskPlan"
              :media-job="message.mediaJob"
              :was-multitask="message.wasMultitask"
              :is-guest-mode="isGuestMode"
              @regenerate="handleRegenerate(message, $event)"
              @again="handleAgain"
              @retry="handleRetryMessage(message, $event)"
              @retry-task="handleTaskRetry"
              @cancel-task="handleTaskCancel"
              @false-positive="openFalsePositiveModal"
              @click-memory="handleClickMemory"
              @continue="handleContinueResponse(message)"
              @media-job-update="message.mediaJob = $event"
              @media-job-completed="handleMediaJobCompleted(message, $event)"
              @media-job-cancel="mediaJobsStore.cancel($event)"
            />
          </template>
        </div>
      </div>

      <!-- Contextual Promo Tips -->
      <PromoTipBanner
        :tip="promoTips.currentTip.value"
        :expanded="promoTips.isExpanded.value"
        @toggle="promoTips.toggleExpand()"
        @dismiss="promoTips.dismissTip(false)"
        @dismiss-permanent="promoTips.dismissTip(true)"
        @action="handlePromoAction"
      />

      <QuoteSelectionButton
        :visible="quoting.floatingVisible.value"
        :position="quoting.floatingPosition.value"
        @quote="quoting.confirmQuote"
      />

      <ChatInput
        v-if="showBottomComposer"
        ref="chatInputRef"
        :is-streaming="isStreaming"
        :is-guest-mode="isGuestMode"
        :banner-visible="isGuestMode && guestStore.shouldShowBanner"
        :quote="quoting.pendingQuote.value"
        @send="handleSendMessage"
        @stop="handleUserStop"
        @guest-feature-gate="handleGuestFeatureGate"
        @clear-quote="quoting.clearPendingQuote"
      >
        <template v-if="isGuestMode" #banner>
          <GuestBanner
            :visible="guestStore.shouldShowBanner"
            :remaining="guestStore.remainingMessages"
            :max-messages="guestStore.maxMessages"
            @dismiss="guestStore.dismissBanner()"
          />
        </template>
        <template v-else-if="incognitoStore.active" #banner>
          <div
            class="flex items-center justify-center gap-2 px-4 py-2 mb-2 rounded-lg surface-chip text-xs txt-secondary"
            data-testid="banner-incognito"
          >
            <Icon icon="mdi:incognito" class="w-4 h-4 txt-brand flex-shrink-0" />
            <span>
              <strong class="txt-primary">{{ $t('incognito.bannerTitle') }}</strong>
              — {{ $t('incognito.bannerText') }}
            </span>
          </div>
        </template>
      </ChatInput>

      <!--
        Phase 3e: backgrounded memory extraction status pill.
        Lives outside the message bubble (fixed-position, bottom-right
        of the chat area) so it can never push content around or cause
        the assistant bubble to flicker. Driven by `memoryToastVisible`
        from `pollExtractedMemoriesOnce()` after the SSE stream has
        already closed.
      -->
      <Transition name="memory-toast">
        <div
          v-if="memoryToastVisible"
          class="memory-toast-pill"
          role="status"
          aria-live="polite"
          data-testid="memory-toast"
        >
          <svg
            class="w-4 h-4 txt-brand flex-shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
            />
          </svg>
          <span class="text-sm font-medium">{{ memoryToastMessage }}</span>
        </div>
      </Transition>
    </div>

    <!-- Limit Reached Modal -->
    <LimitReachedModal
      :is-open="showLimitModal"
      :limit-type="limitData?.limitType || 'lifetime'"
      :action-type="limitData?.actionType || 'MESSAGES'"
      :used="limitData?.used || 0"
      :current-limit="limitData?.limit || 0"
      :reset-time="limitData?.resetTime"
      :user-level="limitData?.userLevel || 'NEW'"
      :phone-verified="limitData?.phoneVerified || false"
      :topup-available="limitData?.topupAvailable || false"
      @close="closeLimitModal"
      @upgrade="closeLimitModal"
      @verify-phone="closeLimitModal"
    />

    <!-- Guest Signup Modal (shown when guest message limit is reached) -->
    <GuestSignupModal :is-open="showGuestSignupModal" @close="showGuestSignupModal = false" />

    <!-- Guest hint popover (shown when a guest taps a restricted feature) -->
    <GuestHintPopover
      :is-open="featureGateOpen"
      :feature-key="featureGateKey"
      @close="featureGateOpen = false"
    />

    <!-- Memory Suggestion Toasts -->
    <MemoryToast
      :memories="activeMemoryToasts"
      @dismiss="handleMemoryToastClose"
      @discard="handleMemoryDiscard"
      @edit="handleMemoryEdit"
    />

    <FalsePositiveModal
      :is-open="falsePositiveModalOpen"
      :segments="falsePositiveSegments"
      :full-text="falsePositiveFullText"
      :user-message="falsePositiveUserMessage"
      :is-submitting="falsePositiveSubmitting"
      :is-preview-loading="falsePositivePreviewLoading"
      :step="falsePositiveStep"
      :classification="falsePositiveClassification"
      :summary-options="falsePositiveSummaryOptions"
      :correction-options="falsePositiveCorrectionOptions"
      @close="closeFalsePositiveModal"
      @preview="previewFalsePositiveFeedback"
      @save="saveFalsePositiveFeedback"
      @back="backToFalsePositiveSelection"
      @regenerate="regenerateFalsePositiveSummary"
    />

    <ContradictionModal
      :is-open="contradictionModalOpen"
      :contradictions="contradictionList"
      :new-statement-summary="contradictionNewSummary"
      :new-statement-correction="contradictionNewCorrection"
      :classification="pendingSaveData?.classification ?? 'feedback'"
      :is-submitting="falsePositiveSubmitting"
      @close="closeContradictionModal"
      @resolve="handleContradictionResolve"
    />

    <!-- Memory Edit Dialog (opens in-place, stays in chat) -->
    <MemoryFormDialog
      :is-open="isMemoryEditDialogOpen"
      :memory="editingMemory"
      :available-categories="availableMemoryCategories"
      @close="closeMemoryEditDialog"
      @save="handleMemoryEditSave"
    />

    <MemoryDeleteDialog
      :is-open="isMemoryDeleteDialogOpen"
      :memory="deletingMemory"
      @close="closeMemoryDeleteDialog"
      @confirm="confirmMemoryDelete"
    />

    <!-- Memories List Dialog (for viewing all memories when clicking a memory badge) -->
    <MemoriesDialog
      :is-open="isMemoriesDialogOpen"
      :highlight-memory-id="highlightedMemoryId"
      @close="closeMemoriesDialog"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import ChatInput from '@/components/ChatInput.vue'
import ChatMessage from '@/components/ChatMessage.vue'
import MarketingNews from '@/components/MarketingNews.vue'
import ExamplePrompts from '@/components/ExamplePrompts.vue'
import QuoteSelectionButton from '@/components/QuoteSelectionButton.vue'
import { useMessageQuoting } from '@/composables/useMessageQuoting'
import LimitReachedModal from '@/components/common/LimitReachedModal.vue'
import {
  useHistoryStore,
  parseContentWithThinking,
  isTaskCardKind,
  isTaskCardState,
  type Message,
  type Part,
} from '@/stores/history'
import { useChatsStore, isDefaultChatTitle } from '@/stores/chats'
import { useModelsStore } from '@/stores/models'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import { useMediaJobsStore } from '@/stores/mediaJobs'
import { useGuestStore } from '@/stores/guest'
import { useConfigStore } from '@/stores/config'
import { useMemoriesStore } from '@/stores/userMemories'
import { useFeedbackStore } from '@/stores/userFeedback'
import { useIncognitoStore } from '@/stores/incognito'
import IncognitoToggle from '@/components/IncognitoToggle.vue'
import type { IncognitoHistoryEntry } from '@/services/api/chatApi'
import { useLimitCheck } from '@/composables/useLimitCheck'
import { useNotification } from '@/composables/useNotification'
import { chatApi } from '@/services/api'
import { prefetchSseToken } from '@/services/api/chatApi'
import type { ModelOption } from '@/composables/useModelSelection'
import { parseAIResponse } from '@/utils/responseParser'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import { generatePartId, pushMediaPart, extractMediaParts } from '@/utils/mediaParts'
import { buildUploadUrl, isAudioFileType } from '@/utils/mediaTypes'
import { isChannelSource } from '@/utils/channelSource'
import { AudioStreamer } from '@/utils/AudioStreamer'
import { httpClient } from '@/services/api/httpClient'
import { z } from 'zod'
import { parseMediaJobPayload, applyMediaJobUpdateToMessage } from '@/utils/messageMapper'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { getCategories, deleteMemory as deleteMemoryApi } from '@/services/api/userMemoriesApi'
import { deleteFeedback as deleteFeedbackApi } from '@/services/api/userFeedbackApi'
import MemoryToast from '@/components/MemoryToast.vue'
import FalsePositiveModal from '@/components/feedback/FalsePositiveModal.vue'
import ContradictionModal from '@/components/feedback/ContradictionModal.vue'
import {
  previewFalsePositive,
  submitFalsePositive,
  submitPositiveFeedback,
  regenerateCorrection,
  checkContradictionsBatch,
} from '@/services/api/feedbackApi'
import type { Contradiction } from '@/services/api/feedbackApi'
import MemoryFormDialog from '@/components/MemoryFormDialog.vue'
import MemoriesDialog from '@/components/MemoriesDialog.vue'
import MemoryDeleteDialog from '@/components/memories/MemoryDeleteDialog.vue'
import PromoTipBanner from '@/components/PromoTipBanner.vue'
import GuestBanner from '@/components/guest/GuestBanner.vue'
import GuestSignupModal from '@/components/guest/GuestSignupModal.vue'
import GuestHintPopover from '@/components/guest/GuestHintPopover.vue'
import { usePromoTips } from '@/composables/usePromoTips'
import { useDateFormat } from '@/composables/useDateFormat'

const SaveCancelledMessageResponseSchema = z
  .object({
    messageId: z.number().optional(),
    topic: z.string().optional(),
    provider: z.string().optional(),
    model: z.string().optional(),
  })
  .passthrough()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { showLimitModal, limitData, checkAndShowLimit, closeLimitModal } = useLimitCheck()
const { error: showErrorToast, success: showSuccessToast } = useNotification()

const chatContainer = ref<HTMLElement | null>(null)
const chatInputRef = ref<InstanceType<typeof ChatInput> | null>(null)
const quoting = useMessageQuoting(chatContainer)
const autoScroll = ref(true)
// While streaming we pin the start of the answer to the top once it grows past
// the viewport. If the user deliberately scrolls to the very bottom, switch to
// following the bottom instead (so the freshest text stays visible). Reset to
// pin mode whenever a new message is sent.
let stickToBottom = false
// Tracks the scrollTop value we last set programmatically so handleScroll can
// tell our own scroll adjustments apart from genuine user scrolling.
let expectedScrollTop = 0
// Optical breathing room kept below the container's top edge once a long
// streaming message gets pinned there.
const STREAM_TOP_GAP = 12
const isDragging = ref(false)
const dragCounter = ref(0)
const historyStore = useHistoryStore()
const chatsStore = useChatsStore()
const modelsStore = useModelsStore()
const aiConfigStore = useAiConfigStore()
const authStore = useAuthStore()
const mediaJobsStore = useMediaJobsStore()
const guestStore = useGuestStore()
const configStore = useConfigStore()
const memoriesStore = useMemoriesStore()
const feedbackStore = useFeedbackStore()
const incognitoStore = useIncognitoStore()
const promoTips = usePromoTips()
const { getDateLabel } = useDateFormat()

const isGuestMode = computed(() => !authStore.isAuthenticated && guestStore.isGuestMode)
const showGuestSignupModal = ref(false)
const featureGateOpen = ref(false)
const featureGateKey = ref('general')

// Empty landing: no messages, not loading, and not the guest-error state.
// Drives the centered hero composer (rendered inside state-empty) and hides the
// bottom sticky composer so there is only ever one live ChatInput instance.
const isEmptyLanding = computed(
  () =>
    !(guestStore.initFailed && !authStore.isAuthenticated) &&
    historyStore.messages.length === 0 &&
    !historyStore.isLoadingMessages
)

// Mobile breakpoint (< md, matches Tailwind's `md`). Initialised synchronously
// so the correct composer renders on first paint (no hero→bottom flash).
const isMobileViewport = ref(
  typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches
)
let mobileMql: MediaQueryList | null = null
const handleMobileMqlChange = (event: MediaQueryListEvent) => {
  isMobileViewport.value = event.matches
}

// On mobile the composer always docks at the bottom (messenger-style) while the
// welcome copy stays centered; on md+ the empty screen keeps its centered hero
// composer. Exactly one ChatInput instance is ever mounted.
const showHeroComposer = computed(() => isEmptyLanding.value && !isMobileViewport.value)
const showBottomComposer = computed(() => !isEmptyLanding.value || isMobileViewport.value)

function handleGuestFeatureGate(key: string) {
  featureGateKey.value = key
  featureGateOpen.value = true
}

function handleExamplePick(prompt: string) {
  chatInputRef.value?.submitText(prompt)
}

const handlePromoAction = (route: string) => {
  promoTips.dismissTip(false)
  if (route) router.push(route)
}

let streamingAbortController: AbortController | null = null
let stopStreamingFn: (() => void) | null = null // Store EventSource close function
let currentTrackId: number | undefined = undefined // Store current trackId for stop request
let currentStreamingChatId: number | undefined = undefined // Store chatId where stream was started
let currentAudioStreamer: AudioStreamer | null = null
const isAudioStreaming = ref(false)

// Processing status for real-time feedback
const processingStatus = ref<string>('')
type StreamingProcessingMetadata = {
  model_name?: string
  provider?: string
  topic?: string
  language?: string
  customMessage?: string
  results_count?: number
  handler?: string
}

const processingMetadata = ref<StreamingProcessingMetadata>({})

// Phase 3e: non-blocking pill that surfaces when backgrounded memory
// extraction (Phase 2) completes after the assistant message has already
// landed. Lives outside the bubble so it can never push content around.
const memoryToastVisible = ref(false)
const memoryToastMessage = ref('')

// Memory suggestion toasts
const activeMemoryToasts = ref<Array<UserMemory & { toastId: number }>>([])
let memoryToastIdCounter = 0

const falsePositiveModalOpen = ref(false)
const falsePositiveSegments = ref<string[]>([])
const falsePositiveFullText = ref('')
const falsePositiveMessageId = ref<number | null>(null)
const falsePositiveUserMessage = ref<string>('')
const falsePositiveSubmitting = ref(false)
const falsePositivePreviewLoading = ref(false)
const falsePositiveStep = ref<'select' | 'confirm'>('select')
const falsePositiveSummaryOptions = ref<string[]>([])
const falsePositiveCorrectionOptions = ref<string[]>([])
const falsePositiveClassification = ref<'memory' | 'feedback'>('feedback')
const falsePositiveRelatedMemoryIds = ref<number[]>([])

// Contradiction modal state (when saving feedback would conflict with existing data)
const contradictionModalOpen = ref(false)
const contradictionList = ref<Contradiction[]>([])
const contradictionNewSummary = ref('')
const contradictionNewCorrection = ref('')
const pendingSaveData = ref<{
  summary: string
  correction: string
  classification: 'memory' | 'feedback'
  relatedMemoryIds: number[]
} | null>(null)

// Memory edit dialog state
const isMemoryEditDialogOpen = ref(false)
const editingMemory = ref<UserMemory | null>(null)
const availableMemoryCategories = ref<string[]>([])

// Memory delete dialog state
const isMemoryDeleteDialogOpen = ref(false)
const deletingMemory = ref<(UserMemory & { toastId: number }) | null>(null)
let deleteDialogTimer: number | null = null
const deleteDialogAutoConfirmMs = 8000
const deleteDialogQueue = ref<Array<UserMemory & { toastId: number }>>([])

// Memories list dialog state (for viewing all memories)
const isMemoriesDialogOpen = ref(false)
const highlightedMemoryId = ref<number | null>(null)

// Use mock data in development or when API is not available
const useMockData = import.meta.env.VITE_USE_MOCK_DATA === 'true' || false

interface MessageGroup {
  label: string
  messages: Message[]
}

const isStreaming = computed(() => {
  return historyStore.messages.some((m) => m.isStreaming === true) || isAudioStreaming.value
})

// Init on mount
onMounted(async () => {
  // Subscribe to the per-user realtime channel so finished renders resolve
  // their banner instantly (push primary). No-op for guests / when realtime is
  // disabled; the 25s banner poll remains the fallback.
  void mediaJobsStore.subscribe(authStore.user?.id)
  // Hydrate the global Jobs tray with any renders already running across chats.
  if (authStore.isAuthenticated) {
    void mediaJobsStore.loadActive()
  }

  if (!authStore.isAuthenticated) {
    await guestStore.initSession()

    if (guestStore.chatId) {
      const rawMessages = await guestStore.loadMessages()
      if (rawMessages.length > 0) {
        historyStore.clear()
        const loaded: Message[] = rawMessages.map((m) => {
          const role: 'user' | 'assistant' = m.direction === 'IN' ? 'user' : 'assistant'
          const parts = parseContentWithThinking(m.text || '', role)
          const models = m.aiModels as Message['aiModels']
          const chatModel = models?.chat
          // Attached files (e.g. AI-generated documents) so the download
          // badge survives a page reload in guest mode.
          const files: Message['files'] =
            m.files && m.files.length > 0
              ? m.files.map((f) => ({
                  id: f.id,
                  filename: f.filename,
                  fileType: f.fileType,
                  filePath: f.filePath,
                  fileSize: f.fileSize ?? undefined,
                  fileMime: f.fileMime ?? undefined,
                }))
              : undefined
          return {
            id: `backend-${m.id}`,
            role,
            parts,
            timestamp: new Date(m.timestamp * 1000),
            provider: chatModel?.provider ?? m.provider ?? undefined,
            modelLabel: chatModel?.model ?? m.provider ?? (role === 'assistant' ? 'AI' : undefined),
            backendMessageId: m.id,
            aiModels: models ?? null,
            webSearch: (m.webSearch as Message['webSearch']) ?? null,
            searchResults: (m.searchResults as Message['searchResults']) ?? null,
            files,
          }
        })
        historyStore.messages.push(...loaded)
        await nextTick()
        scrollToBottom()
      }
    }

    const restricted = route.query.restricted as string | undefined
    if (restricted) {
      featureGateKey.value = restricted
      featureGateOpen.value = true
      router.replace({ query: {} })
    }

    setTimeout(() => {
      if (chatInputRef.value?.textareaRef) {
        chatInputRef.value.textareaRef.focus()
      }
    }, 100)

    window.addEventListener('open-memory-dialog', handleOpenMemoryDialogEvent)
    window.addEventListener('open-feedback-dialog', handleOpenFeedbackDialogEvent)
    return
  }

  // Load AI models config for Again functionality (await these - they're fast)
  await Promise.all([aiConfigStore.loadModels(), aiConfigStore.loadDefaults()])

  // Start loading memories in background (don't await - non-blocking!)
  // Memories button will be disabled until loaded
  memoriesStore.fetchMemories().catch((err) => {
    console.warn('⚠️ Failed to load memories in background:', err)
  })

  // Also load feedbacks in background for badge rendering
  feedbackStore.fetchFeedbacks({ silent: true }).catch((err) => {
    console.warn('⚠️ Failed to load feedbacks in background:', err)
  })

  // Load chats first
  await chatsStore.loadChats()

  // If no active chat, create one
  if (!chatsStore.activeChatId) {
    await chatsStore.createChat('New Chat')
  } else {
    // Load messages for active chat
    await historyStore.loadMessages(chatsStore.activeChatId)
  }

  // Scroll to newest message after initial load
  await nextTick()
  scrollToBottom(true)

  // Auto-focus ChatInput after mounting with delay
  setTimeout(() => {
    if (chatInputRef.value?.textareaRef) {
      chatInputRef.value.textareaRef.focus()
    } else {
      console.warn('⚠️ ChatInput ref not available for auto-focus')
    }
  }, 100)

  // Setup window event listener for memory dialog (used by MessageText.vue)
  window.addEventListener('open-memory-dialog', handleOpenMemoryDialogEvent)
  // Setup window event listener for feedback dialog (used by MessageText.vue)
  window.addEventListener('open-feedback-dialog', handleOpenFeedbackDialogEvent)

  // Phase 1d: keep the SSE token cache warm.
  //   - Prefetch on mount so the first message of a session never waits.
  //   - Re-prefetch when the tab regains focus (token may have expired in
  //     the background; warming it now avoids a stale-cache hit on the
  //     user's first interaction after switching back).
  prefetchSseToken()
  window.addEventListener('focus', prefetchSseToken)
  document.addEventListener('visibilitychange', handleVisibilityChangeForToken)

  // Track the mobile breakpoint so the composer docks at the bottom on phones.
  mobileMql = window.matchMedia('(max-width: 767px)')
  isMobileViewport.value = mobileMql.matches
  mobileMql.addEventListener('change', handleMobileMqlChange)
})

const handleVisibilityChangeForToken = () => {
  if (document.visibilityState === 'visible') {
    prefetchSseToken()
  }
}

const handleViewportResize = () => {
  if (!autoScroll.value || !chatContainer.value) return
  // While streaming, keep the pin behaviour intact instead of jumping to the
  // bottom (e.g. when the mobile keyboard resizes the visual viewport).
  if (historyStore.messages.some((m) => m.isStreaming)) {
    followStreamingScroll()
    return
  }
  chatContainer.value.scrollTop = chatContainer.value.scrollHeight
  expectedScrollTop = chatContainer.value.scrollTop
}

if (window.visualViewport) {
  window.visualViewport.addEventListener('resize', handleViewportResize)
}

// Native keyboard bridge (app/synaplan-native.js) fires this on show/hide with
// Keyboard.resize:'none', where visualViewport never changes — so this is the
// only signal that the keyboard opened. When the user is pinned to the bottom
// we re-pin after the inset-driven padding is applied, keeping the latest
// message right above the composer. rAF guarantees the new scrollHeight is
// measured (the padding var was set synchronously just before this event).
const handleKeyboardInsetChange = () => {
  if (!autoScroll.value || !chatContainer.value) return
  if (historyStore.messages.some((m) => m.isStreaming)) {
    followStreamingScroll()
    return
  }
  requestAnimationFrame(() => {
    if (!chatContainer.value) return
    chatContainer.value.scrollTop = chatContainer.value.scrollHeight
    expectedScrollTop = chatContainer.value.scrollTop
  })
}
window.addEventListener('synaplan:keyboardinset', handleKeyboardInsetChange)

// Window event handler for memory dialog (used by MessageText.vue)
const handleOpenMemoryDialogEvent = (event: Event) => {
  const customEvent = event as CustomEvent<{ memory: UserMemory }>
  if (customEvent.detail?.memory) {
    handleClickMemory(customEvent.detail.memory)
  }
}

// Window event handler for feedback dialog (used by MessageText.vue)
const handleOpenFeedbackDialogEvent = (event: Event) => {
  const customEvent = event as CustomEvent<{ feedbackId: number }>
  if (customEvent.detail?.feedbackId) {
    // Navigate to feedback page with highlight
    router.push({
      name: 'feedbacks',
      query: { highlight: String(customEvent.detail.feedbackId) },
    })
  }
}

// Detach (do NOT cancel) a running turn when the user navigates away or
// switches chats (issues #1142 / #1223 / #1225). Closes the SSE connection and
// clears local streaming state WITHOUT telling the backend to stop — the turn
// finishes in the background and is restored by loadMessages() on return.
// Explicit cancellation stays in handleUserStop() (the Stop button).
function handleNavigateAway() {
  finishStreamingTurnLocally()
}

// Cleanup: detach the running stream when the component unmounts (user leaves chat)
onBeforeUnmount(() => {
  isViewUnmounted = true
  mediaJobsStore.unsubscribe()
  handleNavigateAway()
  // Leaving the chat surface ends an incognito session: the transcript is
  // discarded and the session's ephemeral files are deleted (best effort —
  // the backend reaper covers the rest).
  if (incognitoStore.active) {
    void incognitoStore.endSession()
  }
  if (currentAudioStreamer) {
    currentAudioStreamer.stop()
    currentAudioStreamer = null
  }
  isAudioStreaming.value = false
  if (window.visualViewport) {
    window.visualViewport.removeEventListener('resize', handleViewportResize)
  }
  window.removeEventListener('synaplan:keyboardinset', handleKeyboardInsetChange)
  window.removeEventListener('open-memory-dialog', handleOpenMemoryDialogEvent)
  window.removeEventListener('open-feedback-dialog', handleOpenFeedbackDialogEvent)
  window.removeEventListener('focus', prefetchSseToken)
  document.removeEventListener('visibilitychange', handleVisibilityChangeForToken)
  mobileMql?.removeEventListener('change', handleMobileMqlChange)
  clearDeleteDialogTimer()
  clearMemoryPollTimers()
})

// Incognito session lifecycle: the toggle (MainLayout on mobile, floating
// button on desktop) only flips the store flag — this watcher swaps the
// transcript surface. The session transcript lives exclusively in the
// in-memory history store; the user's persisted chat is untouched and
// restored when the session ends.
watch(
  () => incognitoStore.active,
  async (active) => {
    // Detach any running turn (same semantics as switching chats).
    if (isStreaming.value) {
      handleNavigateAway()
    }
    quoting.clearPendingQuote()
    historyStore.clear()

    if (!active) {
      // Session ended: restore the persisted chat.
      if (chatsStore.activeChatId) {
        await historyStore.loadMessages(chatsStore.activeChatId)
      }
      showSuccessToast(t('incognito.ended'))
    }

    await nextTick()
    scrollToBottom(true)
    setTimeout(() => {
      chatInputRef.value?.textareaRef?.focus()
    }, 100)
  }
)

// Watch for active chat changes and load messages
watch(
  () => chatsStore.activeChatId,
  async (newChatId, oldChatId) => {
    // Picking a chat from the sidebar while incognito is active ends the
    // session (the incognito transcript is meant to be discarded). The
    // incognito watcher above restores nothing here — this watcher loads the
    // newly selected chat below.
    if (oldChatId !== newChatId && incognitoStore.active) {
      void incognitoStore.endSession()
    }

    // Switching chats DETACHES a running turn instead of cancelling it
    // (issue #1223): close the SSE connection and clear local streaming state,
    // but let the backend finish the turn in the background. Returning to the
    // chat restores the completed response via loadMessages().
    if (oldChatId !== newChatId && isStreaming.value) {
      handleNavigateAway()
    }

    // Cleanup: Delete previous chat if it was empty (no messages)
    // BUT: Only if the new chat is NOT also empty (to avoid flicker when creating multiple new chats)
    if (oldChatId && oldChatId !== newChatId) {
      const oldChat = chatsStore.chats.find((c) => c.id === oldChatId)
      const newChat = chatsStore.chats.find((c) => c.id === newChatId)

      // Only cleanup if old chat is empty AND new chat has messages OR is not brand new
      if (oldChat && (oldChat.messageCount === 0 || oldChat.messageCount === undefined)) {
        // Check if it actually has no messages by looking at history
        const hadMessages = historyStore.messages.length > 0

        // Don't cleanup if both old and new are empty (rapid "new chat" clicking)
        const newChatIsEmpty =
          newChat && (newChat.messageCount === 0 || newChat.messageCount === undefined)

        if (!hadMessages && !newChatIsEmpty) {
          await chatsStore.deleteChat(oldChatId, true) // silent = true
        }
      }
    }

    if (newChatId) {
      // Note: Don't call historyStore.clear() here!
      // loadMessages() replaces messages when offset=0, making clear() redundant.
      // Calling clear() first causes empty chat if loadMessages() fails silently.
      await historyStore.loadMessages(newChatId)
      await nextTick()
      scrollToBottom(true)

      // Auto-focus input when switching chats
      setTimeout(() => {
        if (chatInputRef.value?.textareaRef) {
          chatInputRef.value.textareaRef.focus()
        }
      }, 100)
    }
  }
)

async function generateChatTitleFromFirstMessage(firstMessage: string) {
  const chat = chatsStore.activeChat
  if (!chat) return

  if (chat.title && !isDefaultChatTitle(chat.title)) return

  // Only generate for user messages from this chat
  const userMessages = historyStore.messages.filter((m) => m.role === 'user')
  if (userMessages.length !== 1) return

  // Generate title from first message (take first 50 chars)
  let title = firstMessage.trim()
  if (title.length > 50) {
    title = title.substring(0, 47) + '...'
  }

  // Update chat title
  await chatsStore.updateChatTitle(chat.id, title)
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

const scrollToBottom = (force = false) => {
  if ((force || autoScroll.value) && chatContainer.value) {
    nextTick(() => {
      if (chatContainer.value) {
        chatContainer.value.scrollTop = chatContainer.value.scrollHeight
        // Read the clamped value back so the handleScroll guard recognises
        // this as a programmatic scroll.
        expectedScrollTop = chatContainer.value.scrollTop
        autoScroll.value = true
      }
    })
  }
}

// Follow a growing streaming message, but stop scrolling once its top edge
// reaches the top of the container. Computed purely from measured DOM offsets
// so it behaves identically on small and large screens.
const followStreamingScroll = () => {
  if (!autoScroll.value || !chatContainer.value) return
  nextTick(() => {
    const container = chatContainer.value
    if (!container) return
    const bottomMax = container.scrollHeight - container.clientHeight
    let target = bottomMax
    // The streaming assistant message is always the last rendered message
    // (the input is locked while streaming, so nothing can be appended after
    // it). We measure that node directly instead of relying on attribute
    // fallthrough, which ChatMessage does not forward to its root element.
    const messageEls = container.querySelectorAll<HTMLElement>('[data-testid="message-container"]')
    const el = messageEls[messageEls.length - 1]
    // When the user has scrolled to the bottom, keep following the bottom
    // instead of pinning the start to the top.
    if (!stickToBottom && el) {
      const messageTop =
        el.getBoundingClientRect().top - container.getBoundingClientRect().top + container.scrollTop
      // Short message: bottomMax wins (keep following the end). Once the
      // message grows past the viewport, messageTop stays fixed and wins the
      // min(), pinning the start at the top edge.
      target = Math.min(bottomMax, Math.max(0, messageTop - STREAM_TOP_GAP))
    }
    container.scrollTop = target
    expectedScrollTop = container.scrollTop
  })
}

// Drag & Drop handlers for file upload
const handleDragEnter = (event: DragEvent) => {
  // Check if dragging files
  if (event.dataTransfer?.types.includes('Files')) {
    dragCounter.value++
    isDragging.value = true
  }
}

const handleDragOver = (event: DragEvent) => {
  // Just prevent default to allow drop, don't change state here
  event.preventDefault()
}

const handleDragLeave = () => {
  dragCounter.value--
  // Only hide overlay when truly leaving the area
  if (dragCounter.value <= 0) {
    dragCounter.value = 0
    isDragging.value = false
  }
}

const handleDrop = async (event: DragEvent) => {
  dragCounter.value = 0
  isDragging.value = false
  const files = event.dataTransfer?.files
  if (files && files.length > 0 && chatInputRef.value) {
    await chatInputRef.value.uploadFiles(Array.from(files))
  }
}

const handleScroll = async () => {
  if (!chatContainer.value) return

  const { scrollTop, scrollHeight, clientHeight } = chatContainer.value

  // Distinguish our own programmatic scrolls (including the pinned position
  // that is NOT at the bottom) from real user scrolling. Only the latter may
  // toggle auto-follow off/on.
  const isProgrammatic = Math.abs(scrollTop - expectedScrollTop) <= 1
  if (!isProgrammatic) {
    const isAtBottom = Math.abs(scrollHeight - clientHeight - scrollTop) < 50
    autoScroll.value = isAtBottom
    // Scrolling to the very bottom opts into bottom-following; scrolling up
    // (anywhere above the bottom) leaves pin mode for the next stream.
    stickToBottom = isAtBottom
  }

  // Check if at top for loading more messages (Infinite Scroll)
  const isAtTop = scrollTop < 100
  if (
    isAtTop &&
    historyStore.hasMoreMessages &&
    !historyStore.isLoadingMessages &&
    chatsStore.activeChatId
  ) {
    const currentScrollHeight = scrollHeight
    await historyStore.loadMoreMessages(chatsStore.activeChatId)
    // Restore scroll position after loading
    await nextTick()
    if (chatContainer.value) {
      const newScrollHeight = chatContainer.value.scrollHeight
      chatContainer.value.scrollTop = newScrollHeight - currentScrollHeight + scrollTop
      // Keep the guard in sync so this position restore is recognised as a
      // programmatic scroll and does not toggle autoScroll/stickToBottom.
      expectedScrollTop = chatContainer.value.scrollTop
    }
  }
}

// Phase 3d: replace the deep watcher with two targeted ones.
//
//   1. Length of the messages array — fires when a brand new message
//      lands (user input, assistant response added). One scroll, no
//      per-chunk work.
//   2. Total accumulated character count of the streaming assistant
//      message's text parts — fires only while the active stream grows,
//      and only once per rAF tick because `renderStreamingContent` is
//      already throttled via requestAnimationFrame.
//
// The previous { deep: true } watcher fired on every nested mutation
// (every streaming chunk, every memory store update, every part
// content tweak) which forced multiple full layout passes per second
// and made the chat container thrash.
watch(
  () => historyStore.messages.length,
  () => {
    scrollToBottom()
  }
)

watch(
  () => {
    const streaming = historyStore.messages.find((m) => m.isStreaming)
    if (!streaming) return 0
    let total = 0
    for (const p of streaming.parts) {
      if ((p.type === 'text' || p.type === 'thinking') && p.content) {
        total += p.content.length
      }
    }
    return total
  },
  () => {
    followStreamingScroll()
  }
)

// Phase 2c: timers tied to the backgrounded memory-extraction polling.
// Tracked so onBeforeUnmount can cancel them — otherwise a slow Claude
// Opus extraction (or a queue backlog) can keep polling for ~25 s after
// the user navigates away and would update reactive state on an
// unmounted component.
const memoryPollTimers = new Set<ReturnType<typeof setTimeout>>()
let isViewUnmounted = false

function trackTimer(handle: ReturnType<typeof setTimeout>): void {
  memoryPollTimers.add(handle)
}

function clearMemoryPollTimers(): void {
  for (const handle of memoryPollTimers) {
    clearTimeout(handle)
  }
  memoryPollTimers.clear()
}

/**
 * Phase 2c: poll the backgrounded memory extraction outcome a couple of
 * times after the SSE stream completes. The messenger worker takes 2-7 s
 * to do the extraction LLM call + Qdrant writes; polling at 3 s and 8 s
 * covers ~95% of cases without hammering the API.
 *
 * Pushes any saved memories into the local memories store so the
 * `[Memory:ID]` badges in the assistant response resolve immediately. We
 * also surface a non-blocking toast/strip indicator (handled in Phase 3e).
 */
async function pollExtractedMemoriesOnce(messageId: number): Promise<boolean> {
  try {
    const result = await chatApi.getExtractedMemories(messageId)
    if (isViewUnmounted) return true
    if (result.status === 'pending') {
      return false
    }

    for (const memoryData of result.saved) {
      if (!memoryData?.id) continue

      const existing = memoriesStore.memories.find((m) => m.id === memoryData.id)
      if (!existing) {
        const allowedSources = [
          'auto_detected',
          'user_created',
          'user_edited',
          'ai_edited',
        ] as const
        type MemorySource = (typeof allowedSources)[number]
        const rawSource = String(memoryData.source ?? 'auto_detected')
        const source: MemorySource = (allowedSources as readonly string[]).includes(rawSource)
          ? (rawSource as MemorySource)
          : 'auto_detected'

        memoriesStore.memories.push({
          id: memoryData.id,
          category: String(memoryData.category ?? ''),
          key: String(memoryData.key ?? ''),
          value: String(memoryData.value ?? ''),
          source,
          messageId: (memoryData.messageId as number | null | undefined) ?? null,
          created: Number(memoryData.created ?? Math.floor(Date.now() / 1000)),
          updated: Number(memoryData.updated ?? Math.floor(Date.now() / 1000)),
        })
      }
    }

    // Mirror old `analyzing_memories → memories_complete` UI pulse, but
    // outside the bubble (Phase 3e moves the indicator to a fixed pill).
    if (result.saved.length > 0 || result.delete_suggestions.length > 0) {
      memoryToastMessage.value =
        result.saved.length > 0
          ? t('processing.memoriesCompleteTitle')
          : t('processing.analyzingMemoriesTitle')
      memoryToastVisible.value = true
      const hideHandle = setTimeout(() => {
        memoryPollTimers.delete(hideHandle)
        if (isViewUnmounted) return
        memoryToastVisible.value = false
      }, 3000)
      trackTimer(hideHandle)
    }

    return true
  } catch (e) {
    console.warn('Memory extraction poll failed:', e)
    return false
  }
}

function schedulePostStreamMemoryPoll(messageId: number): void {
  // Phase 2c: poll the backgrounded extraction outcome.
  //
  // Schedule increases gradually (2s, 4s, 7s, 12s, 20s) so cheap
  // extractions show toasts almost immediately while slow ones (Claude
  // Opus, queue backlog) still get picked up. Stop on the first non-
  // pending response or after the schedule is exhausted.
  const delaysMs = [2000, 4000, 7000, 12000, 20000]
  let attempt = 0

  const tick = async () => {
    if (isViewUnmounted) return
    if (attempt >= delaysMs.length) return
    const done = await pollExtractedMemoriesOnce(messageId)
    if (done || isViewUnmounted) return
    attempt += 1
    if (attempt < delaysMs.length) {
      const nextHandle = setTimeout(() => {
        memoryPollTimers.delete(nextHandle)
        void tick()
      }, delaysMs[attempt])
      trackTimer(nextHandle)
    }
  }

  const firstHandle = setTimeout(() => {
    memoryPollTimers.delete(firstHandle)
    void tick()
  }, delaysMs[0])
  trackTimer(firstHandle)
}

/**
 * Parse accumulated streaming content and update message parts.
 * Extracted so it can be called from the rAF throttle and the flush on 'complete'.
 *
 * Phase 3a + 3b refactor:
 *   - Each part gets a stable `partId` the first time it appears, so the
 *     `<MessagePart>` v-for can use it as `:key` and Vue won't reuse the
 *     wrong DOM nodes when parts split mid-stream.
 *   - Reconcile structural parts in place. The last text part grows by
 *     mutating `.content` (no new object reference), so child components
 *     bound to that part don't unmount/remount on every animation frame.
 *     Earlier parts (e.g. a finished code block followed by more streaming
 *     prose) keep their identity instead of being re-created each tick.
 */
function applyMediaJobToMessage(message: Message | undefined, raw: unknown): void {
  if (!message) return
  // Accept both the bare job payload ({ job_id, type, state }, as sent on the
  // `complete` event) AND a metadata envelope that nests it under `media_job` /
  // `mediaJob` (as sent on the `generating` progress event). Attaching the job
  // as early as the `generating` event lets the MediaJobStatus banner take over
  // immediately, so the bubble shows ONE in-progress surface instead of
  // flashing the routing status / inline "generating…" placeholder first.
  let parsed = parseMediaJobPayload(raw)
  if (!parsed && raw && typeof raw === 'object') {
    const envelope = raw as Record<string, unknown>
    parsed = parseMediaJobPayload(envelope.media_job ?? envelope.mediaJob)
  }
  if (parsed) {
    message.mediaJob = parsed
  }
}

/**
 * Pick the correct inline "generating…" placeholder token for a media job so a
 * type-less complete event never mislabels (e.g. showing "Generating your
 * video…" for an image request). Falls back to video for an unknown type.
 */
function generatingTokenForMediaJob(raw: unknown): string {
  let parsed = parseMediaJobPayload(raw)
  if (!parsed && raw && typeof raw === 'object') {
    const envelope = raw as Record<string, unknown>
    parsed = parseMediaJobPayload(envelope.media_job ?? envelope.mediaJob)
  }
  switch (parsed?.type) {
    case 'image':
      return '__IMAGE_GENERATING__'
    case 'audio':
      return '__AUDIO_GENERATING__'
    default:
      return '__VIDEO_GENERATING__'
  }
}

function handleMediaJobCompleted(message: Message, payload: { url: string; type: string }): void {
  // Shared with the realtime mediaJobs store so the poll and push completion
  // paths produce an identical result (state → done + media part appended).
  applyMediaJobUpdateToMessage(message, {
    job_id: message.mediaJob?.jobId ?? '',
    type: payload.type,
    state: 'done',
    file: { url: payload.url, type: payload.type },
  })

  if (message.backendMessageId) {
    void historyStore.reconcileMessage(message.id, message.backendMessageId)
  }
}

function renderStreamingContent(content: string, msgId: string): void {
  const trimmedContent = content.trim()

  // Detect file generation JSON — hide content during generation
  const looksLikeFileGeneration =
    (trimmedContent.startsWith('{') ||
      trimmedContent.startsWith('```json\n{') ||
      trimmedContent.startsWith('```\n{')) &&
    (trimmedContent.includes('BFILEPATH') || trimmedContent.includes('"BFILEPATH"'))

  if (looksLikeFileGeneration) {
    if (processingStatus.value !== 'generating_file') {
      processingStatus.value = 'generating_file'
      processingMetadata.value = { customMessage: t('processing.generatingFile') }
    }
    const message = historyStore.messages.find((m) => m.id === msgId)
    if (message) {
      // Issue #625: structural wipe must not drop in-flight media. The
      // SSE `file` event for image / video / audio can land before
      // this branch (e.g. a MEDIAMAKER turn whose JSON markup arrives
      // after the media was already uploaded) — preserving them here
      // keeps the audio player visible in the live bubble.
      message.parts = extractMediaParts(message.parts)
    }
    return
  }

  // Extract thinking blocks
  const thinkingMatches = content.match(/<think>([\s\S]*?)(<\/think>|$)/g)
  const desiredThinking: Part[] = []

  if (thinkingMatches) {
    thinkingMatches.forEach((match) => {
      const inner = match.replace(/<think>|<\/think>/g, '').trim()
      if (inner) {
        desiredThinking.push({ type: 'thinking', content: inner })
      }
    })
  }

  const displayContent = content.replace(/<think>[\s\S]*?<\/think>/g, '').trim()
  const parsed = parseAIResponse(displayContent)

  const message = historyStore.messages.find((m) => m.id === msgId)
  if (!message) return

  // Build the desired ordered list of structural parts.
  const desired: Part[] = [...desiredThinking]
  parsed.parts.forEach((part) => {
    if (part.type === 'text') {
      desired.push({ type: 'text', content: part.content })
    } else if (part.type === 'code' || part.type === 'json') {
      desired.push({ type: 'code', content: part.content, language: part.language })
    } else if (part.type === 'links' && part.links) {
      desired.push({
        type: 'links',
        items: part.links.map((l) => {
          try {
            return {
              title: l.title,
              url: l.url,
              desc: l.description,
              host: new URL(l.url).hostname,
            }
          } catch {
            return { title: l.title, url: l.url, desc: l.description, host: l.url }
          }
        }),
      })
    }
  })

  // Split current parts into (structural, media). Media parts (image / video
  // / audio) are pushed by separate SSE events and not part of `desired`;
  // we keep them appended after the structural section.
  const existingStructural = message.parts.filter(
    (p) => p.type === 'thinking' || p.type === 'text' || p.type === 'code' || p.type === 'links'
  )
  const existingMedia = extractMediaParts(message.parts)

  // Reconcile in-place so existing partIds (and therefore Vue keys) stay
  // stable. For each desired slot:
  //   - if the existing slot has the same `type`, mutate its content fields
  //     in-place (no new object — Vue's reactivity on the field still fires)
  //   - otherwise, build a fresh part with a new partId
  const reconciled: Part[] = []
  for (let i = 0; i < desired.length; i++) {
    const want = desired[i]
    const have = existingStructural[i]

    if (have && have.type === want.type) {
      if (!have.partId) {
        have.partId = generatePartId()
      }
      switch (want.type) {
        case 'text':
        case 'thinking':
          if (have.content !== want.content) {
            have.content = want.content
          }
          break
        case 'code':
          if (have.content !== want.content) have.content = want.content
          if (have.language !== want.language) have.language = want.language
          if (have.filename !== want.filename) have.filename = want.filename
          break
        case 'links':
          // Replace items wholesale only when changed (cheap JSON compare).
          if (JSON.stringify(have.items ?? []) !== JSON.stringify(want.items ?? [])) {
            have.items = want.items
          }
          break
      }
      reconciled.push(have)
    } else {
      want.partId = generatePartId()
      reconciled.push(want)
    }
  }

  message.parts = [...reconciled, ...existingMedia]
}

const handleContinueResponse = async (message: Message) => {
  if (!message.backendMessageId) return

  const chatId = chatsStore.activeChatId
  const userId = authStore.user?.id
  if (!chatId || !userId) return

  message.truncated = false
  message.isStreaming = true

  let fullContent = ''
  for (const p of message.parts) {
    if (p.type === 'thinking' && p.content) {
      fullContent += `<think>${p.content}</think>\n`
    } else if (p.type === 'text' && p.content) {
      fullContent += p.content
    }
  }

  const trackId = Date.now()
  let streamingRafId: number | null = null
  let streamingDirty = false

  const stopStreaming = chatApi.streamMessage({
    userId,
    message: '',
    chatId,
    trackId,
    continueMessageId: message.backendMessageId,
    onUpdate: (data) => {
      if (data.status === 'data' && data.chunk) {
        fullContent += data.chunk

        streamingDirty = true
        if (streamingRafId === null) {
          streamingRafId = requestAnimationFrame(() => {
            streamingRafId = null
            if (!streamingDirty) return
            streamingDirty = false
            renderStreamingContent(fullContent, message.id)
          })
        }
      } else if (data.status === 'reasoning' && data.chunk) {
        const msg = historyStore.messages.find((m) => m.id === message.id)
        if (msg) {
          let reasoningPart = msg.parts.find((p) => p.type === 'thinking' && p.isStreaming)
          if (!reasoningPart) {
            reasoningPart = { type: 'thinking', content: '', isStreaming: true }
            msg.parts.push(reasoningPart)
          }
          reasoningPart.content += data.chunk
        }
      } else if (data.status === 'complete') {
        if (streamingRafId !== null) {
          cancelAnimationFrame(streamingRafId)
          streamingRafId = null
        }

        renderStreamingContent(fullContent, message.id)

        if (data.truncated) {
          message.truncated = true
        }

        message.isStreaming = false
        historyStore.finishStreamingMessage(message.id)

        // Issue #1070: reconcile against the persisted message so files /
        // media / metadata reflect the authoritative backend state.
        if (message.backendMessageId) {
          void historyStore.reconcileMessage(message.id, message.backendMessageId)
        }
      } else if (data.status === 'error') {
        message.truncated = true
        message.isStreaming = false
        historyStore.finishStreamingMessage(message.id)
        showErrorToast(t('chat.continueFailed'))
      }
    },
  })

  stopStreamingFn = stopStreaming
}

const handleSendMessage = async (
  content: string,
  options?: {
    includeReasoning?: boolean
    webSearch?: boolean
    modelId?: number
    fileIds?: number[]
    voiceReply?: boolean
    ragGroupKey?: string
    quotedText?: string
    quotedMessageId?: number
  }
) => {
  autoScroll.value = true
  stickToBottom = false

  // Prepare files info if fileIds are provided
  let files: import('@/stores/history').MessageFile[] | undefined = undefined
  if (options?.fileIds && options.fileIds.length > 0) {
    // Import filesService dynamically
    const { default: filesService } = await import('@/services/filesService')

    // Fetch file details for each fileId
    files = []
    for (const fileId of options.fileIds) {
      try {
        const response = await filesService.getFileContent(fileId)
        if (response) {
          files.push({
            id: response.id,
            filename: response.filename,
            fileType: response.file_type,
            filePath: response.file_path,
            fileSize: response.file_size,
            fileMime: response.mime,
          })
        }
      } catch (error) {
        console.error('Failed to fetch file details:', fileId, error)
      }
    }
  }

  // File-only submission: provide a default message when no text but files are attached.
  // Issue #955: an audio-only submission (e.g. voice note from mobile, drag & drop of an
  // .ogg/.mp3) used to inherit the generic "Please review the attached file." default,
  // which made the LLM treat the recording as a document to summarise and produced
  // meta-commentary like "The OGG audio file contains…".
  //
  // For audio-only uploads we keep the localized voice placeholder for the
  // optimistic user bubble (so the chat doesn't show an empty row while the
  // transcription/streaming is in flight), but the value sent to the backend
  // is an empty string. `FileAnalysisHandler::isGenericAudioPlaceholder()`
  // matches the empty prompt structurally and routes the request through
  // the conversational voice path — no language-specific magic strings on
  // either side.
  const hasFiles = options?.fileIds && options.fileIds.length > 0
  const hasOnlyAudioFiles =
    hasFiles &&
    (files?.length ?? 0) > 0 &&
    files!.every((f) => isAudioFileType(f.fileType, f.fileMime))
  const displayMessage =
    !content.trim() && hasFiles
      ? hasOnlyAudioFiles
        ? t('chat.voiceMessageDefaultMessage')
        : t('chat.fileOnlyDefaultMessage')
      : content
  const backendMessage = !content.trim() && hasOnlyAudioFiles ? '' : displayMessage
  const messageToSend = displayMessage

  // Prepare webSearch metadata for user message
  const webSearchData = options?.webSearch ? { enabled: true } : null

  // Prepare tool metadata based on command in message
  // Also extract the clean content without command prefix for display
  let toolData: { command: string; label: string; icon: string } | null = null
  let displayContent = messageToSend
  let backendContent = backendMessage

  if (messageToSend.startsWith('/')) {
    const commandMatch = messageToSend.match(/^\/(\w+)\s+(.*)$/)
    if (commandMatch) {
      const cmd = commandMatch[1]
      const args = commandMatch[2] || ''

      const toolMap: Record<string, { label: string; icon: string }> = {
        search: { label: 'Web Search', icon: 'mdi:web' },
        pic: { label: 'Image Generation', icon: 'mdi:image' },
        vid: { label: 'Video Generation', icon: 'mdi:video' },
      }

      if (toolMap[cmd]) {
        toolData = { command: cmd, ...toolMap[cmd] }
        // Remove command prefix from display content
        displayContent = args.trim()

        // For /search, send only the query to backend (we use webSearch flag)
        // For /pic and /vid, keep the full command (backend needs it for routing)
        if (cmd === 'search') {
          backendContent = args.trim()
        }
      }
    }
  }

  // Add user message with files, webSearch, and tool info
  // Use displayContent (without command) for the message text shown in UI.
  //
  // Issue #955: when the upload contains audio files, surface them as an
  // <audio> player on the user bubble immediately (in addition to the file
  // badge). Without this the only visible artifact of a voice upload was
  // the transcribed text — there was no way to replay the original
  // recording from the web chat.
  const optimisticParts: import('@/stores/history').Part[] = [
    { type: 'text', content: displayContent },
  ]
  if (files && files.length > 0) {
    for (const file of files) {
      if (!isAudioFileType(file.fileType, file.fileMime)) continue
      const audioUrl = buildUploadUrl(file.filePath)
      if (!audioUrl) continue
      optimisticParts.push({
        partId: generatePartId(),
        type: 'audio',
        url: normalizeMediaUrl(audioUrl),
      })
    }
  }

  historyStore.addMessage(
    'user',
    optimisticParts,
    files,
    undefined, // provider
    undefined, // modelLabel
    undefined, // againData
    undefined, // backendMessageId
    undefined, // originalMessageId
    webSearchData, // webSearch
    toolData, // tool
    options?.quotedText ?? null, // quotedText
    options?.quotedMessageId ?? null // quotedMessageId
  )

  // Lift the active chat to the top of the sidebar lists right away so the
  // conversation the user is interacting with is the most prominent one,
  // matching the backend's `updatedAt DESC` order on the next reload.
  // Mirrors the backend preview format (30 chars + ellipsis).
  // Incognito: the turn belongs to no chat — never touch the sidebar.
  if (chatsStore.activeChatId && !incognitoStore.active) {
    const previewSource = displayContent.trim()
    const preview =
      previewSource.length > 30 ? previewSource.slice(0, 30) + '…' : previewSource || undefined
    chatsStore.bumpChatActivity(chatsStore.activeChatId, {
      firstMessagePreview: preview,
    })
  }

  // Notify promo tip system
  promoTips.onMessageSent()

  // Stream to backend - use backendContent which may differ from displayContent
  await streamAIResponse(backendContent, options)
}

/**
 * Populate the clickable model footer live so it matches what a page reload
 * would show.
 *
 * Prefers the nested `aiModels` payload (chat + sorting) when the backend
 * sends it on the SSE `complete` event — this is what makes the sorting-model
 * badge appear without a refresh (issue #603). Falls back to synthesising
 * `aiModels.chat` from the flat `provider`/`model` fields for backward
 * compatibility with older backends / error paths that don't ship a nested
 * shape.
 */
function applyAssistantChatModelFooter(
  message: Message,
  data: {
    provider?: string
    model?: string
    model_id?: number | null
    aiModels?: Message['aiModels'] | null
  },
  streamFallback: { provider?: string; model?: string; model_id?: number | null }
) {
  const isBadModelToken = (m: unknown) =>
    m === undefined ||
    m === null ||
    String(m).toLowerCase() === 'error' ||
    // Reject channel/source tokens (`WHATSAPP`, `EMAIL`, …) — they
    // leak in from inbound-message `provider_index` and would otherwise
    // surface as the AI model label in the chat footer (issue #653).
    isChannelSource(typeof m === 'string' ? m : null)
  const isBadProviderToken = (p: unknown) =>
    p === undefined ||
    p === null ||
    String(p).toLowerCase() === 'system' ||
    isChannelSource(typeof p === 'string' ? p : null)

  const resolvedModel = !isBadModelToken(data.model)
    ? String(data.model)
    : streamFallback.model || message.modelLabel
  const resolvedProvider = !isBadProviderToken(data.provider)
    ? String(data.provider)
    : streamFallback.provider || message.provider

  const resolvedId =
    data.model_id !== undefined && data.model_id !== null
      ? data.model_id
      : (streamFallback.model_id ?? null)

  const nestedChat = data.aiModels?.chat
  const nestedSorting = data.aiModels?.sorting
  // Audio (TTS) model is independent of the chat model — pass it
  // through whenever the backend ships it so the voice-reply badge
  // appears live (no page reload required). See issue #583.
  const nestedAudio = data.aiModels?.audio

  if (resolvedModel && resolvedProvider) {
    message.modelLabel = resolvedModel
    message.provider = resolvedProvider
    message.aiModels = {
      chat: nestedChat ?? {
        provider: resolvedProvider,
        model: resolvedModel,
        model_id: resolvedId,
      },
      ...(nestedSorting ? { sorting: nestedSorting } : {}),
      ...(nestedAudio ? { audio: nestedAudio } : {}),
    }
  } else if (nestedChat || nestedSorting || nestedAudio) {
    message.aiModels = {
      ...(nestedChat ? { chat: nestedChat } : {}),
      ...(nestedSorting ? { sorting: nestedSorting } : {}),
      ...(nestedAudio ? { audio: nestedAudio } : {}),
    }
  }
}

/**
 * Snapshot the in-memory incognito transcript for the backend: prior turns
 * only (the current user message travels as `message`, the freshly created
 * assistant placeholder is still streaming). The backend caps the payload at
 * ~30 entries / 15k chars, so no client-side cap is needed.
 */
function buildIncognitoHistorySnapshot(): IncognitoHistoryEntry[] {
  const entries: IncognitoHistoryEntry[] = []
  for (const m of historyStore.messages) {
    if (m.isStreaming) continue
    const text = m.parts
      .filter((p) => p.type === 'text' && p.content)
      .map((p) => p.content as string)
      .join('\n')
      .trim()
    if (!text) continue
    entries.push({ role: m.role, content: text })
  }
  // Drop the current turn's user message (it was just appended by
  // handleSendMessage and is sent separately).
  if (entries.length > 0 && entries[entries.length - 1].role === 'user') {
    entries.pop()
  }
  return entries
}

const streamAIResponse = async (
  userMessage: string,
  options?: {
    includeReasoning?: boolean
    webSearch?: boolean
    modelId?: number
    fileIds?: number[]
    voiceReply?: boolean
    isAgain?: boolean
    ragGroupKey?: string
    quotedText?: string
    quotedMessageId?: number
  }
) => {
  streamingAbortController = new AbortController()

  const currentModel =
    aiConfigStore.models.CHAT?.find((model) => model.id === options?.modelId) ??
    aiConfigStore.getCurrentModel('CHAT')
  const provider = currentModel?.service || modelsStore.selectedProvider
  const modelLabel = currentModel?.name || modelsStore.selectedModel

  // Create empty streaming message with provider info
  const messageId = historyStore.addStreamingMessage('assistant', provider, modelLabel)

  let streamingRafId: number | null = null
  let streamingDirty = false

  try {
    if (useMockData) {
      // Mock streaming for development (simple text streaming)
      const mockResponse =
        'This is a mock response for development. The actual streaming is handled by the backend API.'

      // Simple character-by-character streaming
      for (let i = 0; i < mockResponse.length; i += 3) {
        if (streamingAbortController.signal.aborted) {
          break
        }
        const chunk = mockResponse.slice(0, i + 3)
        historyStore.updateStreamingMessage(messageId, chunk)
        await new Promise((resolve) => setTimeout(resolve, 30))
      }

      historyStore.finishStreamingMessage(messageId)
    } else if (isGuestMode.value) {
      // Guest mode streaming — mirrors the authenticated handler for full feature parity
      const guestChatId = await guestStore.ensureChat()
      if (!guestChatId || !guestStore.sessionId) {
        console.error('Guest chat or session not available')
        historyStore.finishStreamingMessage(messageId)
        return
      }

      const trackId = Date.now()
      currentTrackId = trackId
      let fullContent = ''

      processingStatus.value = 'started'
      processingMetadata.value = {}

      const stopStreaming = chatApi.streamGuestMessage({
        guestSessionId: guestStore.sessionId,
        message: userMessage,
        chatId: guestChatId,
        trackId,
        quotedText: options?.quotedText,
        quotedMessageId: options?.quotedMessageId,
        onUpdate: (data) => {
          if (streamingAbortController?.signal.aborted) return

          if (data.status === 'guest_limit_reached') {
            showGuestSignupModal.value = true
            processingStatus.value = ''
            processingMetadata.value = {}
            historyStore.finishStreamingMessage(messageId)
            return
          }

          if (data.status === 'guest_remaining') {
            const remaining = (data as Record<string, unknown>).remaining as number
            const max = (data as Record<string, unknown>).maxMessages as number
            const reached = (data as Record<string, unknown>).limitReached as boolean
            guestStore.updateCount(remaining, max, reached)
            guestStore.showBanner()
            return
          }

          if (data.status === 'started') {
            processingStatus.value = 'started'
            processingMetadata.value = {}
          } else if (data.status === 'preprocessing') {
            processingStatus.value = 'preprocessing'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'analyzing') {
            processingStatus.value = 'analyzing'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'classifying') {
            processingStatus.value = 'classifying'
            processingMetadata.value = data.metadata || {}
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message && data.metadata) {
              const prov = data.metadata.provider
              const mname = data.metadata.model_name
              if (typeof prov === 'string') message.provider = prov
              if (typeof mname === 'string') message.modelLabel = mname
            }
          } else if (data.status === 'classified') {
            const meta = data.metadata || {}
            processingMetadata.value = meta
            processingStatus.value = 'classified'
          } else if (data.status === 'searching') {
            processingStatus.value = 'searching'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'search_complete') {
            processingStatus.value = 'search_complete'
            processingMetadata.value = data.metadata || {}
            // Surface the sources immediately (they arrive seconds before the
            // answer) so the "sources" box renders now, with the generating
            // indicator acting as the placeholder above the soon-to-stream text.
            const earlySources = data.metadata?.results
            if (Array.isArray(earlySources) && earlySources.length > 0) {
              const searchMsg = historyStore.messages.find((m) => m.id === messageId)
              if (searchMsg) {
                searchMsg.searchResults = earlySources as NonNullable<Message['searchResults']>
                searchMsg.webSearch = {
                  query: typeof data.metadata?.query === 'string' ? data.metadata.query : '',
                  resultsCount: earlySources.length,
                }
              }
            }
          } else if (data.status === 'generating') {
            processingStatus.value = 'generating'
            processingMetadata.value = {
              customMessage: data.message || undefined,
              ...(data.metadata || {}),
            }
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message && data.metadata) {
              const prov = data.metadata.provider
              const mname = data.metadata.model_name
              if (typeof prov === 'string') message.provider = prov
              if (typeof mname === 'string') message.modelLabel = mname
              applyMediaJobToMessage(message, data.metadata)
            }
          } else if (data.status === 'generating_file') {
            // Staged document generation progress (officemaker): the backend
            // announces `writing` while the model produces the document text
            // and `converting` while the office file is built.
            processingStatus.value = 'generating_file'
            processingMetadata.value = data.metadata || {}
          } else if (data.status === 'processing') {
            // Processing/routing — no UI update needed
          } else if (data.status === 'data' && data.chunk) {
            if (processingStatus.value) {
              processingStatus.value = ''
              processingMetadata.value = {}
            }
            fullContent += data.chunk

            streamingDirty = true
            if (streamingRafId === null) {
              streamingRafId = requestAnimationFrame(() => {
                streamingRafId = null
                if (!streamingDirty) return
                streamingDirty = false
                renderStreamingContent(fullContent, messageId)
              })
            }
          } else if (data.status === 'reasoning' && data.chunk) {
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message) {
              let reasoningPart = message.parts.find((p) => p.type === 'thinking' && p.isStreaming)
              if (!reasoningPart) {
                reasoningPart = { type: 'thinking', content: '', isStreaming: true }
                message.parts.unshift(reasoningPart)
              }
              reasoningPart.content += data.chunk
            }
          } else if (data.status === 'complete') {
            if (streamingRafId !== null) {
              cancelAnimationFrame(streamingRafId)
              streamingRafId = null
            }
            streamingDirty = false

            if (data.truncated) {
              fullContent += '\n\n---\n\n⚠️ *' + t('message.truncated') + '*'
            }

            if (fullContent) {
              renderStreamingContent(fullContent, messageId)
            } else if (data.mediaJob || data.media_job) {
              renderStreamingContent(
                generatingTokenForMediaJob(data.mediaJob ?? data.media_job),
                messageId
              )
            }

            processingStatus.value = ''
            processingMetadata.value = {}

            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message) {
              applyMediaJobToMessage(message, data.mediaJob ?? data.media_job)

              if (data.messageId) {
                message.backendMessageId = data.messageId
              }

              // Generated file (e.g. officemaker Word document): attach the
              // download badge and replace the raw JSON / marker with the
              // translated confirmation — mirrors the authenticated handler.
              if (data.generatedFile) {
                if (!message.files) {
                  message.files = []
                }
                message.files.push({
                  id: data.generatedFile.id,
                  filename: data.generatedFile.filename,
                  filePath: data.generatedFile.path,
                  fileSize: data.generatedFile.size,
                  fileType: data.generatedFile.type,
                  fileMime: data.generatedFile.mime,
                })

                const hasJsonOrMarker =
                  message.parts.length === 0 ||
                  (message.parts[0].type === 'code' &&
                    message.parts[0].content?.includes('BFILEPATH')) ||
                  (message.parts[0].type === 'text' &&
                    message.parts[0].content?.includes('__FILE_GENERATED__'))

                if (hasJsonOrMarker) {
                  message.parts = [
                    {
                      type: 'text',
                      content: t('message.fileGenerated', {
                        filename: data.generatedFile.filename,
                      }),
                    },
                  ]
                }
              }

              if (
                data.searchResults &&
                Array.isArray(data.searchResults) &&
                data.searchResults.length > 0
              ) {
                message.searchResults = data.searchResults as NonNullable<Message['searchResults']>
                message.webSearch = {
                  query: data.searchResults[0]?.query || '',
                  resultsCount: data.searchResults.length,
                }
              }

              applyAssistantChatModelFooter(
                message,
                {
                  provider: data.provider,
                  model: data.model,
                  model_id: data.model_id ?? null,
                  aiModels: data.aiModels ?? null,
                },
                { provider, model: modelLabel, model_id: currentModel?.id ?? null }
              )
            }

            historyStore.finishStreamingMessage(messageId)
            scrollToBottom()
          } else if (data.status === 'error') {
            if (streamingRafId !== null) {
              cancelAnimationFrame(streamingRafId)
              streamingRafId = null
            }
            processingStatus.value = ''
            processingMetadata.value = {}
            historyStore.finishStreamingMessage(messageId)
          }
        },
      })

      stopStreamingFn = stopStreaming
    } else {
      // Use real Backend API with SSE streaming
      const userId = authStore.user?.id || 1
      const incognito = incognitoStore.active
      // Incognito turns belong to no chat — the backend processes them fully
      // in-memory and gets the conversation context from the history payload.
      const chatId = incognito ? undefined : (chatsStore.activeChatId ?? undefined)

      if (!chatId && !incognito) {
        console.error('No active chat selected')
        return
      }

      // Snapshot the in-memory transcript for the backend (prior turns only:
      // the current user message is sent separately, and the just-created
      // assistant placeholder is still streaming).
      const incognitoHistory: IncognitoHistoryEntry[] = incognito
        ? buildIncognitoHistorySnapshot()
        : []

      const trackId = Date.now()
      currentTrackId = trackId // Store for stop functionality
      currentStreamingChatId = chatId ?? undefined // Store chatId for stop functionality
      let fullContent = ''

      const includeReasoning = options?.includeReasoning ?? false
      const webSearch = options?.webSearch ?? false
      // IMPORTANT: Only pass modelId if explicitly provided (e.g., "Again" function)
      // For normal requests, let backend do classification/sorting to determine the right handler
      const finalModelId = options?.modelId // Don't fallback to current model!
      const fileIds = options?.fileIds || [] // Array of fileIds

      // Initialize AudioStreamer if voice reply is requested
      if (currentAudioStreamer) {
        currentAudioStreamer.stop()
        currentAudioStreamer = null
      }

      let spokenLength = 0
      let audioText = ''
      let insideThinkBlock = false
      let detectedLanguage = 'en'

      if (options?.voiceReply) {
        currentAudioStreamer = new AudioStreamer()
        isAudioStreaming.value = true
        currentAudioStreamer.setOnFinished(() => {
          isAudioStreaming.value = false
          currentAudioStreamer = null
        })
      }

      const stopStreaming = chatApi.streamMessage({
        userId,
        message: userMessage,
        trackId,
        chatId,
        incognito,
        history: incognitoHistory,
        includeReasoning,
        webSearch,
        modelId: finalModelId,
        fileIds,
        voiceReply: options?.voiceReply,
        isAgain: options?.isAgain,
        ragGroupKey: options?.ragGroupKey,
        quotedText: options?.quotedText,
        quotedMessageId: options?.quotedMessageId,
        onUpdate: (data) => {
          // CRITICAL: Check abort signal at the very beginning
          if (streamingAbortController?.signal.aborted) {
            return
          }

          // [i2v-debug] Opt-in multitask/media tracing: a task card stuck at
          // "Rendering… 95%" gives no clue whether the worker ever finishes. Log
          // the plan + task lifecycle (progress, terminal state, produced file) so
          // we can see in the browser console if the closing task_update/task_file
          // actually reaches the client over the realtime channel. Gated behind
          // `localStorage.setItem('synaplanDebug', '1')` (same pattern as the
          // 'perf' tracing) so production consoles stay quiet and no internal
          // metadata (file URLs/errors) leaks unless a developer opts in.
          try {
            if (
              typeof window !== 'undefined' &&
              window.localStorage?.getItem('synaplanDebug') &&
              typeof data.status === 'string' &&
              (data.status === 'plan' ||
                data.status === 'plan_discarded' ||
                data.status.startsWith('task_'))
            ) {
              console.debug('[i2v-debug]', data.status, {
                node_id: data.metadata?.node_id,
                state: data.metadata?.state,
                percent: data.metadata?.percent,
                provider_status: data.metadata?.provider_status,
                elapsed_seconds: data.metadata?.elapsed_seconds,
                url: data.metadata?.url,
                error: data.metadata?.error,
              })
            }
          } catch {
            // never let debug tracing break the stream handler
          }

          // Handle different status events for UI feedback
          if (data.status === 'started') {
            processingStatus.value = 'started'
            processingMetadata.value = {}
          } else if (data.status === 'preprocessing') {
            processingStatus.value = 'preprocessing'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'analyzing') {
            // Analyzing phase (e.g., understanding media generation request)
            processingStatus.value = 'analyzing'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'classifying') {
            processingStatus.value = 'classifying'
            processingMetadata.value = data.metadata || {}

            // Update message with sorting model from backend (instead of store model)
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message && data.metadata) {
              const prov = data.metadata.provider
              const mname = data.metadata.model_name
              if (typeof prov === 'string') {
                message.provider = prov
              }
              if (typeof mname === 'string') {
                message.modelLabel = mname
              }
            }
          } else if (data.status === 'classified') {
            const meta = data.metadata || {}
            processingMetadata.value = meta
            processingStatus.value = 'classified'
            // Capture language for TTS streaming
            if (typeof meta.language === 'string') {
              detectedLanguage = meta.language
            }
          } else if (data.status === 'searching') {
            processingStatus.value = 'searching'
            processingMetadata.value = { customMessage: data.message }
          } else if (data.status === 'search_complete') {
            processingStatus.value = 'search_complete'
            processingMetadata.value = data.metadata || {}
            // Surface the sources immediately (they arrive seconds before the
            // answer) so the "sources" box renders now, with the generating
            // indicator acting as the placeholder above the soon-to-stream text.
            const earlySources = data.metadata?.results
            if (Array.isArray(earlySources) && earlySources.length > 0) {
              const searchMsg = historyStore.messages.find((m) => m.id === messageId)
              if (searchMsg) {
                searchMsg.searchResults = earlySources as NonNullable<Message['searchResults']>
                searchMsg.webSearch = {
                  query: typeof data.metadata?.query === 'string' ? data.metadata.query : '',
                  resultsCount: earlySources.length,
                }
              }
            }
          } else if (data.status === 'generating') {
            processingStatus.value = 'generating'
            // Use custom message from backend if available, otherwise default
            processingMetadata.value = {
              customMessage: data.message || undefined,
              ...(data.metadata || {}),
            }

            // Update message with real model from backend (instead of store model)
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message && data.metadata) {
              const prov = data.metadata.provider
              const mname = data.metadata.model_name
              if (typeof prov === 'string') {
                message.provider = prov
              }
              if (typeof mname === 'string') {
                message.modelLabel = mname
              }
              applyMediaJobToMessage(message, data.metadata)
            }
          } else if (data.status === 'generating_file') {
            // Staged document generation progress (officemaker): the backend
            // announces `writing` while the model produces the document text
            // and `converting` while the office file is built.
            processingStatus.value = 'generating_file'
            processingMetadata.value = data.metadata || {}
          } else if (data.status === 'processing') {
            // Processing/routing messages - improved logging
          } else if (data.status === 'thinking') {
            // Phase 1e: surface model "thinking" reasoning so the bubble
            // doesn't sit empty for 5-8 s on Gemini Pro.
            processingStatus.value = 'thinking'
            processingMetadata.value = { customMessage: data.message || undefined }
          } else if (data.status === 'analyzing_memories') {
            // 🎯 Memory analysis started
            processingStatus.value = 'analyzing_memories'
            processingMetadata.value = { customMessage: data.message || undefined }
          } else if (data.status === 'saving_memories') {
            // 🎯 Saving memories
            processingStatus.value = 'saving_memories'
            processingMetadata.value = { customMessage: data.message || undefined }
          } else if (data.status === 'memories_complete') {
            // 🎯 Memory analysis complete
            processingStatus.value = 'memories_complete'
            processingMetadata.value = { customMessage: data.message || undefined }
            // Clear processing status after a short delay
            setTimeout(() => {
              if (processingStatus.value === 'memories_complete') {
                processingStatus.value = ''
                processingMetadata.value = {}
              }
            }, 2000)
          } else if (data.status === 'status') {
            // Generic status message
          } else if (data.status === 'plan') {
            // Multitask routing: a multi-node plan was recognized. Render a task
            // card per node. Single-node turns never emit this, so normal chat
            // is unaffected.
            const message = historyStore.messages.find((m) => m.id === messageId)
            const tasks = data.metadata?.plan
            if (message && Array.isArray(tasks) && tasks.length > 0) {
              processingStatus.value = ''
              processingMetadata.value = {}
              message.wasMultitask = true
              message.taskPlan = {
                active: true,
                // Persist the turn id on the plan so the per-card Stop button can
                // always reach the backend even if currentTrackId is cleared by a
                // racing handler (issue #1141).
                trackId: currentTrackId,
                replyNode:
                  typeof data.metadata?.reply_node === 'string' ? data.metadata.reply_node : '',
                cards: tasks.map((t) => ({
                  nodeId: t.node_id,
                  capability: t.capability,
                  kind: isTaskCardKind(t.kind) ? t.kind : 'text',
                  state: 'pending' as const,
                })),
              }
            }
          } else if (data.status === 'plan_discarded') {
            // The DAG failed entirely and the backend is falling back to a normal
            // single-bubble answer. Retract the (now misleading) failed task cards
            // so the clean reply below isn't sitting under a "step failed" box.
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message) {
              message.taskPlan = null
              message.wasMultitask = false
            }
          } else if (data.status === 'task_update') {
            const message = historyStore.messages.find((m) => m.id === messageId)
            const card = message?.taskPlan?.cards.find((c) => c.nodeId === data.metadata?.node_id)
            // A user-cancelled step is terminal on the client: ignore the
            // backend 'failed' that follows the abort so the card stays
            // 'cancelled' (neutral) instead of flipping to a scary error.
            if (card && card.state === 'cancelled') {
              // keep cancelled
            } else if (card && isTaskCardState(data.metadata?.state)) {
              card.state = data.metadata.state
              // Failure details: specific error text + (for media nodes) the
              // resolved prompt powering the per-task retry button.
              if (typeof data.metadata?.error === 'string' && data.metadata.error) {
                card.error = data.metadata.error
              }
              if (typeof data.metadata?.prompt === 'string' && data.metadata.prompt) {
                card.prompt = data.metadata.prompt
              }
              // Web search card compact summary — populated by DagExecutor on done.
              if (typeof data.metadata?.query === 'string' && data.metadata.query) {
                card.query = data.metadata.query
              }
              if (typeof data.metadata?.results_count === 'number') {
                card.resultsCount = data.metadata.results_count
              }
            }
          } else if (data.status === 'task_chunk') {
            const message = historyStore.messages.find((m) => m.id === messageId)
            const card = message?.taskPlan?.cards.find((c) => c.nodeId === data.metadata?.node_id)
            if (card && typeof data.metadata?.chunk === 'string') {
              card.text = (card.text ?? '') + data.metadata.chunk
            }
          } else if (data.status === 'task_file') {
            const message = historyStore.messages.find((m) => m.id === messageId)
            const card = message?.taskPlan?.cards.find((c) => c.nodeId === data.metadata?.node_id)
            if (card && typeof data.metadata?.url === 'string') {
              card.url = normalizeMediaUrl(data.metadata.url)
              card.mediaType =
                typeof data.metadata?.type === 'string' ? data.metadata.type : card.kind
            }
          } else if (data.status === 'task_progress') {
            // Live media render progress: feed a moving bar on the running card.
            const message = historyStore.messages.find((m) => m.id === messageId)
            const card = message?.taskPlan?.cards.find((c) => c.nodeId === data.metadata?.node_id)
            if (card && card.state !== 'cancelled') {
              if (typeof data.metadata?.percent === 'number') {
                card.progressPercent = data.metadata.percent
              }
              if (typeof data.metadata?.provider_status === 'string') {
                card.providerStatus = data.metadata.provider_status
              }
              if (typeof data.metadata?.elapsed_seconds === 'number') {
                card.elapsedSeconds = data.metadata.elapsed_seconds
              }
            }
          } else if (
            data.status === 'data' &&
            historyStore.messages.find((m) => m.id === messageId)?.taskPlan?.active
          ) {
            // Multitask mode: no live single-bubble rendering (the task cards
            // are the streaming surface), but the assembled answer text — the
            // executor streams it once after the DAG finishes, and the reply
            // node has no card of its own (compose_reply is hidden) — must
            // still accumulate so the 'complete' flush renders it. Dropping it
            // left the bubble without any answer text until a reload re-fetched
            // the persisted message (#1057).
            if (data.chunk) {
              fullContent += data.chunk
            }
          } else if (
            (data.status === 'file' ||
              data.status === 'audio' ||
              data.status === 'tts_generating' ||
              data.status === 'links') &&
            historyStore.messages.find((m) => m.id === messageId)?.taskPlan?.active
          ) {
            // Multitask mode: the task cards are the live surface, so suppress
            // the normal single-bubble media events. They still flow so the
            // OUT message files persist; history renders the flattened bubble
            // on reload.
          } else if (data.status === 'data' && data.chunk) {
            if (processingStatus.value) {
              processingStatus.value = ''
              processingMetadata.value = {}
            }

            fullContent += data.chunk

            if (currentAudioStreamer) {
              let audioChunk = data.chunk.replace(/<think>[\s\S]*?<\/think>/gi, '')

              if (insideThinkBlock) {
                const closeMatch = audioChunk.match(/<\/think>/i)
                if (closeMatch && closeMatch.index !== undefined) {
                  audioChunk = audioChunk.substring(closeMatch.index + closeMatch[0].length)
                  insideThinkBlock = false
                } else {
                  audioChunk = ''
                }
              }

              if (!insideThinkBlock) {
                const openMatch = audioChunk.match(/<think>/i)
                if (openMatch && openMatch.index !== undefined) {
                  audioChunk = audioChunk.substring(0, openMatch.index)
                  insideThinkBlock = true
                }
              }

              audioText += audioChunk

              while (true) {
                const currentUnprocessed = audioText.slice(spokenLength)
                const boundaryMatch = currentUnprocessed.match(/([.?!]+)(\s+|$)|(\n+)/)

                if (!boundaryMatch || boundaryMatch.index === undefined) break

                const endIdx = boundaryMatch.index + boundaryMatch[0].length
                const sentence = currentUnprocessed.substring(0, endIdx)

                if (sentence.trim()) {
                  currentAudioStreamer.streamText(sentence, undefined, detectedLanguage)
                }

                spokenLength += endIdx
              }
            }

            // Mark dirty and schedule a throttled render via rAF.
            // This avoids re-parsing the full markdown on every single SSE chunk,
            // which causes O(n²) rendering for long responses.
            streamingDirty = true

            if (streamingRafId === null) {
              streamingRafId = requestAnimationFrame(() => {
                streamingRafId = null
                if (!streamingDirty) return
                streamingDirty = false
                renderStreamingContent(fullContent, messageId)
              })
            }
          } else if (data.status === 'reasoning' && data.chunk) {
            // Reasoning chunks from OpenAI o-series / GPT-5 models
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message) {
              // Find existing reasoning part or create new one
              let reasoningPart = message.parts.find((p) => p.type === 'thinking' && p.isStreaming)

              if (!reasoningPart) {
                // Create new reasoning part at the beginning
                reasoningPart = {
                  type: 'thinking',
                  content: '',
                  isStreaming: true,
                }
                message.parts.unshift(reasoningPart)
              }

              // Append reasoning content
              reasoningPart.content += data.chunk
            }
          } else if (data.status === 'file') {
            // Handle file attachments (images, videos, audio, etc.)
            //
            // Issue #625: the live MEDIAMAKER audio player used to go
            // missing whenever this push raced the streaming text
            // reconciler. {@link pushMediaPart} now assigns a stable
            // `partId` and re-assigns `message.parts` so we always
            // mutate the current reactive proxy (a stale closure
            // reference from a sibling handler would otherwise
            // silently drop the push).
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message && data.url) {
              const absoluteUrl = normalizeMediaUrl(data.url)
              if (data.type === 'image' || data.type === 'video' || data.type === 'audio') {
                pushMediaPart(message, data.type, absoluteUrl)
              }
            }
          } else if (data.status === 'tts_generating') {
            // TTS synthesis started — show loading animation in message
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message) {
              message.parts.push({ type: 'tts_loading' })
            }
          } else if (data.status === 'audio') {
            // Handle TTS audio response (voice reply)
            // Incognito: the backend ships the ephemeral file id so the
            // session-end cleanup can delete the synthesized audio.
            if (incognito && typeof data.file_id === 'number') {
              incognitoStore.registerFile(data.file_id)
            }
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message && data.url) {
              // Remove tts_loading part and replace with audio player
              const loadingIdx = message.parts.findIndex((p) => p.type === 'tts_loading')
              const isVoiceReply = loadingIdx !== -1
              if (isVoiceReply) {
                message.parts.splice(loadingIdx, 1)
              }
              const absoluteUrl = normalizeMediaUrl(data.url)
              // If we are already streaming audio (currentAudioStreamer exists), don't autoplay the file
              const shouldAutoplay = isVoiceReply && !currentAudioStreamer
              pushMediaPart(message, 'audio', absoluteUrl, { autoplay: shouldAutoplay })
            }
          } else if (data.status === 'links') {
            // Handle web search results
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message && data.links) {
              message.parts.push({
                type: 'links',
                items: data.links.map((l) => {
                  try {
                    return {
                      title: l.title || l.url,
                      url: l.url,
                      desc: l.description,
                      host: new URL(l.url).hostname,
                    }
                  } catch {
                    return {
                      title: l.title || l.url,
                      url: l.url,
                      desc: l.description,
                      host: l.url,
                    }
                  }
                }),
              })
            }
          } else if (data.status === 'memory_suggested') {
            // Handle memory suggestions from backend
            // Memory data is in metadata
            const memoryData = data.metadata

            if (!memoryData || !memoryData.id) {
              console.warn('⚠️ Invalid memory data received:', data)
              return
            }

            const toastMemory = {
              id: memoryData.id,
              category: memoryData.category ?? '',
              key: memoryData.key ?? '',
              value: memoryData.value ?? '',
              source: (memoryData.source ?? 'auto_detected') as UserMemory['source'],
              messageId: memoryData.messageId ?? null,
              created: memoryData.created ?? Date.now(),
              updated: memoryData.updated ?? Date.now(),
              toastId: memoryToastIdCounter++,
            } as UserMemory & { toastId: number }

            if (memoryData.action === 'delete') {
              openMemoryDeleteDialog(toastMemory)
              return
            }

            // Create toast for the suggested memory
            activeMemoryToasts.value.push(toastMemory)
          } else if (data.status === 'memories_loaded') {
            // Memories loaded event - store in memoriesStore for badge rendering
            const memories = data.metadata?.memories
            if (memories && Array.isArray(memories)) {
              // Collect memory IDs for the message
              const memoryIds: number[] = []

              // Update the memory store with loaded memories so badges can resolve
              for (const mem of memories) {
                memoryIds.push(mem.id)
                const existing = memoriesStore.memories.find((m) => m.id === mem.id)
                if (!existing) {
                  // Add to store for badge rendering
                  memoriesStore.memories.push({
                    id: mem.id,
                    category: mem.category || 'personal',
                    key: mem.key || '',
                    value: mem.value || '',
                    source: (mem.source || 'auto_detected') as UserMemory['source'],
                    messageId: mem.messageId ?? null,
                    created: mem.created || 0,
                    updated: mem.updated || 0,
                  })
                }
              }

              // Attach memory IDs to the current streaming message
              const streamingMessage = historyStore.messages.find((m) => m.id === messageId)
              if (streamingMessage && memoryIds.length > 0) {
                streamingMessage.memoryIds = memoryIds
              }
            }
          } else if (data.status === 'feedback_loaded') {
            // Feedback examples loaded - store in feedbackStore for badge rendering
            const feedbacks = data.metadata?.feedbacks
            if (feedbacks && Array.isArray(feedbacks)) {
              // Collect feedback IDs for the message
              const feedbackIds: number[] = []

              // Update the feedback store with loaded feedbacks so badges can resolve
              for (const fb of feedbacks) {
                feedbackIds.push(fb.id)
                const existing = feedbackStore.getFeedbackById(fb.id)
                if (!existing) {
                  // Add to store temporarily for badge rendering
                  feedbackStore.feedbacks.push({
                    id: fb.id,
                    type: fb.type as 'false_positive' | 'positive',
                    value: fb.value ?? '',
                    messageId: null,
                    created: 0,
                    updated: 0,
                  })
                }
              }

              // Attach feedback IDs to the current streaming message
              const streamingMessage = historyStore.messages.find((m) => m.id === messageId)
              if (streamingMessage && feedbackIds.length > 0) {
                streamingMessage.feedbackIds = feedbackIds
              }
            }
          } else if (data.status === 'memory_deleted') {
            // Legacy backend event - remove from local store only
            const memoryId = data.metadata?.id
            if (memoryId) {
              memoriesStore.memories = memoriesStore.memories.filter((m) => m.id !== memoryId)
            }
          } else if (data.status === 'perf') {
            // Phase 0 instrumentation — only log when the user opts in via
            // `localStorage.setItem('synaplanDebug', '1')`. Keeps prod consoles
            // quiet but lets us inspect every phase in dev / on demand.
            try {
              if (typeof window !== 'undefined' && window.localStorage?.getItem('synaplanDebug')) {
                console.groupCollapsed(
                  `[synaplan perf] ${data.total_ms ?? '?'} ms total — message ${messageId}`
                )
                console.table(data.phases ?? {})
                if (data.marks && Object.keys(data.marks).length > 0) {
                  console.table(data.marks)
                }
                console.groupEnd()
              }
            } catch {
              // localStorage can throw in private mode — don't let perf logging break the stream.
            }
          } else if (data.status === 'complete') {
            // Phase 3f (corrected): do the final render + state cleanup
            // synchronously so they all land in the same Vue render tick.
            //
            // The earlier rAF-wrapped variant deferred renderStreamingContent
            // by one frame, which let `historyStore.finishStreamingMessage`
            // (further below) flip `isStreaming = false` first. The DOM
            // commit triggered by that flip showed `data-testid="message-done"`
            // BEFORE message.parts had been filled with the final accumulated
            // text — Playwright's `waitForAnswer` then read a stale partial
            // bubble (e.g. "ollama" instead of "ollama stub response").
            //
            // Sync ordering avoids the race entirely: Vue batches the sync
            // mutations (parts updated, processingStatus cleared, isStreaming
            // toggled) into one paint, preserving the smooth single-frame
            // transition this phase was originally targeting.
            if (streamingRafId !== null) {
              cancelAnimationFrame(streamingRafId)
              streamingRafId = null
            }
            streamingDirty = false

            if (fullContent) {
              renderStreamingContent(fullContent, messageId)
            } else if (data.mediaJob || data.media_job) {
              renderStreamingContent(
                generatingTokenForMediaJob(data.mediaJob ?? data.media_job),
                messageId
              )
            }

            if (currentAudioStreamer) {
              const remaining = audioText.slice(spokenLength)
              if (remaining.trim()) {
                currentAudioStreamer.streamText(remaining, undefined, detectedLanguage)
              }
              currentAudioStreamer.markComplete()
            }

            processingStatus.value = ''
            processingMetadata.value = {}

            // Update message metadata
            const message = historyStore.messages.find((m) => m.id === messageId)
            if (message) {
              applyMediaJobToMessage(message, data.mediaJob ?? data.media_job)

              // Mark as truncated so the Continue button appears
              if (data.truncated) {
                message.truncated = true
              }
              // Clean up any leftover tts_loading indicator (TTS may have failed silently)
              message.parts = message.parts.filter((p) => p.type !== 'tts_loading')
              // ✨ NEW: Handle generated file from backend
              if (data.generatedFile) {
                // Incognito: the generated document is ephemeral — track it so
                // the session-end cleanup deletes it.
                if (incognito) {
                  incognitoStore.registerFile(data.generatedFile.id)
                }
                // Add file to message FIRST
                if (!message.files) {
                  message.files = []
                }

                const fileData: import('@/stores/history').MessageFile = {
                  id: data.generatedFile.id,
                  filename: data.generatedFile.filename,
                  filePath: data.generatedFile.path,
                  fileSize: data.generatedFile.size,
                  fileType: data.generatedFile.type,
                  fileMime: data.generatedFile.mime,
                }

                message.files.push(fileData)

                // Replace JSON content or special markers with translated message
                const hasJsonOrMarker =
                  message.parts.length === 0 ||
                  (message.parts[0].type === 'code' &&
                    message.parts[0].content?.includes('BFILEPATH')) ||
                  (message.parts[0].type === 'text' &&
                    message.parts[0].content?.includes('__FILE_GENERATED__'))

                if (hasJsonOrMarker) {
                  // Use translation with filename parameter
                  const translatedMessage = t('message.fileGenerated', {
                    filename: data.generatedFile.filename,
                  })
                  message.parts = [
                    {
                      type: 'text',
                      content: translatedMessage,
                    },
                  ]
                }

                // Force Vue reactivity with multiple strategies
                nextTick(() => {
                  // Strategy 1: Update the message object with a new id to force key-based re-render
                  const messageIndex = historyStore.messages.findIndex((m) => m.id === message.id)
                  if (messageIndex !== -1) {
                    // Create completely new message object
                    // FIXME: This entire block is cargo-cult reactivity code - message is already a store reference,
                    // Vue 3 Proxy detects mutations automatically. The ternary is unnecessary (files already mutated above),
                    // and spreading parts/files just wastes CPU creating shallow copies of already-mutated arrays.
                    const updatedMessage = {
                      ...message,
                      files: message.files ? [...message.files] : undefined,
                      parts: [...message.parts],
                      timestamp: new Date(message.timestamp),
                    }

                    // Replace in store
                    historyStore.messages.splice(messageIndex, 1, updatedMessage)
                  }
                })
              }

              // ✨ NEW: Parse JSON response if AI responded in JSON format
              // NOTE: againData is now generated by frontend in ChatMessage.vue
              // based on available models and message type (image/video/audio)

              if (data.messageId) {
                message.backendMessageId = data.messageId
              }

              // Store search results if provided
              if (
                data.searchResults &&
                Array.isArray(data.searchResults) &&
                data.searchResults.length > 0
              ) {
                message.searchResults = data.searchResults as NonNullable<Message['searchResults']>

                // Also set webSearch metadata for assistant message
                message.webSearch = {
                  query: data.searchResults[0]?.query || '',
                  resultsCount: data.searchResults.length,
                }
              }

              // Store memory IDs if provided (full memories loaded from store)
              if (data.memoryIds && Array.isArray(data.memoryIds) && data.memoryIds.length > 0) {
                message.memoryIds = data.memoryIds
              }

              // Store feedback IDs if provided
              if (
                data.feedbackIds &&
                Array.isArray(data.feedbackIds) &&
                data.feedbackIds.length > 0
              ) {
                message.feedbackIds = data.feedbackIds
              }

              // Provider/model + clickable footer (aiModels) — match persisted API shape
              applyAssistantChatModelFooter(
                message,
                {
                  provider: data.provider,
                  model: data.model,
                  model_id: data.model_id ?? null,
                  aiModels: data.aiModels ?? null,
                },
                { provider, model: modelLabel, model_id: currentModel?.id ?? null }
              )

              // Store topic from classification
              if (data.topic) {
                message.topic = data.topic
              }

              // Store original topic (preserved on error messages for correct "Again" model selection)
              if (data.originalTopic !== undefined) {
                message.originalTopic = data.originalTopic
              }
              if (data.originalMediaType !== undefined) {
                message.originalMediaType = data.originalMediaType
              }

              if (data.error_hint === 'vision_model_required') {
                const hint =
                  '\n\n---\n\n' +
                  '**💡 ' +
                  t('aiProvider.error.visionModelHint') +
                  '**\n\n' +
                  t('aiProvider.error.visionModelHintDetail')
                message.parts.push({ type: 'text', content: hint })
              }

              // Mark reasoning parts as complete (remove streaming flag)
              message.parts.forEach((part) => {
                if (part.type === 'thinking' && part.isStreaming) {
                  delete part.isStreaming
                }
              })
            } else {
              console.error('❌ Could not find message with id:', messageId)
            }

            // Incognito: nothing was persisted — no chat title, no sidebar
            // bump, no reconcile against a stored message, no memory-
            // extraction poll (extraction is skipped server-side).
            if (!incognito && chatId) {
              // Generate chat title from first message
              generateChatTitleFromFirstMessage(userMessage)

              // Bump chat activity so the sidebar reflects the assistant message
              // landing without waiting for a full reload.
              chatsStore.bumpChatActivity(chatId)
            }

            historyStore.finishStreamingMessage(messageId)

            // Issue #1070: the streamed state is only a live preview — the
            // persisted message is the single source of truth for files,
            // media and metadata. Re-fetch it once so anything the SSE
            // accumulation missed (e.g. TTS audio in a multitask turn,
            // where the `audio` event is suppressed while task cards
            // stream) renders without a page reload.
            if (data.messageId && !incognito) {
              void historyStore.reconcileMessage(messageId, data.messageId)
            }

            // Phase 2c: schedule a couple of polls for backgrounded memory
            // extraction results. The worker writes to the source message
            // metadata; we pick it up here and surface via the same memory
            // store dispatch the legacy SSE events used.
            if (data.messageId && !incognito) {
              schedulePostStreamMemoryPoll(data.messageId)
            }

            // Clean up streaming resources after successful completion
            streamingAbortController = null
            stopStreamingFn = null
            currentTrackId = undefined
            currentStreamingChatId = undefined
          } else if (data.status === 'error') {
            // Cancel any pending throttled render
            if (streamingRafId !== null) {
              cancelAnimationFrame(streamingRafId)
              streamingRafId = null
            }
            streamingDirty = false

            const errorMsg = String(data.error ?? data.message ?? 'Unknown error')
            console.error('Error:', errorMsg, data)
            processingStatus.value = ''
            processingMetadata.value = {}

            // Update message metadata from error event so status/provider/topic
            // are visible in real-time without requiring a page refresh
            {
              const message = historyStore.messages.find((m) => m.id === messageId)
              if (message) {
                if (data.messageId) {
                  message.backendMessageId = data.messageId
                }
                if (data.topic) {
                  message.topic = data.topic
                }
                if (data.originalTopic !== undefined) {
                  message.originalTopic = data.originalTopic
                }
                if (data.originalMediaType !== undefined) {
                  message.originalMediaType = data.originalMediaType
                }
                applyAssistantChatModelFooter(
                  message,
                  {
                    provider: data.provider,
                    model: data.model,
                    model_id: data.model_id ?? null,
                    aiModels: data.aiModels ?? null,
                  },
                  { provider, model: modelLabel, model_id: currentModel?.id ?? null }
                )
              }
            }

            // Handle chat not found errors with toast notification
            if (
              errorMsg.toLowerCase().includes('chat not found') ||
              errorMsg.toLowerCase().includes('access denied')
            ) {
              // Remove the empty assistant message
              historyStore.removeMessage(messageId)

              // Show toast notification
              showErrorToast(t('chat.notFound'), 5000)

              // Clean up streaming resources
              streamingAbortController = null
              stopStreamingFn = null
              currentTrackId = undefined
              currentStreamingChatId = undefined
              return
            }

            // Handle rate limit errors with modal
            if (errorMsg.toLowerCase().includes('rate limit')) {
              // Remove the empty assistant message
              historyStore.removeMessage(messageId)

              // Find and mark the previous user message as rate_limited (don't delete it!)
              const userMessages = historyStore.messages.filter((m) => m.role === 'user')
              const lastUserMessage = userMessages[userMessages.length - 1]
              if (lastUserMessage) {
                historyStore.setMessageStatus(lastUserMessage.id, 'rate_limited', 'rate_limit', {
                  limitType:
                    data.limit_type === 'hourly' ||
                    data.limit_type === 'monthly' ||
                    data.limit_type === 'lifetime'
                      ? data.limit_type
                      : 'lifetime',
                  actionType: data.action_type || 'MESSAGES',
                  used: data.used || 0,
                  limit: data.limit || 0,
                  remaining: data.remaining || 0,
                  resetAt: data.reset_at || null,
                  userLevel: data.user_level || authStore.user?.level || 'NEW',
                })
              }

              checkAndShowLimit({
                allowed: false,
                limitType:
                  data.limit_type === 'hourly' ||
                  data.limit_type === 'monthly' ||
                  data.limit_type === 'lifetime'
                    ? data.limit_type
                    : 'lifetime',
                actionType: data.action_type || 'MESSAGES',
                used: data.used || 0,
                limit: data.limit || 0,
                remaining: data.remaining || 0,
                resetTime: data.reset_at || null,
                userLevel: data.user_level || authStore.user?.level || 'NEW',
                phoneVerified: data.phone_verified || false,
              })

              // Clean up streaming resources
              streamingAbortController = null
              stopStreamingFn = null
              currentTrackId = undefined
              currentStreamingChatId = undefined
              return
            }

            // Handle monthly cost-budget exceeded → offer a one-time top-up.
            // Branch on the stable machine-readable `code` (set by the backend),
            // not on the human-readable error text which changes with i18n /
            // rewording. Keep the structured-field check as a defensive fallback.
            if (
              data.code === 'COST_BUDGET_EXCEEDED' ||
              (data.limit_type === 'monthly' && data.topup_available === true)
            ) {
              historyStore.removeMessage(messageId)

              checkAndShowLimit({
                allowed: false,
                limitType: 'monthly',
                actionType: data.action_type || 'MESSAGES',
                used: Number(data.used) || 0,
                limit: Number(data.limit) || 0,
                remaining: Number(data.remaining) || 0,
                resetTime: null,
                userLevel: data.user_level || authStore.user?.level || 'NEW',
                phoneVerified: data.phone_verified || false,
                topupAvailable: data.topup_available === true,
              })

              streamingAbortController = null
              stopStreamingFn = null
              currentTrackId = undefined
              currentStreamingChatId = undefined
              return
            }

            // Format user-friendly error message with installation instructions
            let displayError = '## ⚠️ ' + errorMsg + '\n\n'

            if (data.install_command && data.suggested_models) {
              displayError += '### 📦 ' + t('aiProvider.error.noModelTitle') + '\n\n'

              if (data.suggested_models.quick) {
                displayError += '**' + t('aiProvider.error.quickModels') + ':**\n'
                data.suggested_models.quick.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }

              if (data.suggested_models.medium) {
                displayError += '**' + t('aiProvider.error.mediumModels') + ':**\n'
                data.suggested_models.medium.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }

              if (data.suggested_models.large) {
                displayError += '**' + t('aiProvider.error.largeModels') + ':**\n'
                data.suggested_models.large.forEach((model: string) => {
                  displayError += `- \`${model}\`\n`
                })
                displayError += '\n'
              }

              displayError += '### 💡 ' + t('aiProvider.error.exampleCommand') + '\n\n'
              displayError += '```bash\n' + data.install_command + '\n```\n\n'
              displayError += '*' + t('aiProvider.error.restartNote') + '*'
            }

            // Show error in the streaming message bubble
            const message = historyStore.messages.find((m) => m.id === messageId)
            const hasContent = message?.parts.some(
              (p) => p.type === 'text' && p.content && p.content.trim() !== ''
            )
            if (message && hasContent) {
              // Already has meaningful content (partial response streamed before error)
              historyStore.finishStreamingMessage(messageId)
            } else {
              // No visible content yet — display the error message
              historyStore.updateStreamingMessage(messageId, displayError)
              historyStore.finishStreamingMessage(messageId)
            }

            // Clean up streaming resources after error
            streamingAbortController = null
            stopStreamingFn = null
            currentTrackId = undefined
            currentStreamingChatId = undefined
          } else {
            console.warn('⚠️ Unknown status:', data.status, data)
          }
        },
      })

      // Store EventSource cleanup function globally
      stopStreamingFn = stopStreaming

      // Store cleanup function
      streamingAbortController.signal.addEventListener('abort', () => {
        stopStreaming()
        stopStreamingFn = null
      })
    }
  } catch (error) {
    console.error('❌ Streaming error:', error)

    // Cancel any pending throttled render
    if (streamingRafId !== null) {
      cancelAnimationFrame(streamingRafId)
      streamingRafId = null
    }
    streamingDirty = false

    historyStore.updateStreamingMessage(messageId, 'Sorry, an error occurred.')
    historyStore.finishStreamingMessage(messageId)
    streamingAbortController = null
    stopStreamingFn = null
    currentTrackId = undefined
    currentStreamingChatId = undefined
  }
  // NOTE: Don't clean up in finally block! The streaming is async and still running.
  // Cleanup happens in the 'complete' event handler or in handleUserStop()
}

// Explicit Stop button (issue #1225): the user WANTS to cancel the turn. Tell
// the backend to stop (/stop-stream flags the turn via CancellationStore) and
// persist the partial answer as cancelled (/save-cancelled). This is distinct
// from navigating away, which detaches WITHOUT cancelling (handleNavigateAway).
const handleUserStop = async () => {
  // CRITICAL: Abort signal FIRST to prevent any further chunk processing
  if (streamingAbortController) {
    streamingAbortController.abort()
  }

  // Close the EventSource connection IMMEDIATELY
  if (stopStreamingFn) {
    stopStreamingFn()
    stopStreamingFn = null
  }

  // Resolve the turn id reliably (issues #1141/#1142): the module-level
  // currentTrackId can be cleared by a racing complete/error handler. When
  // that happened on navigate-away/reload the backend was never told to stop
  // AND the partial answer was never persisted (the save below was skipped),
  // so the whole response — including already-generated media — was lost. Fall
  // back to the plan-scoped id captured when the turn started.
  const streamingMessageForTrack = historyStore.messages.find((m) => m.isStreaming)
  const effectiveTrackId = streamingMessageForTrack?.taskPlan?.trackId ?? currentTrackId

  // Notify backend to stop streaming.
  // Since detach-on-navigation (#1225) the backend ignores a bare disconnect,
  // so closing the EventSource alone no longer cancels the turn — every
  // explicit Stop must flag the turn via a cancel endpoint.
  // Guests have no auth session: the auth-guarded /stop-stream endpoint would
  // return 401 and the http client would force a "session expired" redirect to
  // /login (issue #1037). Guests use the public guest endpoint instead, which
  // authorizes the cancel with the server-issued guest session id.
  if (isGuestMode.value) {
    if (effectiveTrackId && guestStore.sessionId) {
      try {
        await chatApi.stopGuestStream(guestStore.sessionId, effectiveTrackId)
      } catch (error) {
        console.error('❌ Failed to notify backend (guest):', error)
      }
    } else {
      console.warn('⚠️ No trackId or guest session - skipping backend notification')
    }
  } else if (effectiveTrackId) {
    try {
      await chatApi.stopStream(effectiveTrackId)
    } catch (error) {
      console.error('❌ Failed to notify backend:', error)
    }
  } else {
    console.warn('⚠️ No trackId - skipping backend notification')
  }

  // Stop streaming audio
  if (currentAudioStreamer) {
    currentAudioStreamer.stop()
    currentAudioStreamer = null
  }
  isAudioStreaming.value = false

  // Clear processing status
  processingStatus.value = ''
  processingMetadata.value = {}

  // Finish any streaming message and add cancellation notice
  const streamingMessage = historyStore.messages.find((m) => m.isStreaming)
  if (streamingMessage) {
    // Remove any TTS loading indicators (voice reply was in progress when cancelled)
    streamingMessage.parts = streamingMessage.parts.filter((p) => p.type !== 'tts_loading')

    const cancelMessage = t('message.cancelledByUser')

    // Collect the current content for saving to backend
    let finalContent: string

    // Add cancellation message if there's no content yet
    if (
      streamingMessage.parts.length === 0 ||
      (streamingMessage.parts.length === 1 && streamingMessage.parts[0].content === '')
    ) {
      historyStore.updateStreamingMessage(streamingMessage.id, cancelMessage)
      finalContent = cancelMessage
    } else {
      // Collect existing text content
      finalContent = streamingMessage.parts
        .filter((p) => p.type === 'text')
        .map((p) => p.content || '')
        .join('\n\n')

      // Append cancellation notice to existing content
      const lastPart = streamingMessage.parts[streamingMessage.parts.length - 1]
      if (lastPart && lastPart.type === 'text') {
        lastPart.content += `\n\n${cancelMessage}`
      } else {
        streamingMessage.parts.push({
          type: 'text',
          content: `\n\n${cancelMessage}`,
        })
      }

      finalContent += `\n\n${cancelMessage}`
    }

    historyStore.finishStreamingMessage(streamingMessage.id)

    // Save the cancelled message to backend so it persists after refresh
    // CRITICAL: Use the ORIGINAL chatId where stream was started, NOT the current active chat!
    // Use the #1141/#1142-hardened track id so the save still fires when
    // currentTrackId was cleared mid-turn (navigate-away / reload).
    const trackIdToSave = effectiveTrackId
    const chatIdToSave = currentStreamingChatId

    if (isGuestMode.value) {
      // Guests can't persist messages via the auth-guarded /save-cancelled
      // endpoint (issue #1037). The cancellation notice is already shown
      // locally above, so we simply skip the backend save.
    } else if (incognitoStore.active) {
      // Incognito: nothing may be persisted — the partial answer stays only
      // in the in-memory transcript.
    } else if (trackIdToSave && chatIdToSave) {
      // Save and update message with backend ID, pass current metadata
      const metadata = {
        provider: streamingMessage.provider,
        model: streamingMessage.modelLabel,
        topic: streamingMessage.topic,
      }
      saveCancelledMessageToBackend(
        trackIdToSave,
        chatIdToSave,
        finalContent,
        streamingMessage.id,
        metadata
      ).catch((error) => console.error('❌ Failed to save cancelled message to backend:', error))
    } else {
      console.warn('⚠️ Cannot save cancelled message - missing trackId or chatId', {
        trackIdToSave,
        chatIdToSave,
      })
    }
  }

  // Clear references AFTER saving
  streamingAbortController = null
  currentTrackId = undefined
  currentStreamingChatId = undefined
}

// Helper function to save cancelled message to backend
async function saveCancelledMessageToBackend(
  trackId: number,
  chatId: number,
  content: string,
  messageId: string,
  metadata?: { provider?: string; model?: string; topic?: string }
) {
  try {
    const data = await httpClient('/api/v1/messages/save-cancelled', {
      method: 'POST',
      body: JSON.stringify({
        trackId,
        chatId,
        content,
        provider: metadata?.provider,
        model: metadata?.model,
        topic: metadata?.topic,
      }),
      schema: SaveCancelledMessageResponseSchema,
    })

    // Update the message with backend message ID and metadata so the footer buttons appear
    const message = historyStore.messages.find((m) => m.id === messageId)
    if (message && data.messageId) {
      message.backendMessageId = data.messageId

      // Update metadata if provided by backend
      if (data.topic) {
        message.topic = data.topic
      }
      if (data.provider) {
        message.provider = data.provider
      }
      if (data.model) {
        message.modelLabel = data.model
      }

      // Set aiModels object for proper display of model badges
      if (data.provider && data.model) {
        message.aiModels = {
          chat: {
            provider: data.provider,
            model: data.model,
            model_id: null, // We don't have the model_id from cancelled message
          },
        }
      }
    }
  } catch (error) {
    console.error('❌ Error saving cancelled message:', error)
  }
}

function findPrecedingUserMessage(messages: Message[], fromIndex: number): Message | null {
  for (let i = fromIndex - 1; i >= 0; i--) {
    if (messages[i].role === 'user') return messages[i]
  }
  return null
}

function extractUserText(message: Message): string {
  return message.parts
    .filter((p) => p.type === 'text')
    .map((p) => p.content || '')
    .join('\n')
}

const handleAgain = async (backendMessageId: number, modelId?: number) => {
  if (!authStore.isAuthenticated) {
    console.error('❌ Not authenticated - redirecting to login')
    const { error } = useNotification()
    error(t('auth.sessionExpired'))
    await router.push({ name: 'login', query: { reason: 'session_expired' } })
    return
  }

  const assistantMessage = historyStore.messages.find(
    (m) => m.backendMessageId === backendMessageId && m.role === 'assistant'
  )

  if (!assistantMessage) {
    console.error('❌ Could not find assistant message with backendMessageId:', backendMessageId)
    return
  }

  const messageIndex = historyStore.messages.indexOf(assistantMessage)

  // Search backwards for the nearest user message
  const userMessage = findPrecedingUserMessage(historyStore.messages, messageIndex)

  if (!userMessage) {
    console.error('❌ Could not find user message before assistant message')
    return
  }

  const userText = extractUserText(userMessage)

  if (!userText) {
    console.error('❌ No text found in user message')
    return
  }

  // Stop any active audio playback before retrying
  if (currentAudioStreamer) {
    currentAudioStreamer.stop()
    currentAudioStreamer = null
  }
  isAudioStreaming.value = false

  historyStore.markSuperseded(assistantMessage.id)

  // Stream new response directly without creating a duplicate user message.
  //
  // With a model pick: `isAgain` skips classification and routes straight to
  // the picked model (single-prompt "Again with…").
  //
  // Without a model pick (multitask "Again"): stream WITHOUT `isAgain` so the
  // backend re-classifies (`source: ai_sorting`) and the planner can build a
  // fresh DAG — `isAgain` without a model would silently degrade the turn to
  // the single-node legacy path.
  await streamAIResponse(userText, modelId ? { modelId, isAgain: true } : {})
}

/**
 * Retry one failed task-plan step with another model. Streams the step's
 * resolved prompt through the Again path (`isAgain` + modelId): the backend
 * maps the model tag to the matching media topic (e.g. TEXT2PIC → tools:pic),
 * so only that sub-task re-runs. The result arrives as a new assistant bubble;
 * the original turn (with its successful parts) is left untouched.
 */
const handleTaskRetry = async (payload: { prompt: string; modelId: number }) => {
  if (!authStore.isAuthenticated || isGuestMode.value) return
  if (!payload.prompt || !payload.modelId) return

  // Stop any active audio playback before re-running the step
  if (currentAudioStreamer) {
    currentAudioStreamer.stop()
    currentAudioStreamer = null
  }
  isAudioStreaming.value = false

  await streamAIResponse(payload.prompt, { modelId: payload.modelId, isAgain: true })
}

// Per-card Stop: cancel one running media step without ending the whole turn.
// Mark the card cancelled immediately (the user's intent is the source of truth)
// and signal the backend so the provider poll aborts and stops billing.
const handleTaskCancel = async (nodeId: string) => {
  // Resolve the CURRENT turn's task-plan message via the streaming flag, mirroring
  // finishStreamingTurnLocally(). Node ids repeat across turns ("n1", "n2", …) and
  // taskPlan.active is only cleared on local teardown (not on a normal/error
  // completion), so finding the FIRST active plan can match a stale earlier turn and
  // cancel the wrong card / send the wrong trackId. The streaming message is the
  // unambiguous active turn; fall back to the active-plan lookup only if none is
  // currently streaming.
  const message =
    historyStore.messages.find((m) => m.isStreaming && m.taskPlan?.active) ??
    historyStore.messages.find((m) => m.taskPlan?.active)
  const card = message?.taskPlan?.cards.find((c) => c.nodeId === nodeId)
  if (card) {
    card.state = 'cancelled'
  }

  // Resolve the turn id reliably: prefer the plan-scoped id captured when the
  // turn started, falling back to the module-level currentTrackId. Relying on
  // currentTrackId alone was the root cause of issue #1141 — it can be undefined
  // (cleared by a racing complete/error handler) so the backend never received
  // the cancel and the stream stayed open, blocking the input.
  const trackId = message?.taskPlan?.trackId ?? currentTrackId
  if (trackId !== undefined) {
    try {
      await chatApi.cancelTask(trackId, nodeId)
    } catch {
      // Best-effort: the card already reflects the cancellation locally.
    }
  } else {
    console.warn('⚠️ No trackId for per-card cancel - skipping backend notification')
  }

  // If this was the last still-running step, end the turn locally so the input
  // unblocks immediately (issue #1141): a single-node media turn would otherwise
  // wait for a backend `complete` that may be delayed by the provider poll. We
  // close the EventSource and finish the streaming message WITHOUT the global
  // "cancelled by user" notice, since the cancelled card already conveys it.
  const remaining = message?.taskPlan?.cards.filter(
    (c) => c.state === 'pending' || c.state === 'running'
  )
  if (!remaining || remaining.length === 0) {
    finishStreamingTurnLocally()
  }
}

// Tear down the active stream and unblock the chat input without injecting the
// global stop's "cancelled by user" text. Used when a per-card Stop cancels the
// only running step (issue #1141).
function finishStreamingTurnLocally() {
  if (streamingAbortController) {
    streamingAbortController.abort()
  }
  if (stopStreamingFn) {
    stopStreamingFn()
    stopStreamingFn = null
  }
  if (currentAudioStreamer) {
    currentAudioStreamer.stop()
    currentAudioStreamer = null
  }
  isAudioStreaming.value = false
  processingStatus.value = ''
  processingMetadata.value = {}

  const streamingMessage = historyStore.messages.find((m) => m.isStreaming)
  if (streamingMessage) {
    if (streamingMessage.taskPlan) {
      streamingMessage.taskPlan.active = false
    }
    historyStore.finishStreamingMessage(streamingMessage.id)
  }

  streamingAbortController = null
  currentTrackId = undefined
  currentStreamingChatId = undefined
}

const handleRegenerate = async (message: Message, modelOption: ModelOption) => {
  const messageIndex = historyStore.messages.findIndex((m) => m.id === message.id)
  if (messageIndex <= 0) return

  // Search backwards for the nearest user message
  const previousMessage = findPrecedingUserMessage(historyStore.messages, messageIndex)
  if (!previousMessage) return

  const content = extractUserText(previousMessage)
  if (!content) return

  // Stop any active audio playback before regenerating
  if (currentAudioStreamer) {
    currentAudioStreamer.stop()
    currentAudioStreamer = null
  }
  isAudioStreaming.value = false

  historyStore.markSuperseded(message.id)

  // Stream new response directly without creating a duplicate user message
  await streamAIResponse(content, { modelId: modelOption.id, isAgain: true })
}

// Handle retry for rate-limited messages
const handleRetryMessage = async (message: Message, content: string) => {
  // Clear the error status on the message
  historyStore.clearMessageError(message.id)

  // Stream the AI response (don't add new user message, it already exists)
  await streamAIResponse(content)
}

// Memory toast handlers
async function handleMemoryEdit(memory: UserMemory & { toastId: number }) {
  // Close the toast first
  const index = activeMemoryToasts.value.findIndex((m) => m.toastId === memory.toastId)
  if (index !== -1) {
    activeMemoryToasts.value.splice(index, 1)
  }

  // Load categories if needed
  if (availableMemoryCategories.value.length === 0) {
    try {
      const categories = await getCategories()
      availableMemoryCategories.value = categories.map((c) => c.category)
    } catch {
      // Continue without categories
    }
  }

  // Open the edit dialog in-place (stay in chat!)
  editingMemory.value = memory
  isMemoryEditDialogOpen.value = true
}

function closeMemoryEditDialog() {
  isMemoryEditDialogOpen.value = false
  editingMemory.value = null
}

async function handleMemoryEditSave(memoryData: {
  category?: string
  key?: string
  value: string
}) {
  if (!editingMemory.value) return

  try {
    await memoriesStore.editMemory(
      editingMemory.value.id,
      {
        value: memoryData.value,
        category: memoryData.category,
        key: memoryData.key,
      },
      { silent: false }
    )
    closeMemoryEditDialog()
  } catch {
    // Store shows error notification
  }
}

// Memory badge click handler - opens MemoriesDialog with highlighted memory
function handleClickMemory(memory: UserMemory) {
  highlightedMemoryId.value = memory.id
  isMemoriesDialogOpen.value = true
}

function closeMemoriesDialog() {
  isMemoriesDialogOpen.value = false
  highlightedMemoryId.value = null
}

function handleMemoryDiscard(memory: UserMemory & { toastId: number }) {
  // Close the toast and open delete confirmation dialog
  const index = activeMemoryToasts.value.findIndex((m) => m.toastId === memory.toastId)
  if (index !== -1) {
    activeMemoryToasts.value.splice(index, 1)
  }
  openMemoryDeleteDialog(memory)
}

function handleMemoryToastClose(toastId: number) {
  const index = activeMemoryToasts.value.findIndex((m) => m.toastId === toastId)
  if (index !== -1) {
    activeMemoryToasts.value.splice(index, 1)
  }
}

function openFalsePositiveModal(text: string, messageId?: number) {
  const segments = text
    .split(/\n{2,}/)
    .map((segment) => segment.trim())
    .filter(Boolean)

  falsePositiveFullText.value = text.trim()
  falsePositiveSegments.value = segments.length > 0 ? segments : [text.trim()]
  falsePositiveMessageId.value = messageId ?? null

  // Find the previous user message for context
  // Look for the user message that came before this assistant message
  let userMessageText = ''
  if (messageId) {
    const messages = historyStore.messages
    const assistantMsgIndex = messages.findIndex((m) => m.backendMessageId === messageId)
    if (assistantMsgIndex > 0) {
      // Search backwards for the first user message
      for (let i = assistantMsgIndex - 1; i >= 0; i--) {
        const msg = messages[i]
        if (msg.role === 'user') {
          userMessageText = msg.parts
            .filter((p) => p.type === 'text' && p.content)
            .map((p) => (p.content ?? '').trim())
            .filter(Boolean)
            .join('\n')
          break
        }
      }
    }
  }
  falsePositiveUserMessage.value = userMessageText

  falsePositiveStep.value = 'select'
  falsePositiveSummaryOptions.value = []
  falsePositiveCorrectionOptions.value = []
  falsePositiveClassification.value = 'feedback'
  falsePositiveRelatedMemoryIds.value = []
  falsePositiveModalOpen.value = true
}

function closeFalsePositiveModal() {
  falsePositiveModalOpen.value = false
  falsePositiveSegments.value = []
  falsePositiveFullText.value = ''
  falsePositiveMessageId.value = null
  falsePositiveUserMessage.value = ''
  falsePositiveStep.value = 'select'
  falsePositiveSummaryOptions.value = []
  falsePositiveCorrectionOptions.value = []
  falsePositiveClassification.value = 'feedback'
  falsePositiveRelatedMemoryIds.value = []
}

async function previewFalsePositiveFeedback(text: string) {
  if (!text.trim()) {
    return
  }

  falsePositivePreviewLoading.value = true
  try {
    const preview = await previewFalsePositive({
      text,
      userMessage: falsePositiveUserMessage.value || undefined,
    })
    falsePositiveClassification.value = preview.classification
    falsePositiveSummaryOptions.value = preview.summaryOptions
    falsePositiveCorrectionOptions.value = preview.correctionOptions
    falsePositiveRelatedMemoryIds.value = preview.relatedMemoryIds ?? []
    falsePositiveStep.value = 'confirm'
  } catch (err) {
    const errorMsg = err instanceof Error ? err.message : t('feedback.falsePositive.error')
    showErrorToast(errorMsg)
  } finally {
    falsePositivePreviewLoading.value = false
  }
}

function backToFalsePositiveSelection() {
  falsePositiveStep.value = 'select'
}

/**
 * Save both false positive and correction in a single operation.
 * Checks for contradictions first; if any, opens ContradictionModal.
 */
async function saveFalsePositiveFeedback(data: { summary: string; correction: string }) {
  const { summary, correction } = data

  if (!summary.trim() && !correction.trim()) {
    return
  }

  falsePositiveSubmitting.value = true
  try {
    // Check both summary and correction for contradictions in a single batch call
    const result = await checkContradictionsBatch({
      summary: summary.trim(),
      correction: correction.trim(),
    })

    if (result.hasContradictions && result.contradictions.length > 0) {
      // Store pending data (including classification + related memory IDs) for after contradiction resolution
      pendingSaveData.value = {
        summary: summary.trim(),
        correction: correction.trim(),
        classification: falsePositiveClassification.value,
        relatedMemoryIds: falsePositiveRelatedMemoryIds.value,
      }
      contradictionList.value = result.contradictions
      contradictionNewSummary.value = summary.trim()
      contradictionNewCorrection.value = correction.trim()
      // Close FP modal first, then open contradiction modal
      closeFalsePositiveModal()
      falsePositiveSubmitting.value = false
      contradictionModalOpen.value = true
      return
    }

    await doSaveFeedback(
      { summary: summary.trim(), correction: correction.trim() },
      undefined,
      falsePositiveRelatedMemoryIds.value
    )
    if (falsePositiveClassification.value === 'memory') {
      showSuccessToast(
        t(
          correction.trim()
            ? 'feedback.falsePositive.memoryUpdated'
            : 'feedback.falsePositive.memoryDeleted'
        )
      )
    } else {
      showSuccessToast(t('feedback.falsePositive.success'))
    }
    closeFalsePositiveModal()
  } catch (err) {
    const errorMsg = err instanceof Error ? err.message : t('feedback.falsePositive.error')
    showErrorToast(errorMsg)
  } finally {
    falsePositiveSubmitting.value = false
  }
}

/**
 * Execute the actual save. Branches based on classification:
 * - "memory": Update/delete memories using contradiction IDs or related memory IDs (never save as feedback)
 * - "feedback": Save as false positive + positive feedback (original behavior)
 *
 * relatedMemoryIds: IDs from the preview step's vector search — used as fallback when
 * the contradiction check didn't find the relevant memory (e.g., different phrasing).
 */
async function doSaveFeedback(
  data: { summary: string; correction: string },
  itemsToDelete?: Contradiction[],
  relatedMemoryIds?: number[]
) {
  const isMemory = falsePositiveClassification.value === 'memory'

  if (isMemory) {
    // Memory flow: update, delete, or create memories (never save as feedback)
    const memoryContradictions = (itemsToDelete ?? []).filter((c) => c.type === 'memory')
    const hasCorrection = data.correction.trim().length > 0

    // Build the list of target memory IDs: contradiction IDs take priority, then related IDs from preview
    const targetIds: number[] =
      memoryContradictions.length > 0
        ? memoryContradictions.map((c) => c.id)
        : [...(relatedMemoryIds ?? [])]

    if (targetIds.length > 0 && hasCorrection) {
      // User provided a correction → update the first target memory, delete the rest
      await memoriesStore.editMemory(targetIds[0], { value: data.correction }, { silent: true })
      // Delete remaining target memories via raw API (avoids loading/error churn + fetchCategories per item)
      const deletedIds: number[] = []
      for (let i = 1; i < targetIds.length; i++) {
        try {
          await deleteMemoryApi(targetIds[i])
          deletedIds.push(targetIds[i])
        } catch {
          // Best effort
        }
      }
      if (deletedIds.length > 0) {
        const idSet = new Set(deletedIds)
        memoriesStore.memories = memoriesStore.memories.filter((m) => !idSet.has(m.id))
      }
    } else if (targetIds.length > 0 && !hasCorrection) {
      // No correction provided → delete all target memories via raw API
      const deletedIds: number[] = []
      for (const id of targetIds) {
        try {
          await deleteMemoryApi(id)
          deletedIds.push(id)
        } catch {
          // Best effort
        }
      }
      if (deletedIds.length > 0) {
        const idSet = new Set(deletedIds)
        memoriesStore.memories = memoriesStore.memories.filter((m) => !idSet.has(m.id))
      }
    } else if (hasCorrection) {
      // No target memories found at all but user gave a correction → create new memory
      await memoriesStore.addMemory(
        { value: data.correction, category: 'user_correction', key: 'correction' },
        { silent: true }
      )
    }
    // If no target memories AND no correction → nothing to do (user just acknowledged the error)

    // Delete non-memory contradictions (feedback entries) via raw API
    const feedbackContradictions = (itemsToDelete ?? []).filter((c) => c.type !== 'memory')
    if (feedbackContradictions.length > 0) {
      const deletedFbIds: number[] = []
      for (const c of feedbackContradictions) {
        try {
          await deleteFeedbackApi(c.id)
          deletedFbIds.push(c.id)
        } catch {
          // Best effort
        }
      }
      if (deletedFbIds.length > 0) {
        const idSet = new Set(deletedFbIds)
        feedbackStore.feedbacks = feedbackStore.feedbacks.filter((f) => !idSet.has(f.id))
      }
    }
  } else {
    // Feedback flow: original behavior
    const promises: Promise<void>[] = []
    if (data.summary) {
      promises.push(
        submitFalsePositive({
          summary: data.summary,
          messageId: falsePositiveMessageId.value ?? undefined,
        })
      )
    }
    if (data.correction) {
      promises.push(
        submitPositiveFeedback({
          text: data.correction,
          messageId: falsePositiveMessageId.value ?? undefined,
        })
      )
    }
    await Promise.all(promises)

    // Delete contradicted items for feedback flow
    if (itemsToDelete && itemsToDelete.length > 0) {
      await deleteContradictedItems(itemsToDelete)
    }
  }
}

/**
 * Delete contradicted items (memories or feedback).
 * Uses raw API calls to avoid store loading/error state churn per item,
 * then syncs the store state in one batch at the end.
 */
async function deleteContradictedItems(contradictions: Contradiction[]) {
  const deletedMemoryIds: number[] = []
  const deletedFeedbackIds: number[] = []

  // Phase 1: Delete via API (best effort per item)
  for (const c of contradictions) {
    try {
      if (c.type === 'memory') {
        await deleteMemoryApi(c.id)
        deletedMemoryIds.push(c.id)
      } else {
        await deleteFeedbackApi(c.id)
        deletedFeedbackIds.push(c.id)
      }
    } catch {
      // Best effort — continue with remaining items
    }
  }

  // Phase 2: Sync store state in one batch (no loading flicker)
  if (deletedMemoryIds.length > 0) {
    const idSet = new Set(deletedMemoryIds)
    memoriesStore.memories = memoriesStore.memories.filter((m) => !idSet.has(m.id))
  }
  if (deletedFeedbackIds.length > 0) {
    const idSet = new Set(deletedFeedbackIds)
    feedbackStore.feedbacks = feedbackStore.feedbacks.filter((f) => !idSet.has(f.id))
  }
}

function closeContradictionModal() {
  contradictionModalOpen.value = false
  contradictionList.value = []
  contradictionNewSummary.value = ''
  contradictionNewCorrection.value = ''
  pendingSaveData.value = null
  falsePositiveSubmitting.value = false
}

async function handleContradictionResolve(data: {
  action: 'save' | 'cancel'
  itemsToDelete: Contradiction[]
}) {
  const pending = pendingSaveData.value
  if (!pending) {
    closeContradictionModal()
    return
  }

  if (data.action === 'cancel') {
    closeContradictionModal()
    return
  }

  falsePositiveSubmitting.value = true
  // Restore classification from pending data (FP modal may have been closed, resetting the ref)
  falsePositiveClassification.value = pending.classification
  try {
    // Pass items to delete + related memory IDs into doSaveFeedback so it can handle memory updates vs deletions
    await doSaveFeedback(pending, data.itemsToDelete, pending.relatedMemoryIds)
    if (pending.classification === 'memory') {
      showSuccessToast(
        t(
          pending.correction.trim()
            ? 'feedback.falsePositive.memoryUpdated'
            : 'feedback.falsePositive.memoryDeleted'
        )
      )
    } else {
      showSuccessToast(t('feedback.falsePositive.success'))
    }
    closeContradictionModal()
  } catch (err) {
    const errorMsg = err instanceof Error ? err.message : t('feedback.falsePositive.error')
    showErrorToast(errorMsg)
  } finally {
    falsePositiveSubmitting.value = false
  }
}

/**
 * Handle "Regenerate" from False Positive Modal
 * Regenerates the CORRECTION (bottom field) based on the summary (top field)
 * Uses dedicated backend endpoint - no prompts from frontend
 * Everything stays in the modal - nothing is sent to chat
 */
async function regenerateFalsePositiveSummary(data: { summary: string; correction: string }) {
  if (!data.summary.trim()) {
    showErrorToast(t('feedback.falsePositive.needSummaryFirst'))
    return
  }

  falsePositivePreviewLoading.value = true
  try {
    // Call dedicated backend endpoint - prompt is handled server-side
    const result = await regenerateCorrection({
      falseClaim: data.summary.trim(),
      oldCorrection: data.correction.trim() || undefined,
    })

    // Add the new correction as a new option at the top
    if (result.correction) {
      falsePositiveCorrectionOptions.value = [
        result.correction,
        ...falsePositiveCorrectionOptions.value,
      ]
    }
  } catch (err) {
    const errorMsg = err instanceof Error ? err.message : t('feedback.falsePositive.error')
    showErrorToast(errorMsg)
  } finally {
    falsePositivePreviewLoading.value = false
  }
}

function closeMemoryDeleteDialog() {
  clearDeleteDialogTimer()
  isMemoryDeleteDialogOpen.value = false
  deletingMemory.value = null
  openNextDeleteDialogFromQueue()
}

async function confirmMemoryDelete(memory: UserMemory) {
  try {
    await memoriesStore.removeMemory(memory.id)
  } catch {
    // Store shows error notification
  } finally {
    closeMemoryDeleteDialog()
  }
}

function openMemoryDeleteDialog(memory: UserMemory & { toastId: number }) {
  if (isMemoryDeleteDialogOpen.value) {
    deleteDialogQueue.value.push(memory)
    return
  }
  deletingMemory.value = memory
  isMemoryDeleteDialogOpen.value = true
  startDeleteDialogTimer(memory.id)
}

function startDeleteDialogTimer(memoryId: number) {
  clearDeleteDialogTimer()
  deleteDialogTimer = window.setTimeout(() => {
    if (deletingMemory.value?.id === memoryId) {
      confirmMemoryDelete(deletingMemory.value)
    }
  }, deleteDialogAutoConfirmMs)
}

function clearDeleteDialogTimer() {
  if (deleteDialogTimer) {
    clearTimeout(deleteDialogTimer)
    deleteDialogTimer = null
  }
}

function openNextDeleteDialogFromQueue() {
  if (isMemoryDeleteDialogOpen.value || deleteDialogQueue.value.length === 0) return
  const next = deleteDialogQueue.value.shift()
  if (next) {
    openMemoryDeleteDialog(next)
  }
}
</script>

<style scoped>
/* Fade transition for drag overlay */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

/*
 * Phase 3e: backgrounded memory extraction status pill.
 * Fixed-position so it doesn't shift the chat layout. Sits just above the
 * chat input on desktop and stays out of the way on mobile.
 *
 * Uses the brand colour for the border + a brand-tinted background so the
 * pill is unambiguously visible in both light and dark mode (the previous
 * `--bg-elevated` + `--border-light` combo blended into the dark-mode
 * chat background and looked washed-out).
 */
.memory-toast-pill {
  position: absolute;
  bottom: 5.5rem;
  right: 1rem;
  z-index: 30;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.875rem;
  border-radius: 9999px;
  background-color: var(--bg-card);
  /* 2px brand border so the pill reads even when sitting on a dark bubble. */
  border: 2px solid var(--brand);
  /* Larger drop shadow + brand-tinted glow so the pill lifts off the chat
     surface in both themes. */
  box-shadow:
    0 6px 20px rgba(0, 0, 0, 0.18),
    0 0 0 4px var(--brand-alpha-light);
  color: var(--text-primary);
  pointer-events: none;
  white-space: nowrap;
  max-width: calc(100vw - 2rem);
}

.memory-toast-enter-active,
.memory-toast-leave-active {
  transition:
    opacity 200ms ease-out,
    transform 200ms ease-out;
}

.memory-toast-enter-from,
.memory-toast-leave-to {
  opacity: 0;
  transform: translateY(8px);
}
</style>
