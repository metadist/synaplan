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
      <!-- Incognito toggle + collapsed speed config (desktop): there is no
           chat header, so the buttons float over the top-right of the message
           area. The mobile instances live in MainLayout (fixed top-right,
           mirroring the menu button). The mix button hides itself while the
           expanded card is showing in the empty state below. -->
      <div
        v-if="authStore.isAuthenticated && !needsProviderSetup"
        class="v2-desktop-chrome items-center gap-2 absolute top-3 right-3 z-30"
        data-testid="section-incognito-toggle-desktop"
      >
        <ServiceReadinessPill />
        <ModelMixControl />
        <IncognitoToggle />
      </div>

      <!-- Soft top edge so messages fade instead of being cut off. Sits under
           the floating chrome (z-30) and never takes taps. -->
      <div class="chat-top-edge" aria-hidden="true" />

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

      <LiveVoiceStage v-if="liveStage && !needsProviderSetup" v-bind="liveStage" />

      <!-- First-run: no usable AI provider — replace the chat, do not let
           the user send messages that can only fail with HTTP 500. -->
      <div
        v-if="needsProviderSetup"
        class="flex-1 min-h-0 flex flex-col"
        data-testid="state-provider-setup"
      >
        <LocalAiDownloadCard class="mx-auto max-w-[70rem] w-full px-4 pt-4" />
        <ProviderSetupBanner />
      </div>

      <div
        v-else
        ref="chatContainer"
        class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden bg-chat overscroll-contain chat-scroll-keyboard-pad"
        :class="{
          'flex flex-col items-center': isEmptyLanding,
          'max-md:hidden': liveStage,
        }"
        data-testid="section-messages"
        @scroll="handleScroll"
      >
        <div class="max-w-[70rem] mx-auto py-6 px-4" :class="{ 'my-auto w-full': isEmptyLanding }">
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
            <span class="ml-2 txt-secondary text-sm">{{ $t('common.loadingMessages') }}</span>
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
                  guestStore.guestChatDisabled
                    ? $t('guest.disabledTitle')
                    : guestStore.sessionExpired
                      ? $t('guest.expiredTitle')
                      : guestStore.rateLimited
                        ? $t('guest.rateLimitedTitle')
                        : $t('guest.errorTitle')
                }}
              </h2>
              <p class="txt-secondary mb-4">
                {{
                  guestStore.guestChatDisabled
                    ? $t('guest.disabledDescription')
                    : guestStore.sessionExpired
                      ? $t('guest.expiredDescription')
                      : guestStore.rateLimited
                        ? $t('guest.rateLimitedDescription')
                        : $t('guest.errorDescription')
                }}
              </p>
              <div class="flex gap-3 justify-center">
                <!-- Retrying a disabled trial can never succeed. -->
                <button
                  v-if="!guestStore.guestChatDisabled"
                  class="px-4 py-2 rounded-xl btn-brand text-sm font-medium"
                  @click="guestStore.retryInit()"
                >
                  {{ $t('guest.retry') }}
                </button>
                <!-- Sign-in fallback: /register is unreachable when
                     registration is disabled (#462 route guard). -->
                <router-link
                  :to="{ name: configStore.auth.registrationEnabled ? 'register' : 'login' }"
                  class="px-4 py-2 rounded-lg border border-[var(--border)] txt-secondary text-sm font-medium hover:bg-[var(--bg-secondary)] transition-colors"
                >
                  {{
                    configStore.auth.registrationEnabled
                      ? $t('guest.createAccount')
                      : $t('auth.signIn')
                  }}
                </router-link>
              </div>
            </div>
          </div>

          <div
            v-else-if="
              historyStore.messages.length === 0 && !historyStore.isLoadingMessages && !liveStage
            "
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
              <h2 class="text-2xl font-semibold txt-primary mb-2 text-balance max-w-md mx-auto">
                {{ emptyLandingTitle }}
              </h2>
              <p class="txt-secondary">
                {{ emptyLandingHint }}
              </p>
            </div>

            <!-- Speed config: the expanded mix card greets the user on every
                 fresh chat and collapses into the round top-right button on
                 the first tap, click, or keystroke elsewhere. -->
            <div
              v-if="showInlineMixPanel"
              ref="inlineMixPanelEl"
              class="w-full max-w-sm"
              data-testid="section-model-mix-inline"
            >
              <ModelMixPanel @select="dismissInlineMixPanel" />
            </div>

            <AssistantStarterPrompts
              v-if="pinnedAgentId && pinnedStarterPrompts.length > 0"
              :prompts="pinnedStarterPrompts"
              @pick="handleExamplePick"
            />
            <GettingStartedChecklist
              v-if="authStore.isAuthenticated && !incognitoStore.active && !pinnedAgentId"
            />
            <SelfAwareEmptyHint v-if="showSelfAwareEmptyHint" @ask="handleExamplePick" />
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
              :error-reason="message.errorReason"
              :error-message="message.errorMessage"
              :can-retry-model="message.canRetryModel"
              :error-debug="message.errorDebug"
              :again-data="message.againData"
              :backend-message-id="message.backendMessageId"
              :quoted-text="message.quotedText"
              :quoted-message-id="message.quotedMessageId"
              :processing-status="message.isStreaming ? processingStatus : undefined"
              :processing-metadata="message.isStreaming ? processingMetadata : undefined"
              :processing-steps="
                message.isStreaming ? processingTimeline.steps : message.processingSteps
              "
              :processing-model="
                message.isStreaming ? processingTimeline.model : message.processingModel
              "
              :files="message.files"
              :document-changes="message.documentChanges"
              :document-fidelity-lossy="message.documentFidelityLossy"
              :voice-reply-failed="message.voiceReplyFailed"
              :effort="message.effort"
              :effort-notice="message.effortNotice"
              :research-run-id="message.researchRunId"
              :read-aloud-failed="message.readAloudFailed"
              :search-results="message.searchResults"
              :rag-sources="message.ragSources"
              :provider-messages="message.providerMessages"
              :provider-messages-truncated="message.providerMessagesTruncated"
              :ai-models="message.aiModels"
              :web-search="message.webSearch"
              :memory-ids="message.memoryIds"
              :feedback-ids="message.feedbackIds"
              :docs="message.docs"
              :status="message.status"
              :error-type="message.errorType"
              :error-data="message.errorData"
              :truncated="message.truncated"
              :task-plan="message.taskPlan"
              :schedule-source="userTextBefore(historyStore.messages, message.id)"
              :media-job="message.mediaJob"
              :was-multitask="message.wasMultitask"
              :usage="message.usage"
              :usage-extra="message.usageExtra"
              :versions="message.versions"
              :edits="message.edits"
              :usage-taximeter-active="usageTaximeterStore.active"
              :is-guest-mode="isGuestMode"
              :can-rewrite="canRewriteConversation"
              :foreign-memory="sharedConversationLocked"
              @regenerate="handleRegenerate(message, $event)"
              @again="handleAgain"
              @select-version="selectMessageVersion"
              @edit-message="editUserMessage(message, $event)"
              @reattach-file="reattachMessageFile"
              @retry="handleRetryMessage(message, $event)"
              @retry-task="handleTaskRetry"
              @followup-task="handleTaskFollowup"
              @ask-user="answerAskUser(message, $event)"
              @cancel-task="handleTaskCancel"
              @false-positive="openFalsePositiveModal"
              @report="openReportModal"
              @click-memory="handleClickMemory"
              @continue="handleContinueResponse(message)"
              @look-deeper="handleLookDeeper(message)"
              @media-job-update="message.mediaJob = $event"
              @media-job-completed="handleMediaJobCompleted(message, $event)"
              @media-job-cancel="mediaJobsStore.cancel($event)"
            />
          </template>
        </div>
      </div>

      <!-- Usage taximeter: desktop rail + mobile ring. Gated by the admin
           master switch and authenticated (non-guest/widget) web usage; the
           two share one store and differ only by CSS breakpoint. -->
      <!-- A shared conversation must not show the viewer's own day spend as if
           it were the cost of this chat (#2062). -->
      <template
        v-if="
          usageTaximeterStore.active &&
          !needsProviderSetup &&
          chatsStore.conversationAccess === 'owner'
        "
      >
        <ConsumptionBar />
        <ConsumptionRing />
      </template>

      <!-- Contextual Promo Tips -->
      <PromoTipBanner
        v-if="!needsProviderSetup"
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

      <!-- Single composer, always docked at the bottom. On the empty landing
           the messages area shows the welcome hero (plus example prompts for
           anonymous users); keeping the input at the bottom (instead of a
           centered hero composer) means the "+" menu and its dropdowns always
           open upward with room and are never clipped by the chat container's
           overflow (issue #1285). -->
      <SharedConversationBanner
        v-if="sharedConversationLocked && sharedConversationAccess"
        class="mx-4 mb-3"
        :owner-name="sharedConversationOwnerName"
        :shared-via="chatsStore.conversationSource?.sharedVia ?? null"
        :access="sharedConversationAccess"
        :can-continue="chatsStore.conversationAccess === 'use'"
        @continue="continueSharedConversation"
      />
      <div
        v-if="isApprovalsEnabled() && chatPendingApprovals.length > 0"
        class="mx-4 mb-3 space-y-3"
        data-testid="chat-approvals"
      >
        <ApprovalCard
          v-for="row in chatPendingApprovals"
          :key="row.id"
          :approval="row"
          :can-always-allow="row.canAlwaysAllow"
          @approved="onChatApprovalApproved"
          @rejected="onChatApprovalRejected"
          @always-allow="onChatApprovalAlwaysAllow"
        />
      </div>
      <!-- Read-only viewers of a shared chat get no composer, but still see
           the chat's files (open only, no attach or delete). -->
      <div
        v-if="
          !needsProviderSetup &&
          !canComposeSharedChat &&
          !incognitoStore.active &&
          conversationFiles.length > 0
        "
        class="flex max-w-[70rem] mx-auto w-full px-4 mb-3"
        data-testid="section-conversation-files-readonly"
      >
        <ConversationFilesBar
          :files="conversationFiles"
          :can-attach="false"
          :can-delete="false"
          @preview="previewConversationFile"
        />
      </div>
      <ChatFilePreview
        :open="conversationPreview !== null"
        :file="conversationPreview"
        :guest-session-id="isGuestMode ? guestStore.sessionId : null"
        :can-reattach="canComposeSharedChat && !isGuestMode"
        @close="conversationPreview = null"
        @download="downloadConversationPreview"
        @reattach="attachOpenPreview"
      />
      <ChatAccessErrorBanner
        v-if="
          !needsProviderSetup &&
          !canComposeSharedChat &&
          (chatsStore.conversationAccessFailed || retryingConversationAccess)
        "
        class="max-w-[70rem] mx-auto w-full px-4 mb-3"
        :retrying="retryingConversationAccess"
        @retry="retryConversationAccess"
      />
      <FreeApiStartBanner
        v-if="freeApiStartsOnSend && canComposeSharedChat && !isGuestMode"
        class="max-w-[70rem] mx-auto w-full px-4 mb-2"
        :error-key="freeApiStart.startErrorKey.value"
      />
      <FreeApiChatBanner
        v-else-if="!needsProviderSetup && canComposeSharedChat && !isGuestMode"
        class="max-w-[70rem] mx-auto w-full px-4 mb-2"
        :streaming="isStreaming"
      />
      <ChatInput
        v-if="!needsProviderSetup && canComposeSharedChat"
        ref="chatInputRef"
        :is-streaming="isStreaming"
        :send-locked="!assistantThreadReady || freeApiStore.busy"
        :is-guest-mode="isGuestMode"
        :banner-visible="
          showPendingPurchaseBanner ||
          (isGuestMode && guestStore.shouldShowBanner) ||
          Boolean(pinnedAgentId)
        "
        :quote="quoting.pendingQuote.value"
        :before-send="freeApiStartsOnSend ? freeApiStart.readyForSend : undefined"
        @budget-blocked="showLimitOrPaywall"
        @send="handleSendMessage"
        @stop="handleUserStop"
        @guest-feature-gate="handleGuestFeatureGate"
        @clear-quote="quoting.clearPendingQuote"
      >
        <!-- The shared chat stays selected during incognito, so its files must
             not become attachable or deletable from this session. -->
        <template #beside-plus>
          <ConversationFilesBar
            v-if="!incognitoStore.active"
            :files="conversationFiles"
            :can-attach="canComposeSharedChat"
            :can-delete="canComposeSharedChat && !isGuestMode"
            @attach="attachConversationFile"
            @preview="previewConversationFile"
            @remove="removeConversationFile"
          />
        </template>
        <!-- Native onboarding: a signed-out store purchase waiting to be
             linked to an account outranks the guest quota banner. -->
        <template #banner>
          <PendingPurchaseBanner
            v-if="showPendingPurchaseBanner"
            :visible="showPendingPurchaseBanner"
            @dismiss="pendingPurchaseBannerDismissed = true"
          />
          <GuestBanner
            v-else-if="isGuestMode"
            :visible="guestStore.shouldShowBanner"
            :remaining="guestStore.remainingMessages"
            :max-messages="guestStore.maxMessages"
            @dismiss="guestStore.dismissBanner()"
          />
          <div
            v-else-if="incognitoStore.active"
            class="mb-2 rounded-lg surface-chip px-4 py-2.5 text-xs txt-secondary"
            data-testid="banner-incognito"
          >
            <div class="flex items-start gap-2">
              <Icon icon="mdi:incognito" class="mt-0.5 w-4 h-4 shrink-0 txt-brand" />
              <p>
                <strong class="txt-primary">{{ $t('incognito.bannerTitle') }}</strong>
                — {{ $t('incognito.bannerText') }}
              </p>
            </div>
            <label v-if="!incognitoHasMessages" class="mt-2 flex cursor-pointer items-start gap-2">
              <input
                type="checkbox"
                class="mt-0.5 h-4 w-4 shrink-0"
                :checked="incognitoStore.useSavedContext"
                data-testid="check-incognito-saved-context"
                @change="onIncognitoSavedContext"
              />
              <span>
                <span class="font-medium txt-primary">{{ $t('incognito.useSaved') }}</span>
                <span class="mt-0.5 block">
                  {{
                    incognitoStore.useSavedContext
                      ? $t('incognito.useSavedOn')
                      : $t('incognito.useSavedOff')
                  }}
                </span>
              </span>
            </label>
            <p v-else class="mt-2" data-testid="text-incognito-saved-context">
              {{
                incognitoStore.useSavedContext
                  ? $t('incognito.lockedOn')
                  : $t('incognito.lockedOff')
              }}
            </p>
          </div>
          <div
            v-else-if="pinnedAgentId && pinnedAssistantName"
            class="flex items-center justify-center gap-2 px-4 py-2 mb-2 rounded-lg surface-chip text-xs txt-secondary"
            data-testid="banner-pinned-assistant"
          >
            <Icon icon="mdi:robot-outline" class="w-4 h-4 txt-brand flex-shrink-0" />
            <span class="txt-primary">
              {{ $t('assistants.talkingTo', { name: pinnedAssistantName }) }}
            </span>
            <button
              type="button"
              class="btn-secondary inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium"
              data-testid="btn-pinned-assistant-stop"
              @click="stopPinnedAssistant"
            >
              <XMarkIcon class="h-5 w-5" aria-hidden="true" />
              {{ $t('assistants.useDefaultModel') }}
            </button>
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

    <!-- Guest Signup Modal (fallback when there is no purchase channel) -->
    <GuestSignupModal :is-open="showGuestSignupModal" @close="showGuestSignupModal = false" />

    <!-- Upgrade paywall (trial spent, monthly allowance spent, daily reminder) -->
    <SubscriptionPaywallModal
      :is-open="isPaywallOpen"
      :reason="paywallReason"
      @close="closePaywall"
      @unavailable="fallBackFromPaywall"
    />

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

    <ReportContentModal
      :is-open="reportModalOpen"
      :is-submitting="reportSubmitting"
      @close="closeReportModal"
      @submit="submitReport"
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
  </MainLayout>
</template>

<script setup lang="ts">
import {
  ref,
  computed,
  nextTick,
  watch,
  onMounted,
  onBeforeUnmount,
  defineAsyncComponent,
} from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import ChatInput from '@/components/ChatInput.vue'
import ChatAccessErrorBanner from '@/components/ChatAccessErrorBanner.vue'
import ChatMessage from '@/components/ChatMessage.vue'
import MarketingNews from '@/components/MarketingNews.vue'
import AssistantStarterPrompts from '@/components/assistants/AssistantStarterPrompts.vue'
import SelfAwareEmptyHint from '@/components/chat/SelfAwareEmptyHint.vue'
import LiveVoiceStage from '@/components/chat/LiveVoiceStage.vue'
import ConsumptionBar from '@/components/usage/ConsumptionBar.vue'
import ConsumptionRing from '@/components/usage/ConsumptionRing.vue'
import QuoteSelectionButton from '@/components/QuoteSelectionButton.vue'
import ProviderSetupBanner from '@/components/setup/ProviderSetupBanner.vue'
import FreeApiStartBanner from '@/components/freeapi/FreeApiStartBanner.vue'
import FreeApiChatBanner from '@/components/freeapi/FreeApiChatBanner.vue'
import { useFreeApiStore } from '@/stores/freeApi'
import { useFreeApiStartOnSend } from '@/composables/useFreeApiStartOnSend'
import LocalAiDownloadCard from '@/components/setup/LocalAiDownloadCard.vue'
import { useMessageQuoting } from '@/composables/useMessageQuoting'
import { useExtractedMemoryPoll } from '@/composables/useExtractedMemoryPoll'
import {
  openSourceMessage,
  parseOpenMessageQuery,
  parsePositiveQueryId,
  type OpenMessageTarget,
} from '@/composables/useScrollToMessage'
import { useFirstRunSetup } from '@/composables/useFirstRunSetup'
import LimitReachedModal from '@/components/common/LimitReachedModal.vue'
import { useHistoryStore, type Message, type Part } from '@/stores/history'
import { useChatsStore } from '@/stores/chats'
import { iamApi } from '@/services/api/iamApi'
import { isIamSharingEnabled } from '@/composables/useIamFeature'
import { canComposeChat, isSharedConversationLocked } from '@/utils/sharedConversationLock'
import { chatGoneStatus, continueOutcomeAfterRecheck } from '@/utils/chatAccessError'
import {
  attachmentIdsForEdit,
  shouldReloadTranscriptAfterVersionLink,
} from '@/utils/editTranscript'
import SharedConversationBanner from '@/components/iam/SharedConversationBanner.vue'
import { useModelsStore } from '@/stores/models'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useAuthStore } from '@/stores/auth'
import { useMediaJobsStore } from '@/stores/mediaJobs'
import { useApprovalsStore } from '@/stores/approvals'
import { useRealtimeStore } from '@/stores/realtime'
import ApprovalCard from '@/components/chat/ApprovalCard.vue'
import ConversationFilesBar from '@/components/chat/ConversationFilesBar.vue'
import { useConversationFiles } from '@/composables/useConversationFiles'
import { useConversationFilePreview } from '@/composables/useConversationFilePreview'
import { isApprovalsEnabled } from '@/composables/useApprovalsFeature'
import { useGuestStore } from '@/stores/guest'
import { useConfigStore } from '@/stores/config'
import { useUsageTaximeterStore } from '@/stores/usageTaximeter'
import { useMemoriesStore } from '@/stores/userMemories'
import { useFeedbackStore } from '@/stores/userFeedback'
import { useMessageDigestsStore } from '@/stores/messageDigests'
import { useIncognitoStore } from '@/stores/incognito'
import { useResearchStore } from '@/stores/research'
import { useSmartSearchStore } from '@/stores/smartSearch'
import { isAgentsEnabled } from '@/composables/useAgentsFeature'
import {
  capturePinnedAgentForSend,
  goToFreshChat,
  shouldOpenFreshAssistantChat,
  usePinnedAssistant,
} from '@/composables/usePinnedAssistant'
import IncognitoToggle from '@/components/IncognitoToggle.vue'
import ModelMixControl from '@/components/chat/ModelMixControl.vue'
import ModelMixPanel from '@/components/chat/ModelMixPanel.vue'
import ServiceReadinessPill from '@/components/chat/ServiceReadinessPill.vue'
import { useModelMixStore } from '@/stores/modelMix'
import type { IncognitoHistoryEntry } from '@/services/api/chatApi'
import type { StreamUpdatePayload } from '@/types/chatStream'
import {
  cloneTimelineModel,
  cloneTimelineSteps,
  createTimelineState,
  ingestTimelineEvent,
  type TimelineState,
} from '@/utils/processingTimeline'
import { useLimitCheck, type LimitCheckResult } from '@/composables/useLimitCheck'
import { useNotification } from '@/composables/useNotification'
import { busyGuard, busyRefused as refuseBusyTurn } from '@/composables/useBusyRetryOffer'
import { chatApi } from '@/services/api'
import { conversationFilesApi } from '@/services/api/conversationFilesApi'
import { prefetchSseToken } from '@/services/api/chatApi'
import type { ModelOption } from '@/composables/useModelSelection'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import { generatePartId } from '@/utils/mediaParts'
import { applyReadAloudFailed } from '@/utils/voiceReply'
import { buildUploadUrl, isAudioFileType } from '@/utils/mediaTypes'
import { stripPastedBlocks } from '@/utils/pastedContent'
import { groupMessagesByDay, lookDeeperInput, userTextBefore } from '@/utils/chatTurns'
import { shouldShowSelfAwareEmptyHint } from '@/utils/emptyLandingActions'
import { pickEmptyGreetingKey } from '@/utils/emptyGreeting'
import { claimOwnedChatForSend } from '@/utils/claimOwnedChatForSend'
import GettingStartedChecklist from '@/components/chat/GettingStartedChecklist.vue'
import { AudioStreamer } from '@/utils/AudioStreamer'
import { startBargeInMonitor } from '@/services/voice/bargeIn'
import { createSmoothStream, type SmoothStream } from '@/utils/smoothStream'
import {
  createContinueStreamHandler,
  createGuestStreamHandler,
  createMemberStreamHandler,
} from '@/services/chat/stream/streamUpdateHandler'
import type {
  GuestTurn,
  MemberTurn,
  ProcessingIndicator,
  StreamingProcessingMetadata,
  StreamModelFallback,
  StreamRun,
  StreamTurn,
} from '@/services/chat/stream/streamTurn'
import { paintStreamedAnswer } from '@/services/chat/stream/streamedAnswer'
import { VoiceReplyStream } from '@/services/chat/stream/voiceReplyStream'
import { isRecoverableStreamError } from '@/utils/streamError'
import { httpClient } from '@/services/api/httpClient'
import { pluginCommands } from '@/stores/commands'
import { i18n } from '@/i18n'
import { z } from 'zod'
import {
  applyMediaJobUpdateToMessage,
  mapApiMessageRow,
  parseContentWithThinking,
  IN_PROGRESS_TURN_ID,
} from '@/utils/messageMapper'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { getCategories, deleteMemory as deleteMemoryApi } from '@/services/api/userMemoriesApi'
import { deleteFeedback as deleteFeedbackApi } from '@/services/api/userFeedbackApi'
import MemoryToast from '@/components/MemoryToast.vue'
import FalsePositiveModal from '@/components/feedback/FalsePositiveModal.vue'
import ReportContentModal from '@/components/feedback/ReportContentModal.vue'
import { submitContentReport, type ReportReason } from '@/services/api/moderationApi'
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
import MemoryDeleteDialog from '@/components/memories/MemoryDeleteDialog.vue'
import PromoTipBanner from '@/components/PromoTipBanner.vue'
import GuestBanner from '@/components/guest/GuestBanner.vue'
import PendingPurchaseBanner from '@/components/guest/PendingPurchaseBanner.vue'
import GuestSignupModal from '@/components/guest/GuestSignupModal.vue'
import GuestHintPopover from '@/components/guest/GuestHintPopover.vue'
import SubscriptionPaywallModal from '@/components/subscription/SubscriptionPaywallModal.vue'
import { paywallReasonForLimit, usePaywallPrompt } from '@/composables/usePaywallPrompt'
import { hasPendingIapRedemption } from '@/services/nativeIap'
import { isNativeApp } from '@/services/api/nativeRuntime'
import {
  consumePendingShortcut,
  onShortcutAction,
  type ShortcutActionName,
} from '@/services/api/nativeShortcuts'
import { captureNativePhoto, isNativeCameraAvailable } from '@/services/api/nativeCamera'
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

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { showLimitModal, limitData, checkAndShowLimit, closeLimitModal } = useLimitCheck()
const { isPaywallOpen, paywallReason, shouldRemind, openPaywall, closePaywall } = usePaywallPrompt()
const {
  error: showErrorToast,
  success: showSuccessToast,
  warning: notifyWarning,
  info: notifyInfo,
} = useNotification()
const smartSearchStore = useSmartSearchStore()
const { goToProviderSetup } = useFirstRunSetup()

const chatContainer = ref<HTMLElement | null>(null)
const chatInputRef = ref<InstanceType<typeof ChatInput> | null>(null)
const { files: conversationFiles, dismiss: dismissConversationFile } = useConversationFiles()
const {
  conversationPreview,
  previewConversationFile,
  downloadConversationPreview,
  attachConversationFile,
  attachOpenPreview,
  reattachMessageFile,
} = useConversationFilePreview(() => chatInputRef.value)
const ChatFilePreview = defineAsyncComponent(() => import('@/components/chat/ChatFilePreview.vue'))

// Take a file out of this chat. The list and the count update immediately:
// the file stays in the user's files, so there is nothing to confirm.
const removeConversationFile = async (file: { id: number | null; name: string }) => {
  const chatId = chatsStore.activeChatId
  if (file.id === null || chatId == null) {
    return
  }
  const undo = dismissConversationFile(file.id)
  try {
    await conversationFilesApi.detachConversationFile(chatId, file.id)
    showSuccessToast(t('chat.conversationFiles.deleted', { name: file.name }))
  } catch {
    undo()
    showErrorToast(t('chat.conversationFiles.deleteFailed', { name: file.name }))
  }
}
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
const sharedConversationLocked = computed(() =>
  isSharedConversationLocked(chatsStore.conversationAccess, incognitoStore.active)
)
const sharedConversationAccess = computed(() =>
  chatsStore.conversationAccess === 'read' || chatsStore.conversationAccess === 'use'
    ? chatsStore.conversationAccess
    : null
)
const sharedConversationOwnerName = computed(
  () => chatsStore.conversationSource?.owner?.name?.trim() || null
)
const canComposeSharedChat = computed(() =>
  canComposeChat({
    guest: isGuestMode.value,
    incognito: incognitoStore.active,
    access: chatsStore.conversationAccess,
    sharingEnabled: isIamSharingEnabled(),
    chatOpen: chatsStore.activeChatId != null,
  })
)
// Owner-only writes (Again, Retry, Stop, task-plan controls). Unresolved
// access stays null while the lookup is in flight or has failed, so it must
// not count as owner — same rule as the composer.
const canRewriteConversation = canComposeSharedChat

const retryingConversationAccess = ref(false)
const retryConversationAccess = async () => {
  if (retryingConversationAccess.value) return
  retryingConversationAccess.value = true
  try {
    await chatsStore.retryConversationAccess()
  } finally {
    retryingConversationAccess.value = false
  }
}

const continueSharedConversation = async () => {
  const id = chatsStore.activeChatId
  if (!id) return
  try {
    const copy = await iamApi.continueChat(id)
    chatsStore.setActiveChat(copy.id)
    await chatsStore.loadChats()
    await chatsStore.loadConversationAccess(copy.id)
  } catch (error) {
    if (chatGoneStatus(error)) {
      await chatsStore.loadConversationAccess(id)
      const outcome = continueOutcomeAfterRecheck(
        chatsStore.activeChatId === id,
        chatsStore.conversationAccess
      )
      if (outcome === 'released') return
      if (outcome === 'read-only') {
        showErrorToast(t('iam.continueNeedsUse'))
        return
      }
    }
    showErrorToast(t('iam.continueFailed'))
  }
}
const modelsStore = useModelsStore()
const aiConfigStore = useAiConfigStore()
const authStore = useAuthStore()
const mediaJobsStore = useMediaJobsStore()
const approvalsStore = useApprovalsStore()
const realtimeStore = useRealtimeStore()
const guestStore = useGuestStore()

const chatPendingApprovals = computed(() =>
  approvalsStore.pending.filter(
    (row) => row.requestedBy.kind === 'chat' && row.requestedBy.chatId === chatsStore.activeChatId
  )
)

async function onChatApprovalApproved(id: number): Promise<void> {
  try {
    await approvalsStore.approve(id)
    showSuccessToast(t('approvals.approvedToast'))
    void settleChatApproval(id)
  } catch {
    showErrorToast(t('approvals.decideFailed'))
  }
}

async function onChatApprovalRejected(id: number, reason: string): Promise<void> {
  try {
    await approvalsStore.reject(id, reason)
    showSuccessToast(t('approvals.rejectedToast'))
    void settleChatApproval(id)
  } catch {
    showErrorToast(t('approvals.decideFailed'))
  }
}

async function onChatApprovalAlwaysAllow(id: number): Promise<void> {
  try {
    await approvalsStore.approve(id, true)
    showSuccessToast(t('approvals.approvedToast'))
    void settleChatApproval(id)
  } catch {
    showErrorToast(t('approvals.decideFailed'))
  }
}

/**
 * Q1: the decided approval continues this thread. Only a healthy socket can be
 * trusted to deliver the `approval.chat_continued` event: while realtime is
 * connecting, reconnecting, or never connected, the event may be missed, so
 * fall back to waiting for the worker outcome, then reload once — the reload
 * is cheap and idempotent either way.
 */
async function settleChatApproval(id: number): Promise<void> {
  if (isViewUnmounted) {
    return
  }
  if (realtimeStore.state === 'connected') {
    return // the approval.chat_continued event reloads the thread
  }
  const chatId = chatsStore.activeChatId
  if (chatId == null) {
    return
  }
  await approvalsStore.waitForOutcome(id)
  if (isViewUnmounted) {
    return
  }
  await historyStore.loadMessages(chatId)
}

// Q1: a decided chat approval appends a follow-up message to the thread —
// from this card or from the Approvals inbox (J-TL-2). Reload the open chat
// when its thread continued elsewhere.
const stopContinuationListen = approvalsStore.onChatContinued((continuation) => {
  if (isViewUnmounted) {
    return
  }
  if (continuation.chatId !== chatsStore.activeChatId) {
    return
  }
  void historyStore.loadMessages(continuation.chatId)
})

function ingestApprovalRequired(data: StreamUpdatePayload): void {
  if (!isApprovalsEnabled()) {
    return
  }
  if (data.status !== 'task_update' || data.metadata?.state !== 'waiting_approval') {
    return
  }
  const approvalId = data.metadata.approval_id
  if (typeof approvalId !== 'number' || approvalId < 1) {
    return
  }
  const rawClass = data.metadata.side_effect
  const sideEffect =
    rawClass === 'read' || rawClass === 'write' || rawClass === 'destructive' ? rawClass : 'read'
  approvalsStore.addFromStream({
    id: approvalId,
    tool: typeof data.metadata.tool === 'string' ? data.metadata.tool : '',
    preview: typeof data.metadata.preview === 'string' ? data.metadata.preview : '',
    status: 'pending',
    expiresAt: typeof data.metadata.expires_at === 'number' ? data.metadata.expires_at : 0,
    created: Math.floor(Date.now() / 1000),
    requestedBy: {
      kind: 'chat',
      chatId: chatsStore.activeChatId,
      messageId: data.messageId ?? null,
    },
    sideEffect,
    canAlwaysAllow: sideEffect === 'write',
  })
  void approvalsStore.load('pending')
}
const configStore = useConfigStore()
const usageTaximeterStore = useUsageTaximeterStore()
const memoriesStore = useMemoriesStore()
const feedbackStore = useFeedbackStore()
const messageDigestsStore = useMessageDigestsStore()
const incognitoStore = useIncognitoStore()
const researchStore = useResearchStore()
const incognitoHasMessages = computed(() => historyStore.messages.length > 0)

function onIncognitoSavedContext(event: Event) {
  const input = event.target
  if (!(input instanceof HTMLInputElement)) return
  incognitoStore.setUseSavedContext(input.checked)
}
const {
  agentId: pinnedAgentId,
  queryAgentId,
  name: pinnedAssistantName,
  starterPrompts: pinnedStarterPrompts,
} = usePinnedAssistant()

async function stopPinnedAssistant(): Promise<void> {
  // A thread that already names the assistant keeps that pin on its messages.
  // Leave it for an empty chat first, then drop the address pin, so the next
  // message goes to the default model.
  await chatsStore.findOrCreateEmptyChat()
  await goToFreshChat(router, route)
}

// Start chat (?agentId=) may still be leaving the previous thread. Send stays
// off until that empty chat is the one on screen, so the first message cannot
// land in the last conversation.
const assistantThreadReady = ref(queryAgentId.value == null || !isAgentsEnabled())
let assistantChatBootstrapped = assistantThreadReady.value
let suppressNextChatHistoryLoad = false
let suppressBottomScrollForFocus = false
let historyLoadedChatId: number | null = null
// The history load the activeChatId watcher has in flight. The boot path joins
// it instead of starting a second load for the same chat: a second load makes
// the first response stale, so the spinner waits for the slower request.
let watcherHistoryLoad: { chatId: number; done: Promise<void> } | null = null
// False until the mount has settled the first chat selection.
let chatBootSettled = false
let programmaticMessageScroll = false
let messageFocusGeneration = 0
let messageFocusQueue: Promise<void> = Promise.resolve()
const messageFocusIdleStops = new Set<() => void>()

// A chat load that began before a message focus was requested must not pull
// the view back to the bottom once that focus has already scrolled.
function bottomScrollYieldsToFocus(focusAtStart: number): boolean {
  return suppressBottomScrollForFocus || messageFocusGeneration !== focusAtStart
}

async function openFreshAssistantChatIfNeeded(): Promise<void> {
  if (!queryAgentId.value || !isAgentsEnabled() || !authStore.isAuthenticated) {
    assistantThreadReady.value = true
    return
  }
  if (!shouldOpenFreshAssistantChat(queryAgentId.value, historyStore.messages)) {
    assistantThreadReady.value = true
    return
  }
  assistantThreadReady.value = false
  const previousChatId = chatsStore.activeChatId
  suppressNextChatHistoryLoad = true
  try {
    const chat = await chatsStore.findOrCreateEmptyChat()
    const chatId = chat?.id ?? chatsStore.activeChatId
    if (chatId && chatId !== previousChatId) {
      await historyStore.loadMessages(chatId)
    }
  } finally {
    if (chatsStore.activeChatId === previousChatId) {
      suppressNextChatHistoryLoad = false
    }
    assistantThreadReady.value = true
  }
}

function captureOutgoingAgentId(): number | null {
  return capturePinnedAgentForSend(isAgentsEnabled(), queryAgentId.value, historyStore.messages)
}

function outgoingAgentId(explicit?: number | null): number | undefined {
  if (explicit && explicit > 0) {
    return explicit
  }
  return captureOutgoingAgentId() ?? undefined
}

const promoTips = usePromoTips()
const { getDateLabel } = useDateFormat()

const isGuestMode = computed(() => !authStore.isAuthenticated && guestStore.isGuestMode)

// MOBILE-APP SEAM (native onboarding): remind a signed-out user that
// their store purchase is still waiting for an account. Read once at setup —
// the flag only changes through a sign-in (which flips isAuthenticated and
// hides the banner anyway) or a new purchase (full navigation in between).
const pendingPurchaseAtSetup = hasPendingIapRedemption()
const pendingPurchaseBannerDismissed = ref(false)
const showPendingPurchaseBanner = computed(
  () =>
    pendingPurchaseAtSetup && !pendingPurchaseBannerDismissed.value && !authStore.isAuthenticated
)

// Chosen once per visit. A refresh picks again; later store updates must not
// swap the heading while the person is looking at the empty chat.
const emptyGreetingKey = pickEmptyGreetingKey(Math.random())

const emptyLandingTitle = computed(() => {
  if (incognitoStore.active) {
    return t('incognito.emptyTitle')
  }
  if (pinnedAgentId.value && pinnedAssistantName.value) {
    return pinnedAssistantName.value
  }
  return t(`companionLinks.greetings.${emptyGreetingKey}`)
})
const emptyLandingHint = computed(() => {
  if (incognitoStore.active) {
    return t('incognito.emptyHint')
  }
  if (pinnedAgentId.value && pinnedAssistantName.value) {
    return t('assistants.emptyHint', { name: pinnedAssistantName.value })
  }
  return t('chatInput.placeholder')
})
const showGuestSignupModal = ref(false)
const featureGateOpen = ref(false)
const featureGateKey = ref('general')

/** The block that opened the paywall, kept so the fallback modal can show it. */
const blockedLimit = ref<LimitCheckResult | null>(null)

/**
 * A blocked message either opens the upgrade paywall or the plain informational
 * limit modal — `paywallReasonForLimit` owns which block deserves which.
 */
function showLimitOrPaywall(result: LimitCheckResult): void {
  blockedLimit.value = result
  const reason = paywallReasonForLimit(result)

  if (reason && openPaywall(reason)) {
    return
  }
  checkAndShowLimit(result)
}

/**
 * The paywall has nothing to sell (catalogue unreachable, or every tier above
 * this user deactivated). Hand the user back to the modal the paywall replaced
 * so a blocked message still explains itself; a mere reminder just goes away.
 */
function fallBackFromPaywall(): void {
  const reason = paywallReason.value
  closePaywall()

  if ('guest_limit' === reason) {
    showGuestSignupModal.value = true
    return
  }
  if ('reminder' === reason) return
  if (blockedLimit.value) checkAndShowLimit(blockedLimit.value)
}

/**
 * Opportunistic upgrade reminder, throttled to once a day by
 * `usePaywallPrompt`. Skipped in a brand-new session so nothing pops up right
 * after onboarding — the user has to have sent something first.
 */
function maybeRemindAboutUpgrade(): void {
  const hasUsedTheApp = authStore.isAuthenticated
    ? historyStore.messages.length > 0
    : guestStore.messageCount > 0
  if (!hasUsedTheApp || !shouldRemind()) return
  openPaywall('reminder')
}

// Empty landing: no messages, not loading, and not the guest-error state.
// Keeps the welcome content vertically centered while the composer remains
// docked at the bottom, leaving enough room for its upward-opening menus.
const isEmptyLanding = computed(
  () =>
    !(guestStore.initFailed && !authStore.isAuthenticated) &&
    historyStore.messages.length === 0 &&
    !historyStore.isLoadingMessages
)

// A running live conversation puts its orb above the messages, and the
// welcome cards step aside until the first words arrive.
const liveStage = computed(() => chatInputRef.value?.liveStage ?? null)

// Runtime-config first-run signal: the default chat model has no usable
// provider. Replace the composer with a tombstone so a fresh install cannot
// produce the cryptic HTTP 500 the old banner still allowed — unless the
// Synaplan Demo starts with the admin's first message.
const chatHasNoProvider = computed(
  () => authStore.isAuthenticated && configStore.setup.chatReady === false
)
const freeApiStore = useFreeApiStore()
const freeApiStart = useFreeApiStartOnSend(chatHasNoProvider, (text) =>
  chatInputRef.value?.setInputText(text)
)
const freeApiStartsOnSend = freeApiStart.startsOnSend
const needsProviderSetup = computed(() => chatHasNoProvider.value && !freeApiStartsOnSend.value)

// Empty start page: the self-aware question is the one suggested action in
// the first week after the account was created. A pinned assistant with its
// own starters replaces it. It only shows when the composer can submit.
const emptyLandingActions = computed(() => ({
  incognito: incognitoStore.active,
  hasPinnedAssistant: Boolean(pinnedAgentId.value),
  starterPromptCount: pinnedStarterPrompts.value.length,
  canCompose: canComposeSharedChat.value,
  needsProviderSetup: needsProviderSetup.value,
  hasAccount: authStore.isAuthenticated,
  accountCreatedAt: authStore.user?.created ?? null,
  nowMs: Date.now(),
}))
const showSelfAwareEmptyHint = computed(() =>
  shouldShowSelfAwareEmptyHint(emptyLandingActions.value)
)

// --- Speed config (model mixes) -------------------------------------------
// The expanded card shows on every fresh, non-incognito chat until the user
// interacts anywhere (tap, click, or typing); it then collapses into the
// round ModelMixControl button next to the incognito toggle. The store flag
// keeps that button hidden while the card is up, on desktop AND mobile.
const modelMixStore = useModelMixStore()
const mixPanelDismissed = ref(false)
const inlineMixPanelEl = ref<HTMLElement | null>(null)

const showInlineMixPanel = computed(() => false)

watch(showInlineMixPanel, (visible) => {
  modelMixStore.inlinePanelVisible = visible
})

const dismissInlineMixPanel = () => {
  mixPanelDismissed.value = true
}

// A fresh chat gets the card back.
watch(
  () => chatsStore.activeChatId,
  (chatId) => {
    mixPanelDismissed.value = false
    if (chatId) {
      void chatsStore.loadConversationAccess(chatId)
    } else {
      chatsStore.resolveConversationAccessAsOwn()
    }
  },
  { immediate: true }
)

// "Once tapped, clicked or text is entered, the popup closes": any pointer
// press outside the card and any keystroke (the composer is focused on load,
// so typing lands there) collapse it.
const collapseMixPanelOnPointer = (event: PointerEvent) => {
  if (!showInlineMixPanel.value) return
  if (inlineMixPanelEl.value?.contains(event.target as Node)) return
  dismissInlineMixPanel()
}

const collapseMixPanelOnKeydown = () => {
  if (!showInlineMixPanel.value) return
  dismissInlineMixPanel()
}

onMounted(() => {
  modelMixStore.inlinePanelVisible = showInlineMixPanel.value
  document.addEventListener('pointerdown', collapseMixPanelOnPointer)
  document.addEventListener('keydown', collapseMixPanelOnKeydown)
})

onBeforeUnmount(() => {
  modelMixStore.inlinePanelVisible = false
  document.removeEventListener('pointerdown', collapseMixPanelOnPointer)
  document.removeEventListener('keydown', collapseMixPanelOnKeydown)
})

function handleGuestFeatureGate(key: string) {
  featureGateKey.value = key
  featureGateOpen.value = true
}

// MOBILE-APP SEAM: iOS App Shortcuts land here after the native bootstrap
// pulls (cold start) or pushes (warm start) the pending action. `open` is a
// no-op — the app is already visible. Photos are attached, never auto-sent.
let stopShortcutListen: (() => void) | null = null
const handledShortcutTokens = new Set<string>()
// The composer is `v-if`-gated behind provider setup, so a shortcut can arrive
// before it exists. The action is parked here and run by the watcher below
// once the composer is mounted, instead of polling for the ref: dictation and
// the camera are useless without an input to hand their result to, and a
// captured photo with nowhere to go would be silently discarded.
const queuedShortcut = ref<Exclude<ShortcutActionName, 'open'> | null>(null)

async function runNativeShortcut(
  input: InstanceType<typeof ChatInput>,
  action: Exclude<ShortcutActionName, 'open'>
): Promise<void> {
  if ('dictate' === action) {
    await input.startDictation()
    return
  }
  if (isGuestMode.value) {
    handleGuestFeatureGate('attach')
    return
  }
  if (!isNativeCameraAvailable()) {
    showErrorToast(t('chatInput.cameraUnavailable'))
    return
  }
  const file = await captureNativePhoto()
  if (file) {
    await chatInputRef.value?.uploadFiles([file])
  }
}

function handleNativeShortcut(action: ShortcutActionName, token: string): void {
  if (token && handledShortcutTokens.has(token)) {
    return
  }
  if (token) {
    handledShortcutTokens.add(token)
  }
  if ('open' !== action) {
    queuedShortcut.value = action
  }
}

watch(
  [chatInputRef, queuedShortcut],
  ([input, action]) => {
    if (!input || !action) {
      return
    }
    queuedShortcut.value = null
    void runNativeShortcut(input, action)
  },
  { flush: 'post' }
)

const isSummarizeToolQuery = (tool: unknown): boolean =>
  tool === 'summarize' || (Array.isArray(tool) && tool.includes('summarize'))

watch(
  [chatInputRef, () => route.query.tool],
  ([input, tool]) => {
    if (!input || !isSummarizeToolQuery(tool)) {
      return
    }
    input.armSummarize()
    const nextQuery = { ...route.query }
    delete nextQuery.tool
    void router.replace({ path: route.path, query: nextQuery })
  },
  { flush: 'post' }
)

// Smart Search fills the composer via `?prefill=` (slash commands). A URL
// can only ever produce a draft. Sending ("Ask in chat") comes solely from
// the in-memory store intent, so a crafted link cannot send on someone's
// behalf.
let deliveringSearchPrefill = false
let deliveringSearchAsk = false

watch(
  [chatInputRef, () => route.query.prefill],
  async ([input, prefill]) => {
    if (deliveringSearchPrefill || !input || typeof prefill !== 'string' || prefill === '') {
      return
    }
    deliveringSearchPrefill = true
    try {
      const nextQuery = { ...route.query }
      delete nextQuery.prefill
      delete nextQuery.send
      await router.replace({ path: route.path, query: nextQuery })
      chatInputRef.value?.setInputText(prefill)
    } finally {
      deliveringSearchPrefill = false
    }
  },
  { flush: 'post' }
)

/** Opens the thread the question goes to. Returns false when there is none. */
async function openThreadForSearchAsk(): Promise<boolean> {
  // An incognito session stays incognito: the question joins the current
  // in-memory conversation instead of a new, stored chat.
  if (incognitoStore.active) {
    return true
  }

  const previousChatId = chatsStore.activeChatId
  // The chat-id watcher reloads history and would replace an optimistic
  // user message. Skip that reload, load the empty thread ourselves, then send.
  suppressNextChatHistoryLoad = true
  const chat = await chatsStore.findOrCreateEmptyChat()
  await nextTick()
  if (!chat || chat.id === previousChatId) {
    suppressNextChatHistoryLoad = false
  }
  if (!chat) {
    return false
  }
  await historyStore.loadMessages(chat.id)
  return true
}

async function deliverSearchAsk(text: string): Promise<void> {
  if (text.startsWith('/')) {
    chatInputRef.value?.setInputText(text)
    notifyWarning(t('search.palette.ask.commandDraft'))
    return
  }
  if (!(await openThreadForSearchAsk())) {
    chatInputRef.value?.setInputText(text)
    notifyWarning(t('search.palette.ask.notSent'))
    return
  }
  const sent = (await chatInputRef.value?.submitText(text)) ?? false
  if (!sent) {
    notifyWarning(t('search.palette.ask.notSent'))
  }
}

watch(
  [chatInputRef, () => smartSearchStore.pendingAsk],
  async ([input, pending]) => {
    if (deliveringSearchAsk || !input || !pending) {
      return
    }
    const text = smartSearchStore.takePendingAsk()
    if (!text) {
      return
    }
    deliveringSearchAsk = true
    try {
      await deliverSearchAsk(text)
    } finally {
      deliveringSearchAsk = false
    }
  },
  { flush: 'post' }
)

function initChatShortcuts(): void {
  if (!isNativeApp()) {
    return
  }
  stopShortcutListen = onShortcutAction((payload) => {
    handleNativeShortcut(payload.action, payload.token)
  })
  void consumePendingShortcut().then((pending) => {
    if (pending) {
      handleNativeShortcut(pending.action, pending.token)
    }
  })
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
// Run this view is already re-attached to, so a repeated history load (the 2s
// in-progress poll, a chat switch back and forth) cannot open a second
// connection and render the same answer twice.
let attachedRunId: string | null = null
// Chat currently marked as "still answering" in the sidebar by this view.
let generatingChatId: number | null = null
let currentAudioStreamer: AudioStreamer | null = null
let stopBargeIn: (() => void) | null = null
const isAudioStreaming = ref(false)

// Processing status for real-time feedback
const processingStatus = ref<string>('')

const processingMetadata = ref<StreamingProcessingMetadata>({})

// Ordered record of the running turn's pipeline steps. `processingStatus`
// above keeps only the latest phase; this keeps all of them so the bubble can
// show what is done, what is running and for how long (`ProcessingTimeline`).
const processingTimeline = ref<TimelineState>(createTimelineState())

const ingestTimeline = (data: StreamUpdatePayload) => {
  ingestTimelineEvent(processingTimeline.value, data)
  const message = historyStore.messages.find((m) => m.isStreaming && m.role === 'assistant')
  if (!message) return
  // Clone so the next turn's empty timeline cannot wipe this message's summary.
  message.processingSteps = cloneTimelineSteps(processingTimeline.value.steps)
  message.processingModel = cloneTimelineModel(processingTimeline.value.model)
}

// Phase 3e: non-blocking pill that surfaces when backgrounded memory
// extraction (Phase 2) completes after the assistant message has already
// landed. Lives outside the bubble so it can never push content around.
const { memoryToastVisible, memoryToastMessage, scheduleMemoryPoll } = useExtractedMemoryPoll()

// Memory suggestion toasts
const activeMemoryToasts = ref<Array<UserMemory & { toastId: number }>>([])
let memoryToastIdCounter = 0

// Content report modal (Apple Guideline 1.2)
const reportModalOpen = ref(false)
const reportSubmitting = ref(false)
const reportMessageId = ref<number | null>(null)

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

// Use mock data in development or when API is not available
const useMockData = import.meta.env.VITE_USE_MOCK_DATA === 'true' || false

const isStreaming = computed(() => {
  return historyStore.messages.some((m) => m.isStreaming === true) || isAudioStreaming.value
})

// Init on mount
onMounted(async () => {
  initChatShortcuts()
  // Subscribe to the per-user realtime channel so finished renders resolve
  // their banner instantly (push primary). No-op for guests / when realtime is
  // disabled; the 25s banner poll remains the fallback.
  void mediaJobsStore.subscribe(authStore.user?.id)
  if (isApprovalsEnabled()) {
    void approvalsStore.subscribe(authStore.user?.id)
    if (authStore.isAuthenticated) {
      void approvalsStore.load('pending')
    }
  }
  // Hydrate the global Jobs tray with any renders already running across chats.
  if (authStore.isAuthenticated) {
    void mediaJobsStore.loadActive()
  }

  if (!authStore.isAuthenticated) {
    await guestStore.initSession()
    if (isViewUnmounted) return

    if (guestStore.chatId) {
      const rawMessages = await guestStore.loadMessages()
      if (isViewUnmounted) return
      if (rawMessages.length > 0) {
        historyStore.clear()
        // Use the SAME row mapper as the authenticated reload path (issue
        // #1070) so guests keep full parity: task-plan cards and generated
        // media (video/image/audio) survive a reload instead of collapsing to
        // the plain answer text.
        const loaded: Message[] = rawMessages.map((m) => mapApiMessageRow(m))
        historyStore.messages.push(...loaded)
        await nextTick()
        if (isViewUnmounted) return
        scrollToBottom()
      }

      // A turn that was still generating when the guest reloaded keeps writing
      // into a fresh bubble instead of being lost.
      void resumeActiveRunIfAny()
    }

    if (isViewUnmounted) return
    const restricted = route.query.restricted as string | undefined
    if (restricted) {
      featureGateKey.value = restricted
      featureGateOpen.value = true
      router.replace({ query: {} })
    }

    setTimeout(() => {
      if (isViewUnmounted) return
      if (chatInputRef.value?.textareaRef) {
        chatInputRef.value.textareaRef.focus()
      }
    }, 100)

    if (isViewUnmounted) return
    window.addEventListener('open-memory-dialog', handleOpenMemoryDialogEvent)
    window.addEventListener('open-feedback-dialog', handleOpenFeedbackDialogEvent)
    window.addEventListener('open-first-run-setup', handleOpenFirstRunSetupEvent)
    window.addEventListener('open-message-reference', handleOpenMessageReferenceEvent)
    maybeRemindAboutUpgrade()
    assistantThreadReady.value = true
    assistantChatBootstrapped = true
    return
  }

  // The chat list needs nothing from the model catalog, so both requests go out
  // together. Awaiting the catalog first put the list — and behind it the first
  // chat and the composer — one extra round trip away from the user for no
  // reason; on a slow backend that cost several seconds of staring at an empty
  // chat.
  const chatsLoaded = chatsStore.loadChats()

  // Models config is needed for Again functionality
  const modelsLoaded = Promise.all([aiConfigStore.loadModels(), aiConfigStore.loadDefaults()])

  // Start loading memories in background (don't await - non-blocking!)
  // Memories button will be disabled until loaded
  memoriesStore.fetchMemories().catch((err) => {
    console.warn('⚠️ Failed to load memories in background:', err)
  })

  // Also load feedbacks in background for badge rendering
  feedbackStore.fetchFeedbacks({ silent: true }).catch((err) => {
    console.warn('⚠️ Failed to load feedbacks in background:', err)
  })

  await Promise.all([chatsLoaded, modelsLoaded])
  // The user may already have left the chat. route.query would then be the
  // page they are on, and router.replace would rewrite it. The same leftover
  // boot is what warns that the composer ref is gone.
  if (isViewUnmounted) return

  // Deep link: /?chat=<id> opens that thread (Saved Tasks). Without it the
  // view reopened the last active chat. /?chat=<id>&message=<id> also scrolls
  // to that message (long-term memory and [Message:ID] badges). Both params
  // are removed after handling so a reload does not jump again.
  const focusTarget = parseOpenMessageQuery(route.query)
  const requestedChatId = focusTarget?.chatId ?? parsePositiveQueryId(route.query.chat)
  const openedSpecificChat = requestedChatId !== null
  if (openedSpecificChat) {
    void router.replace({
      query: {
        ...route.query,
        chat: undefined,
        ...(focusTarget ? { message: undefined } : {}),
      },
    })
  }

  if (focusTarget) {
    await enqueueMessageFocus(focusTarget)
  } else if (requestedChatId !== null) {
    chatsStore.setActiveChat(requestedChatId)
  }
  if (isViewUnmounted) return

  // If no active chat, create one. A message deep link loads through
  // enqueueMessageFocus, which owns that chat's history.
  if (!focusTarget) {
    const bootChatId = chatsStore.activeChatId
    if (!bootChatId) {
      await chatsStore.createChat('New Chat')
    } else if (watcherHistoryLoad?.chatId === bootChatId) {
      // The selection changed while the list was loading (boot restore or a
      // New Chat click); the watcher loads this chat and resumes its run.
      await watcherHistoryLoad.done
    } else if (historyLoadedChatId !== bootChatId && !incognitoStore.active) {
      // Load messages for active chat. An incognito session started during
      // boot keeps its transcript; ending it loads this chat.
      await historyStore.loadMessages(bootChatId)
      if (isViewUnmounted) return
      if (chatsStore.activeChatId !== null) {
        historyLoadedChatId = chatsStore.activeChatId
      }
      // A turn that was still generating when the page was reloaded keeps
      // writing into this bubble instead of being lost.
      void resumeActiveRunIfAny()
    }
  }
  if (isViewUnmounted) return

  // Start chat from an assistant must not reopen an unrelated last thread.
  // A ?chat= deep link (Saved Tasks) keeps that thread even if agentId is set.
  // Send stays disabled until this returns, including when the decision is
  // "stay on this chat".
  try {
    if (!openedSpecificChat) {
      await openFreshAssistantChatIfNeeded()
    }
  } finally {
    if (!isViewUnmounted) {
      assistantThreadReady.value = true
      assistantChatBootstrapped = true
      chatBootSettled = true
    }
  }
  if (isViewUnmounted) return

  // Usage taximeter: seed today's totals once and rebuild the session from the
  // freshly loaded history (no-op when the admin switch is off / not authed).
  if (usageTaximeterStore.active) {
    void usageTaximeterStore.loadSummary()
    usageTaximeterStore.seedFromHistory(historyStore.messages)
  }

  // Scroll to newest message after initial load. A message deep link already
  // scrolled to that message inside enqueueMessageFocus.
  await nextTick()
  if (isViewUnmounted) return
  if (!focusTarget) {
    scrollToBottom(true)
  }

  // Auto-focus ChatInput after mounting with delay
  setTimeout(() => {
    if (isViewUnmounted) return
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
  window.addEventListener('open-first-run-setup', handleOpenFirstRunSetupEvent)
  window.addEventListener('open-message-reference', handleOpenMessageReferenceEvent)

  // Phase 1d: keep the SSE token cache warm.
  //   - Prefetch on mount so the first message of a session never waits.
  //   - Re-prefetch when the tab regains focus (token may have expired in
  //     the background; warming it now avoids a stale-cache hit on the
  //     user's first interaction after switching back).
  prefetchSseToken()
  window.addEventListener('focus', prefetchSseToken)
  document.addEventListener('visibilitychange', handleVisibilityChangeForToken)

  maybeRemindAboutUpgrade()
})

const handleVisibilityChangeForToken = () => {
  if (document.visibilityState !== 'visible') return
  prefetchSseToken()
  const chatId = chatsStore.activeChatId
  const access = chatsStore.conversationAccess
  if (chatsStore.conversationAccessFailed) {
    void retryConversationAccess()
  } else if (chatId && (access === 'read' || access === 'use')) {
    void chatsStore.loadConversationAccess(chatId)
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

// Window event handler for memory badges (used by MessageText.vue)
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

// Demo-mode CTA: sign in as the seeded admin (if needed) and open setup.
const handleOpenFirstRunSetupEvent = () => {
  void goToProviderSetup()
}

function waitForMessagesIdle(): Promise<void> {
  if (!historyStore.isLoadingMessages) return Promise.resolve()
  return new Promise((resolve) => {
    let settled = false
    let stop: (() => void) | undefined
    const finish = () => {
      if (settled) return
      settled = true
      stop?.()
      messageFocusIdleStops.delete(finish)
      resolve()
    }
    stop = watch(
      () => historyStore.isLoadingMessages,
      (loading) => {
        if (!loading) finish()
      }
    )
    messageFocusIdleStops.add(finish)
    if (!historyStore.isLoadingMessages) finish()
  })
}

async function prepareChatForMessage(
  chatId: number,
  isCancelled: () => boolean
): Promise<'ready' | 'gone'> {
  if (isCancelled()) return 'ready'

  if (incognitoStore.active) {
    await incognitoStore.endSession()
    await nextTick()
    if (historyStore.isLoadingMessages) await waitForMessagesIdle()
    await nextTick()
  }
  if (isCancelled()) return 'ready'

  if (chatsStore.activeChatId !== chatId) {
    suppressNextChatHistoryLoad = true
    chatsStore.setActiveChat(chatId)
    await nextTick()
    if (historyStore.isLoadingMessages) await waitForMessagesIdle()
  }
  if (isCancelled()) return 'ready'
  if (chatsStore.activeChatId !== chatId) return 'gone'
  if (historyStore.isLoadingMessages) await waitForMessagesIdle()
  if (isCancelled()) return 'ready'
  if (chatsStore.activeChatId !== chatId) return 'gone'

  if (historyLoadedChatId !== chatId) {
    await historyStore.loadMessages(chatId)
    if (isCancelled()) return 'ready'
    if (chatsStore.activeChatId !== chatId) return 'gone'
    historyLoadedChatId = chatId
    void resumeActiveRunIfAny()
    if (usageTaximeterStore.active) {
      usageTaximeterStore.seedFromHistory(historyStore.messages)
    }
  }

  await nextTick()
  return chatsStore.activeChatId === chatId ? 'ready' : 'gone'
}

function findLoadedMessageElement(messageId: number): HTMLElement | null {
  const root = chatContainer.value
  if (!root) return null
  return root.querySelector<HTMLElement>(
    `[data-testid="message-container"][data-message-id="${messageId}"]`
  )
}

function enqueueMessageFocus(target: OpenMessageTarget): Promise<void> {
  const generation = ++messageFocusGeneration
  const isCancelled = () => generation !== messageFocusGeneration || isViewUnmounted
  const run = messageFocusQueue.then(async () => {
    if (isCancelled() || !authStore.isAuthenticated) return
    suppressBottomScrollForFocus = true
    try {
      await openSourceMessage(target, {
        ensureChatReady: (chatId) => prepareChatForMessage(chatId, isCancelled),
        isMessageLoaded: (messageId) =>
          historyStore.messages.some((message) => message.backendMessageId === messageId),
        hasMoreMessages: () => historyStore.hasMoreMessages,
        loadOlderMessages: async () => {
          const chatId = chatsStore.activeChatId
          if (chatId === null) return
          await historyStore.loadMoreMessages(chatId)
        },
        loadedCount: () => historyStore.messages.length,
        findElement: findLoadedMessageElement,
        nextTick: () => nextTick(),
        beforeScroll: () => {
          programmaticMessageScroll = true
        },
        afterScroll: () => {
          if (chatContainer.value) {
            expectedScrollTop = chatContainer.value.scrollTop
          }
          autoScroll.value = false
          stickToBottom = false
          programmaticMessageScroll = false
        },
        notifyMissing: () => {
          notifyInfo(t('messageRefs.notFound'))
        },
        isCancelled,
      })
    } finally {
      if (generation === messageFocusGeneration) suppressBottomScrollForFocus = false
    }
  })
  messageFocusQueue = run.then(
    () => undefined,
    () => undefined
  )
  return run
}

// [Message:ID] badges and `/?chat=&message=` both land here.
const handleOpenMessageReferenceEvent = (event: Event) => {
  const customEvent = event as CustomEvent<{ messageId: number; chatId: number }>
  const chatId = customEvent.detail?.chatId
  const messageId = customEvent.detail?.messageId
  if (!chatId || chatId <= 0 || !messageId || messageId <= 0) return
  void enqueueMessageFocus({ chatId, messageId })
}

watch(
  () => [route.query.chat, route.query.message] as const,
  () => {
    if (!assistantChatBootstrapped) return
    const target = parseOpenMessageQuery(route.query)
    if (!target) return
    void router.replace({
      query: { ...route.query, chat: undefined, message: undefined },
    })
    void enqueueMessageFocus(target)
  }
)

// Detach (do NOT cancel) a running turn when the user navigates away or
// switches chats (issues #1142 / #1223 / #1225). Closes the SSE connection and
// clears local streaming state WITHOUT telling the backend to stop — the turn
// finishes in the background and is restored by loadMessages() on return.
// Explicit cancellation stays in handleUserStop() (the Stop button).
function handleNavigateAway() {
  const runId = attachedRunId
  const chatId = generatingChatId
  finishStreamingTurnLocally()
  // The visible stream is gone, but the turn keeps running. Follow it quietly
  // so the sidebar dot and the generated title update when it finishes.
  if (!isGuestMode.value && !incognitoStore.active && runId && chatId) {
    chatsStore.watchDetachedRun(chatId, runId)
  }
}

/**
 * Remember the resumable run the server opened for the turn this view is
 * rendering, and mark its chat in the sidebar.
 *
 * The run id keeps a later history load from attaching a second time to a turn
 * this tab already renders. The sidebar mark is what makes leaving mid-answer
 * visible: the turn keeps generating, so the user needs to see which chat is
 * worth returning to.
 */
function noteRunStarted(runId: unknown) {
  if (typeof runId !== 'string' || runId === '') return

  attachedRunId = runId

  // Guests have no chat list to mark.
  if (isGuestMode.value) return

  // Tracked separately from currentStreamingChatId even though it starts from
  // it: navigate-away clears that one while the turn deliberately keeps running,
  // which is exactly when the marker has to stay.
  generatingChatId = currentStreamingChatId ?? chatsStore.activeChatId ?? null
  if (null !== generatingChatId) {
    chatsStore.markChatGenerating(generatingChatId, true)
  }
}

/**
 * The turn ended on the wire. Releasing the run id lets the chat be picked up
 * again later.
 *
 * A recoverable transport drop is NOT the end of the turn server-side — it keeps
 * generating for whoever comes back — so that case deliberately keeps the
 * sidebar marker, which is exactly what it exists to show.
 */
function noteRunTerminal(data: StreamUpdatePayload) {
  attachedRunId = null

  if (isRecoverableStreamError(data)) return

  clearGeneratingMark()
}

function clearGeneratingMark() {
  if (null !== generatingChatId) {
    chatsStore.markChatGenerating(generatingChatId, false)
    generatingChatId = null
  }
}

/**
 * Pick a still-generating turn back up after a reload, a chat switch, or a trip
 * to another view.
 *
 * The backend keeps a turn alive across the disconnect and buffers its events,
 * and the chat history response reports it as `activeRun`. Re-attaching replays
 * what was missed and then follows the live tail, so the answer keeps writing
 * itself instead of leaving the user on a bare prompt until it is persisted.
 */
async function resumeActiveRunIfAny() {
  // This view is about to render the turn itself. Drop the quiet watcher
  // first so the same run is not attached twice.
  if (chatsStore.activeChatId) {
    chatsStore.stopDetachedRunWatch(chatsStore.activeChatId)
  }
  const run = isGuestMode.value ? guestStore.activeRun : historyStore.activeRun
  if (!run) return

  // Already rendering this turn — either the tab that started it never left, or
  // a silent in-progress poll re-reported the same run. Attaching again would
  // produce a second bubble for one answer.
  if (attachedRunId === run.runId || isStreaming.value) return

  // A multitask turn is reported BOTH as `inProgressTurn` (#1142 synthesizes a
  // provisional card bubble) and as `activeRun`. The live re-attach renders the
  // same turn more completely, so drop the provisional one instead of showing
  // the answer twice. If the attach drops, finishStreamingMessage() clears the
  // live flag and the next in-progress poll restores it.
  historyStore.removeMessage(IN_PROGRESS_TURN_ID)

  // The turn's REAL track id. Inventing a fresh one would leave Stop unable to
  // flag the running turn — the answer would keep generating (and billing)
  // while the UI claimed it was cancelled.
  const runTrackId = Number.parseInt(run.trackId, 10)

  attachedRunId = run.runId
  await streamAIResponse('', {
    attach: {
      runId: run.runId,
      partialText: run.partialText,
      trackId: Number.isFinite(runTrackId) ? runTrackId : undefined,
    },
  })
}

// Cleanup: detach the running stream when the component unmounts (user leaves chat)
onBeforeUnmount(() => {
  isViewUnmounted = true
  mediaJobsStore.unsubscribe()
  stopContinuationListen()
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
  window.removeEventListener('open-first-run-setup', handleOpenFirstRunSetupEvent)
  window.removeEventListener('open-message-reference', handleOpenMessageReferenceEvent)
  for (const stop of messageFocusIdleStops) stop()
  messageFocusIdleStops.clear()
  if (stopShortcutListen) {
    stopShortcutListen()
    stopShortcutListen = null
  }
  window.removeEventListener('focus', prefetchSseToken)
  document.removeEventListener('visibilitychange', handleVisibilityChangeForToken)
  clearDeleteDialogTimer()
})

// Incognito session lifecycle: the toggle (MainLayout on mobile, floating
// button on desktop) only flips the store flag — this watcher swaps the
// transcript surface. The session transcript lives exclusively in the
// in-memory history store; the user's persisted chat is untouched and
// restored when the session ends.
watch(
  () => incognitoStore.active,
  async (active) => {
    const focusAtStart = messageFocusGeneration
    // Detach any running turn (same semantics as switching chats).
    if (isStreaming.value) {
      handleNavigateAway()
    }
    quoting.clearPendingQuote()
    historyStore.clear()
    historyLoadedChatId = null

    if (!active) {
      // Session ended: restore the persisted chat. No toast here — the UI
      // already reflects the change (toggle state + restored chat), so a
      // confirmation toast would just be noise.
      if (chatsStore.activeChatId) {
        await historyStore.loadMessages(chatsStore.activeChatId)
        if (chatsStore.activeChatId !== null) {
          historyLoadedChatId = chatsStore.activeChatId
        }
      }
    }

    await nextTick()
    if (!bottomScrollYieldsToFocus(focusAtStart)) {
      scrollToBottom(true)
    }
    setTimeout(() => {
      chatInputRef.value?.textareaRef?.focus()
    }, 100)
  }
)

// Watch for active chat changes and load messages
watch(
  () => chatsStore.activeChatId,
  async (newChatId, oldChatId) => {
    const focusAtStart = messageFocusGeneration
    // The first selection while the view boots (from no chat) is the list
    // arriving, not a pick. An incognito session started meanwhile keeps its
    // transcript; ending that session restores this chat.
    const bootSelectionUnderIncognito =
      oldChatId == null && !chatBootSettled && incognitoStore.active
    // Picking a chat from the sidebar while incognito is active ends the
    // session (the incognito transcript is meant to be discarded). The
    // incognito watcher above restores nothing here — this watcher loads the
    // newly selected chat below.
    if (oldChatId !== newChatId && incognitoStore.active && !bootSelectionUnderIncognito) {
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
      // Start chat loads this history itself and keeps Send locked until it
      // finishes; skipping the second load avoids wiping the first message.
      // A message deep link sets the skip so it can await its own load.
      if (oldChatId !== newChatId) {
        historyLoadedChatId = null
      }
      const skippedHistoryLoad = suppressNextChatHistoryLoad || bootSelectionUnderIncognito
      if (suppressNextChatHistoryLoad) {
        suppressNextChatHistoryLoad = false
      } else if (!bootSelectionUnderIncognito) {
        const done = historyStore.loadMessages(newChatId)
        watcherHistoryLoad = { chatId: newChatId, done }
        try {
          await done
        } finally {
          if (watcherHistoryLoad?.done === done) watcherHistoryLoad = null
        }
        if (chatsStore.activeChatId === newChatId) {
          historyLoadedChatId = newChatId
        }
      }

      // Coming back to a chat whose turn is still generating: keep watching it
      // live. A different chat means a different run, so the previous
      // attachment no longer applies. The message deep link resumes after its
      // own load; doing it here would attach to the previous transcript.
      attachedRunId = null
      if (!skippedHistoryLoad) {
        void resumeActiveRunIfAny()
      }

      // Usage taximeter: a chat switch resets the session (not the day totals)
      // and rebuilds it from the newly loaded history. A message deep link
      // loads that history itself and seeds after the load.
      if (!skippedHistoryLoad && usageTaximeterStore.active) {
        usageTaximeterStore.seedFromHistory(historyStore.messages)
      }

      await nextTick()
      if (!bottomScrollYieldsToFocus(focusAtStart)) {
        scrollToBottom(true)
      }

      // Auto-focus input when switching chats
      setTimeout(() => {
        if (chatInputRef.value?.textareaRef) {
          chatInputRef.value.textareaRef.focus()
        }
      }, 100)
    }
  }
)

watch(queryAgentId, async (id) => {
  if (!assistantChatBootstrapped) {
    return
  }
  if (!id || !authStore.isAuthenticated) {
    assistantThreadReady.value = true
    return
  }
  await openFreshAssistantChatIfNeeded()
})

const groupedMessages = computed(() => groupMessagesByDay(historyStore.messages, getDateLabel))

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
  if (!chatContainer.value || programmaticMessageScroll) return

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

let isViewUnmounted = false

function handleMediaJobCompleted(message: Message, payload: { url: string; type: string }): void {
  // Shared with the realtime mediaJobs store so the poll and push completion
  // paths produce an identical result (state → done + media part appended).
  applyMediaJobUpdateToMessage(message, {
    job_id: message.mediaJob?.jobId ?? '',
    type: payload.type,
    state: 'done',
    file: { url: payload.url, type: payload.type },
  })

  // Usage taximeter: an async media render is billed by the worker around job
  // completion — the poll can observe "done" moments before the billing row
  // and usage meta are committed. refreshAfterSettlement pulls fresh day
  // totals with a short bounded retry and re-reconciles the message so the
  // session model list picks up the render's model + cost without a reload.
  const backendMessageId = message.backendMessageId
  usageTaximeterStore.refreshAfterSettlement(
    backendMessageId
      ? () => {
          void historyStore.reconcileMessage(message.id, backendMessageId).then(() => {
            if (usageTaximeterStore.active) {
              usageTaximeterStore.seedFromHistory(historyStore.messages)
            }
          })
        }
      : undefined
  )

  if (!usageTaximeterStore.active && backendMessageId) {
    void historyStore.reconcileMessage(message.id, backendMessageId)
  }
}

/** Called from the smoother's frame and from the flush on 'complete'. */
function renderStreamingContent(content: string, msgId: string): void {
  const message = historyStore.messages.find((m) => m.id === msgId)
  if (message) paintStreamedAnswer(message, content, processingIndicator, translate)
}

/** Look deeper under a direct answer: start the research the chat skipped. */
const handleLookDeeper = (message: Message): void => {
  const input = lookDeeperInput(historyStore.messages, message.id)
  if (input) void researchStore.lookDeeper(input)
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
    } else if ((p.type === 'text' || p.type === 'json') && p.content) {
      fullContent += p.content
    }
  }

  const trackId = Date.now()
  const continueSmoother = createSmoothStream({
    onRender: (text) => renderStreamingContent(text, message.id),
  })

  const onUpdate = createContinueStreamHandler({
    message,
    text: { content: fullContent, thinkOpen: false },
    smoother: continueSmoother,
    history: historyStore,
    usage: usageTaximeterStore,
    renderContent: (content) => renderStreamingContent(content, message.id),
    showError: (text) => showErrorToast(text),
    t: translate,
  })
  const stopStreaming = chatApi.streamMessage({
    userId,
    message: '',
    chatId,
    trackId,
    language: locale.value,
    continueMessageId: message.backendMessageId,
    agentId: outgoingAgentId(),
    onUpdate,
  })

  stopStreamingFn = () => {
    continueSmoother.flush()
    continueSmoother.cancel()
    stopStreaming()
  }
}

interface PluginChatRoute {
  pluginName: string
  endpoint: string
}

/**
 * Match a "/command …" message against the slash-commands contributed by
 * installed plugins (manifest `chatCommands`, surfaced via runtime config).
 * Returns the owning plugin + endpoint, or null for non-plugin input.
 */
const matchPluginChatCommand = (content: string): PluginChatRoute | null => {
  const match = content.trim().match(/^\/([A-Za-z0-9_-]+)(?:\s|$)/)
  if (!match) {
    return null
  }
  const name = match[1].toLowerCase()
  const command = pluginCommands().find((c) => c.name.toLowerCase() === name)
  if (!command?.pluginName || !command.endpoint) {
    return null
  }
  return { pluginName: command.pluginName, endpoint: command.endpoint }
}

/**
 * Run a plugin slash-command by posting the message to the plugin's own chat
 * endpoint and rendering the reply locally. Deliberately does NOT go through
 * the AI message pipeline, so no classifier/characterization change is needed.
 */
const runPluginChatCommand = async (route: PluginChatRoute, text: string): Promise<void> => {
  const pinnedForTurn = captureOutgoingAgentId()
  historyStore.addMessage(
    'user',
    [{ type: 'text' as const, content: text }],
    undefined,
    undefined,
    undefined,
    undefined,
    undefined,
    undefined,
    null,
    null,
    null,
    null,
    pinnedForTurn
  )
  const userId = authStore.user?.id
  if (!userId) {
    return
  }
  const streamingId = historyStore.addStreamingMessage('assistant')
  try {
    const data = await httpClient<{ message?: string }>(
      `/api/v1/user/${userId}/plugins/${route.pluginName}${route.endpoint}`,
      {
        method: 'POST',
        body: JSON.stringify({ message: text, chatId: 'webchat', lang: i18n.global.locale.value }),
      }
    )
    historyStore.finishStreamingMessage(streamingId, [
      { type: 'text' as const, content: data?.message || t('plugins.commandEmpty') },
    ])
  } catch {
    historyStore.finishStreamingMessage(streamingId, [
      { type: 'text' as const, content: t('plugins.commandError') },
    ])
  }
}

const handleSendMessage = async (
  content: string,
  options?: {
    includeReasoning?: boolean
    reasoningEffort?: string
    webSearch?: boolean
    modelId?: number
    fileIds?: number[]
    voiceReply?: boolean
    ragGroupKey?: string
    quotedText?: string
    quotedMessageId?: number
    language?: string
    /** Assistant captured before the optimistic user message is appended. */
    agentId?: number | null
  }
) => {
  if (needsProviderSetup.value) {
    return
  }

  // Plugin slash-commands (e.g. "/fastbill show overdue") are handled by the
  // owning plugin, not the AI pipeline. Intercept before any other processing.
  const pluginRoute = matchPluginChatCommand(content)
  if (pluginRoute) {
    await runPluginChatCommand(pluginRoute, content.trim())
    return
  }

  // A blank page has no chat yet. Open one before the message appears.
  // The history load for that chat finishes first, so it cannot replace the
  // message that follows.
  if (!incognitoStore.active && !isGuestMode.value) {
    const previousId = chatsStore.activeChatId
    const chatId = await claimOwnedChatForSend({
      activeChatId: previousId,
      suppressHistoryLoad: () => {
        suppressNextChatHistoryLoad = true
      },
      releaseHistoryLoad: () => {
        suppressNextChatHistoryLoad = false
      },
      openOwnedChat: async () => {
        await chatsStore.findOrCreateEmptyChat()
      },
      readActiveChatId: () => chatsStore.activeChatId,
    })
    if (chatId == null) {
      showErrorToast(t('chat.sendNeedsChat'))
      return
    }
    if (previousId == null) {
      await historyStore.loadMessages(chatId)
      if (chatsStore.activeChatId !== chatId) {
        return
      }
      historyLoadedChatId = chatId
    }
  }

  autoScroll.value = true
  stickToBottom = false

  // Usage taximeter: count this user prompt for the session statistics.
  if (usageTaximeterStore.active) {
    usageTaximeterStore.registerPrompt()
  }

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
    const commandMatch = messageToSend.match(/^\/(\w+)\s+([\s\S]*)$/)
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
  const optimisticParts: Part[] = parseContentWithThinking(displayContent, 'user')
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

  // Capture before addMessage. The optimistic row has no assistant yet, and
  // resolvePinnedAgentId() treats any unstamped message as "this thread is
  // not an assistant chat", which drops the ?agentId= pin on the first send.
  const pinnedForTurn = options?.agentId ?? captureOutgoingAgentId()
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
    options?.quotedMessageId ?? null, // quotedMessageId
    pinnedForTurn
  )

  // Lift the active chat to the top of the sidebar lists right away so the
  // conversation the user is interacting with is the most prominent one,
  // matching the backend's `updatedAt DESC` order on the next reload.
  // Mirrors the backend preview format (30 chars + ellipsis).
  // Incognito: the turn belongs to no chat — never touch the sidebar.
  if (chatsStore.activeChatId && !incognitoStore.active) {
    const previewSource = stripPastedBlocks(displayContent).trim()
    const preview =
      previewSource.length > 30 ? previewSource.slice(0, 30) + '…' : previewSource || undefined
    chatsStore.bumpChatActivity(chatsStore.activeChatId, {
      firstMessagePreview: preview,
    })
  }

  // Notify promo tip system
  promoTips.onMessageSent()

  // Stream to backend - use backendContent which may differ from displayContent
  await streamAIResponse(backendContent, { ...options, agentId: pinnedForTurn })
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

/**
 * The stream starts and returns before the server has saved the new answer.
 * Linking immediately used the previous answer's id and rejected the request
 * ("An answer cannot be a version of itself"), which crashed the chat.
 * Call this from the complete/error handler, after that id exists.
 */
async function reloadEditedTranscript(editedChatId: number | undefined): Promise<void> {
  if (
    editedChatId == null ||
    !shouldReloadTranscriptAfterVersionLink({
      kind: 'edit',
      incognito: incognitoStore.active,
      editedChatId,
      activeChatId: chatsStore.activeChatId,
    })
  ) {
    return
  }
  await historyStore.loadMessages(editedChatId)
}

function recordVersionLink(
  link: { kind: 'again' | 'edit'; previousId: number } | undefined,
  createdId: number | undefined,
  editedChatId: number | undefined
): void {
  if (!link || !createdId || createdId === link.previousId) return
  void (async () => {
    try {
      // Again must not reload: a reload while the next Again is opening
      // replaces that bubble and closes its model list. An edit reloads
      // after the link so the open transcript matches the saved question.
      // A chat switch during the stream must not load this chat over the
      // one the person is looking at now. A failed link reloads too, so
      // the optimistic wording does not stay when the server kept the
      // previous question.
      await chatApi.linkTurn(createdId, link.kind, link.previousId)
      if (link.kind === 'edit') await reloadEditedTranscript(editedChatId)
    } catch (err) {
      console.error('Failed to record the answer version', err)
      showErrorToast(t('chat.versionLinkFailed'))
      if (link.kind === 'edit') await reloadEditedTranscript(editedChatId)
    }
  })()
}

interface StreamTurnOptions {
  includeReasoning?: boolean
  reasoningEffort?: string
  webSearch?: boolean
  modelId?: number
  fileIds?: number[]
  voiceReply?: boolean
  isAgain?: boolean
  /** Record this answer as a version once the server has saved it. */
  linkVersion?: { kind: 'again' | 'edit'; previousId: number }
  ragGroupKey?: string
  quotedText?: string
  quotedMessageId?: number
  language?: string
  /** Assistant for this turn, captured before the optimistic user row exists. */
  agentId?: number | null
  /**
   * Re-attach to a turn already generating on the server instead of starting
   * a new one. `userMessage` is then irrelevant — nothing is sent, the client
   * only subscribes to the turn's buffered events. `trackId` is the turn's
   * existing id, so an explicit Stop can still cancel it server-side.
   */
  attach?: { runId: string; partialText: string; trackId?: number }
}

/** The answer bubble a turn streams into, and the painter that keeps it from shrinking. */
interface StreamCanvas {
  messageId: string
  signal: AbortSignal
  smoother: SmoothStream
  fallbackModel: StreamModelFallback
  render: (text: string) => void
}

const translate = (key: string, params?: Record<string, unknown>): string =>
  params ? t(key, params) : t(key)

const processingIndicator: ProcessingIndicator = {
  set: (status, metadata = {}) => {
    processingStatus.value = status
    processingMetadata.value = metadata
  },
  clear: () => {
    processingStatus.value = ''
    processingMetadata.value = {}
  },
  current: () => processingStatus.value,
  metadata: () => processingMetadata.value,
}

/** The turn is over for this view: Stop has nothing left to cancel. */
function releaseStreamingTurn(): void {
  streamingAbortController = null
  stopStreamingFn = null
  currentTrackId = undefined
  currentStreamingChatId = undefined
}

const streamRun: StreamRun = {
  ingestTimeline,
  noteStarted: noteRunStarted,
  noteTerminal: noteRunTerminal,
  trackId: () => currentTrackId,
  release: releaseStreamingTurn,
}

/**
 * Re-attaching paints the answer-so-far right away, and the bubble never
 * shrinks: the replay restarts from the first chunk, far shorter than the
 * text on screen, so the painted prefix holds until the replay grows past it.
 * Stop keeps every character that already arrived.
 */
function streamCanvasFor(
  messageId: string,
  paintedPrefix: string,
  signal: AbortSignal,
  fallbackModel: StreamModelFallback
): StreamCanvas {
  if (paintedPrefix) {
    historyStore.updateStreamingMessage(messageId, paintedPrefix)
  }
  const render = (text: string) =>
    renderStreamingContent(text.length >= paintedPrefix.length ? text : paintedPrefix, messageId)
  const smoother = createSmoothStream({ onRender: render, initialShown: paintedPrefix })
  signal.addEventListener('abort', () => {
    smoother.flush()
    smoother.cancel()
  })

  return { messageId, signal, smoother, fallbackModel, render }
}

function streamTurnFor(canvas: StreamCanvas): StreamTurn {
  return {
    messageId: canvas.messageId,
    text: { content: '', thinkOpen: false },
    smoother: canvas.smoother,
    fallbackModel: canvas.fallbackModel,
    status: processingIndicator,
    history: historyStore,
    run: streamRun,
    usage: usageTaximeterStore,
    message: () => historyStore.messages.find((m) => m.id === canvas.messageId),
    isAborted: () => streamingAbortController?.signal.aborted === true,
    render: canvas.render,
    renderContent: (content) => renderStreamingContent(content, canvas.messageId),
    ingestApprovalRequired,
    scrollToBottom,
    t: translate,
  }
}

function guestTurnFor(canvas: StreamCanvas): GuestTurn {
  return {
    ...streamTurnFor(canvas),
    guest: {
      updateCount: (remaining, max, reached) => guestStore.updateCount(remaining, max, reached),
      showBanner: () => guestStore.showBanner(),
      offerSignup: () => {
        // Without a purchase channel the upgrade sheet is not eligible.
        if (!openPaywall('guest_limit')) {
          showGuestSignupModal.value = true
        }
      },
    },
  }
}

function memberTurnFor(
  canvas: StreamCanvas,
  turn: {
    chatId: number | undefined
    incognito: boolean
    linkVersion: StreamTurnOptions['linkVersion']
    voice: VoiceReplyStream
  }
): MemberTurn {
  const { chatId, linkVersion } = turn
  const busyCurrent = busyGuard(chatId, turn.incognito)
  return {
    ...streamTurnFor(canvas),
    chatId,
    incognito: turn.incognito,
    voice: turn.voice,
    memory: {
      memories: memoriesStore,
      feedbacks: feedbackStore,
      digests: messageDigestsStore,
      suggest: (memory) =>
        activeMemoryToasts.value.push({ ...memory, toastId: memoryToastIdCounter++ }),
      offerDelete: (memory) =>
        openMemoryDeleteDialog({ ...memory, toastId: memoryToastIdCounter++ }),
    },
    chats: {
      applyChatTitle: (id, title) => chatsStore.applyChatTitle(id, title),
      bumpChatActivity: (id) => chatsStore.bumpChatActivity(id),
      clearLocalTurnFinished: (id) => chatsStore.clearLocalTurnFinished(id),
      streamingChatId: () => currentStreamingChatId,
    },
    outcome: {
      recordVersion: (backendMessageId) => recordVersionLink(linkVersion, backendMessageId, chatId),
      restoreUnpersistedEdit: () => {
        if (linkVersion?.kind === 'edit') void reloadEditedTranscript(chatId)
      },
      busyRefused: (text) => refuseBusyTurn(text, translate, chatInputRef.value, busyCurrent),
      scheduleMemoryPoll,
      showError: (message, durationMs) => showErrorToast(message, durationMs),
      showLimit: (notice) => showLimitOrPaywall(notice),
      userLevel: () => authStore.user?.level || 'NEW',
    },
    research: {
      adopt: (runId) => {
        const target = chatId ?? currentStreamingChatId
        if (target === undefined) return
        researchStore.adopt(target, runId, userTextBefore(historyStore.messages, canvas.messageId))
      },
    },
    registerIncognitoFile: (fileId) => incognitoStore.registerFile(fileId),
  }
}

/** A spoken answer: barge-in listens while it plays, a read-aloud failure is shown on the answer. */
function startVoiceReply(messageId: string): void {
  currentAudioStreamer = new AudioStreamer()
  isAudioStreaming.value = true
  currentAudioStreamer.setOnPlaybackStart(() => {
    stopBargeIn?.()
    stopBargeIn = startBargeInMonitor(() => {
      stopBargeIn?.()
      stopBargeIn = null
      currentAudioStreamer?.stop()
      void chatInputRef.value?.startDictation()
    })
  })
  currentAudioStreamer.setOnFinished(() => {
    stopBargeIn?.()
    stopBargeIn = null
    isAudioStreaming.value = false
    currentAudioStreamer = null
  })
  currentAudioStreamer.setOnFailure(() => {
    const message = historyStore.messages.find((m) => m.id === messageId)
    if (message) {
      applyReadAloudFailed(message)
    }
    isAudioStreaming.value = false
    currentAudioStreamer = null
  })
}

async function streamMockAnswer(messageId: string): Promise<void> {
  const mockResponse =
    'This is a mock response for development. The actual streaming is handled by the backend API.'
  for (let i = 0; i < mockResponse.length; i += 3) {
    if (streamingAbortController?.signal.aborted) break
    historyStore.updateStreamingMessage(messageId, mockResponse.slice(0, i + 3))
    await new Promise((resolve) => setTimeout(resolve, 30))
  }
  historyStore.finishStreamingMessage(messageId)
}

async function startGuestTurn(
  userMessage: string,
  options: StreamTurnOptions | undefined,
  canvas: StreamCanvas
): Promise<void> {
  const guestChatId = await guestStore.ensureChat()
  if (!guestChatId || !guestStore.sessionId) {
    console.error('Guest chat or session not available')
    historyStore.finishStreamingMessage(canvas.messageId)
    return
  }

  const attach = options?.attach
  // A re-attach adopts the running turn's id; only a NEW turn mints one.
  const trackId = attach?.trackId ?? Date.now()
  currentTrackId = trackId
  processingIndicator.set('started')

  // One handler for both transports: a turn picked up after a reload renders through identical logic.
  const onUpdate = createGuestStreamHandler(guestTurnFor(canvas))
  stopStreamingFn = attach
    ? chatApi.attachStream({ runId: attach.runId, guestSessionId: guestStore.sessionId, onUpdate })
    : chatApi.streamGuestMessage({
        guestSessionId: guestStore.sessionId,
        message: userMessage,
        chatId: guestChatId,
        trackId,
        quotedText: options?.quotedText,
        quotedMessageId: options?.quotedMessageId,
        onUpdate,
      })
}

/** Incognito turns belong to no chat: the backend gets the context from the history payload. */
function memberStreamRequest(
  userMessage: string,
  options: StreamTurnOptions | undefined,
  turn: { trackId: number; chatId: number | undefined; incognito: boolean }
) {
  const { incognito } = turn
  return {
    userId: authStore.user?.id || 1,
    message: userMessage,
    trackId: turn.trackId,
    chatId: turn.chatId,
    incognito,
    history: incognito ? buildIncognitoHistorySnapshot() : [],
    disableMemories: incognito && !incognitoStore.useSavedContext,
    disableKnowledge: incognito && !incognitoStore.useSavedContext,
    includeReasoning: options?.includeReasoning ?? false,
    reasoningEffort: options?.reasoningEffort,
    webSearch: options?.webSearch ?? false,
    language: options?.language ?? locale.value,
    // Only an explicit model (Again): otherwise the backend classifies the turn.
    modelId: options?.modelId,
    fileIds: options?.fileIds || [],
    voiceReply: options?.voiceReply,
    isAgain: options?.isAgain,
    ragGroupKey: options?.ragGroupKey,
    quotedText: options?.quotedText,
    quotedMessageId: options?.quotedMessageId,
    agentId: outgoingAgentId(options?.agentId),
  }
}

function startMemberTurn(
  userMessage: string,
  options: StreamTurnOptions | undefined,
  canvas: StreamCanvas,
  chatId: number | undefined
): void {
  const incognito = incognitoStore.active
  const attach = options?.attach
  // A re-attach adopts the running turn's id; only a NEW turn mints one.
  const trackId = attach?.trackId ?? Date.now()
  currentTrackId = trackId
  currentStreamingChatId = chatId ?? undefined

  if (currentAudioStreamer) {
    currentAudioStreamer.stop()
    currentAudioStreamer = null
  }
  if (options?.voiceReply) {
    startVoiceReply(canvas.messageId)
  }
  // The UI locale picks the voice until the classifier names the reply language (#2283).
  const voice = new VoiceReplyStream(
    () => currentAudioStreamer,
    () => {
      currentAudioStreamer = null
      isAudioStreaming.value = false
    },
    locale.value || 'en'
  )

  // One handler for both transports: a turn picked up after a reload renders through identical logic.
  const onUpdate = createMemberStreamHandler(
    memberTurnFor(canvas, { chatId, incognito, linkVersion: options?.linkVersion, voice })
  )
  const stopStreaming = attach
    ? chatApi.attachStream({ runId: attach.runId, onUpdate })
    : chatApi.streamMessage({
        ...memberStreamRequest(userMessage, options, { trackId, chatId, incognito }),
        onUpdate,
      })
  stopStreamingFn = stopStreaming
  canvas.signal.addEventListener('abort', () => {
    stopStreaming()
    stopStreamingFn = null
  })
}

const streamAIResponse = async (userMessage: string, options?: StreamTurnOptions) => {
  streamingAbortController = new AbortController()
  processingTimeline.value = createTimelineState()

  const currentModel =
    aiConfigStore.models.CHAT?.find((model) => model.id === options?.modelId) ??
    aiConfigStore.getCurrentModel('CHAT')
  const provider = currentModel?.service || modelsStore.selectedProvider
  const modelLabel = currentModel?.name || modelsStore.selectedModel

  // No owned chat: stop before the placeholder. Leaving it here spins forever,
  // because the request below returns without finishing the bubble.
  if (!isGuestMode.value && !incognitoStore.active && chatsStore.activeChatId == null) {
    console.error('No active chat selected')
    return
  }

  const messageId = historyStore.addStreamingMessage('assistant', provider, modelLabel)
  const canvas = streamCanvasFor(
    messageId,
    options?.attach?.partialText ?? '',
    streamingAbortController.signal,
    { provider, model: modelLabel, model_id: currentModel?.id ?? null }
  )

  let editedChatIdForRestore: number | undefined
  try {
    if (useMockData) {
      await streamMockAnswer(messageId)
    } else if (isGuestMode.value) {
      await startGuestTurn(userMessage, options, canvas)
    } else {
      editedChatIdForRestore = incognitoStore.active
        ? undefined
        : (chatsStore.activeChatId ?? undefined)
      startMemberTurn(userMessage, options, canvas, editedChatIdForRestore)
    }
  } catch (error) {
    console.error('❌ Streaming error:', error)
    canvas.smoother.cancel()
    historyStore.updateStreamingMessage(messageId, t('chatError.reason.unknown'))
    historyStore.finishStreamingMessage(messageId)
    if (currentStreamingChatId) {
      chatsStore.clearLocalTurnFinished(currentStreamingChatId)
    }
    releaseStreamingTurn()
    if (options?.linkVersion?.kind === 'edit') void reloadEditedTranscript(editedChatIdForRestore)
  }
  // No cleanup here: the stream is still running. Its terminal event or handleUserStop() ends it.
}

// Explicit Stop button (issue #1225): the user WANTS to cancel the turn. Tell
// the backend to stop (/stop-stream flags the turn via CancellationStore) and
// persist the partial answer as cancelled (/save-cancelled). This is distinct
// from navigating away, which detaches WITHOUT cancelling (handleNavigateAway).
const handleUserStop = async () => {
  if (!canRewriteConversation.value) return
  // CRITICAL: Abort signal FIRST to prevent any further chunk processing
  if (streamingAbortController) {
    streamingAbortController.abort()
  }

  // Unlike navigating away, an explicit Stop really does end the turn.
  attachedRunId = null
  clearGeneratingMark()

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
      await chatApi.stopStream(effectiveTrackId, currentStreamingChatId ?? chatsStore.activeChatId)
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

  // #732: cancelled turns leave local history non-empty while chat list
  // metadata can still look empty — bump activity so "New chat" won't reuse
  // this thread via isChatEmpty().
  const bumpChatId = currentStreamingChatId ?? chatsStore.activeChatId
  if (streamingMessage && bumpChatId) {
    chatsStore.clearLocalTurnFinished(bumpChatId)
    chatsStore.bumpChatActivity(bumpChatId, {
      incrementMessageCount: true,
      firstMessagePreview: streamingMessage
        ? (streamingMessage.parts?.find((p) => p.type === 'text')?.content ?? '').slice(0, 120) ||
          undefined
        : undefined,
    })
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
  if (!canRewriteConversation.value) return
  if (!authStore.isAuthenticated) {
    console.error('❌ Not authenticated - redirecting to login')
    const { error } = useNotification()
    error(t('auth.sessionExpired'))
    await router.push({ name: 'login', query: { reason: 'session_expired' } })
    return
  }

  const assistantMessage =
    backendMessageId > 0
      ? historyStore.messages.find(
          (m) => m.backendMessageId === backendMessageId && m.role === 'assistant'
        )
      : (historyStore.messages.find((m) => m.id === IN_PROGRESS_TURN_ID) ??
        [...historyStore.messages].reverse().find((m) => m.role === 'assistant' && !!m.errorReason))

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

  const fileIds = (userMessage.files ?? [])
    .map((file) => file.id)
    .filter((id) => Number.isFinite(id) && id > 0)

  // Stream new response directly without creating a duplicate user message.
  //
  // With a model pick: `isAgain` skips classification and routes straight to
  // the picked model (single-prompt "Again with…").
  //
  // Without a model pick (multitask "Again"): stream WITHOUT `isAgain` so the
  // backend re-classifies (`source: ai_sorting`) and the planner can build a
  // fresh DAG — `isAgain` without a model would silently degrade the turn to
  // the single-node legacy path.
  //
  // Reattach the original file IDs so a file_analysis Again still has the
  // attachment to analyze (issue #1910). The version link waits for the
  // saved message id — streamAIResponse returns while the stream is still open.
  const previousId = assistantMessage.backendMessageId
  await streamAIResponse(userText, {
    ...(modelId ? { modelId, isAgain: true, fileIds } : { fileIds }),
    linkVersion: previousId ? { kind: 'again', previousId } : undefined,
  })
}

/**
 * Retry one failed task-plan step with another model. Streams the step's
 * resolved prompt through the Again path (`isAgain` + modelId): the backend
 * maps the model tag to the matching media topic (e.g. TEXT2PIC → tools:pic),
 * so only that sub-task re-runs. The result arrives as a new assistant bubble;
 * the original turn (with its successful parts) is left untouched.
 */
const handleTaskFollowup = async (prompt: string) => {
  if (!canRewriteConversation.value) return
  if (!authStore.isAuthenticated || isGuestMode.value) return
  if (!prompt.trim()) return
  const planMessage =
    historyStore.messages.find((m) => m.isStreaming && m.taskPlan) ??
    [...historyStore.messages].reverse().find((m) => m.taskPlan)
  const messageIndex = planMessage ? historyStore.messages.indexOf(planMessage) : -1
  const userMessage =
    messageIndex >= 0 ? findPrecedingUserMessage(historyStore.messages, messageIndex) : null
  const fileIds = (userMessage?.files ?? [])
    .map((file) => file.id)
    .filter((id) => Number.isFinite(id) && id > 0)
  await streamAIResponse(prompt.trim(), { fileIds })
}

const handleTaskRetry = async (payload: { prompt: string; modelId: number }) => {
  if (!canRewriteConversation.value) return
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
  if (!canRewriteConversation.value) return
  // Resolve the CURRENT turn's task-plan message via the streaming flag, mirroring
  // finishStreamingTurnLocally(). Node ids repeat across turns ("n1", "n2", …), so
  // finding the FIRST active plan could match a stale earlier turn and cancel the
  // wrong card / send the wrong trackId. The streaming message is the unambiguous
  // active turn; fall back to the active-plan lookup only if none is currently
  // streaming.
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
      await chatApi.cancelTask(trackId, nodeId, currentStreamingChatId ?? chatsStore.activeChatId)
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
  if (currentStreamingChatId) {
    chatsStore.clearLocalTurnFinished(currentStreamingChatId)
  }

  streamingAbortController = null
  currentTrackId = undefined
  currentStreamingChatId = undefined
}

async function answerAskUser(
  message: Message,
  payload: { nodeId: string; answer: string; skip: boolean }
): Promise<void> {
  if (!message.backendMessageId) return
  try {
    await chatApi.answerAskUser(
      message.backendMessageId,
      payload.nodeId,
      payload.answer,
      payload.skip
    )
    const chatId = chatsStore.activeChatId
    if (chatId) await historyStore.loadMessages(chatId)
  } catch (err) {
    showErrorToast(err instanceof Error ? err.message : t('taskPlan.askFailed'))
  }
}

async function selectMessageVersion(messageId: number, kind: 'answer' | 'edit'): Promise<void> {
  await chatApi.selectVersion(messageId, kind)
  const chatId = chatsStore.activeChatId
  if (chatId) await historyStore.loadMessages(chatId)
}

async function editUserMessage(message: Message, text: string): Promise<void> {
  const previousId = message.backendMessageId
  if (!isGuestMode.value && !incognitoStore.active && chatsStore.activeChatId == null) return
  const fileIds = attachmentIdsForEdit(message.files)
  historyStore.applySavedEdit(message.id, text)
  await streamAIResponse(text, {
    ...(fileIds.length > 0 ? { fileIds } : {}),
    linkVersion: previousId ? { kind: 'edit', previousId } : undefined,
  })
}

const handleRegenerate = async (message: Message, modelOption: ModelOption) => {
  if (!canRewriteConversation.value) return
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
  if (!canRewriteConversation.value) return
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

// Opens the Memories page; browser Back returns to this chat.
function handleClickMemory(memory: UserMemory) {
  router.push({ path: '/memories', query: { highlight: String(memory.id) } })
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

function openReportModal(messageId: number) {
  reportMessageId.value = messageId
  reportModalOpen.value = true
}

function closeReportModal() {
  reportModalOpen.value = false
  reportMessageId.value = null
}

async function submitReport(data: { reason: ReportReason; details: string }) {
  if (reportMessageId.value === null) {
    return
  }
  reportSubmitting.value = true
  try {
    await submitContentReport({
      contentType: 'message',
      contentId: reportMessageId.value,
      reason: data.reason,
      details: data.details || null,
    })
    closeReportModal()
    showSuccessToast(t('moderation.report.success'))
  } catch (err: unknown) {
    const errorMsg = err instanceof Error ? err.message : t('moderation.report.error')
    showErrorToast(errorMsg)
  } finally {
    reportSubmitting.value = false
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
