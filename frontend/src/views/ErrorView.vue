<template>
  <div class="min-h-screen flex items-center justify-center bg-app px-6" data-testid="page-error">
    <div class="max-w-2xl w-full text-center" data-testid="section-card">
      <div class="mb-8 relative" data-testid="section-icon">
        <div
          class="inline-flex items-center justify-center w-32 h-32 rounded-full bg-gradient-to-br from-red-500/10 to-orange-500/10 relative"
        >
          <div
            class="absolute inset-0 rounded-full bg-gradient-to-br from-red-500/20 to-orange-500/20 animate-ping"
          ></div>
          <ExclamationTriangleIcon class="w-16 h-16 text-red-500 relative z-10" />
        </div>
      </div>

      <div class="space-y-4 mb-8" data-testid="section-content">
        <h1 class="text-4xl md:text-5xl font-bold txt-primary">
          {{ effectiveError.title }}
        </h1>
        <p class="text-lg txt-secondary max-w-lg mx-auto">
          {{ effectiveError.description }}
        </p>
      </div>

      <!--
        ADMIN-ONLY DETAILS PANEL
        Mounted exclusively for admin users so end-users never see internal
        messages, stack traces or status codes — even when the underlying
        payload contains them.

        While impersonating, the CURRENT principal is the impersonated user
        (typically not an admin), but the actual operator IS an admin and
        explicitly needs the full failure context. We therefore unlock the
        panel for either condition.
      -->
      <div
        v-if="canSeeDebugDetails && hasAdminDetails"
        class="surface-card p-6 rounded-xl mb-8 text-left"
        data-testid="section-error-details"
      >
        <div class="flex items-start gap-3 mb-4">
          <CodeBracketIcon class="w-5 h-5 txt-secondary flex-shrink-0 mt-0.5" />
          <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
              <h3 class="text-sm font-semibold txt-primary">
                {{ $t('error.details') }}
              </h3>
              <span
                class="text-[10px] uppercase font-bold px-2 py-0.5 rounded bg-red-500/10 text-red-600 dark:text-red-400 tracking-wider"
                data-testid="badge-admin-only"
              >
                {{ $t('error.adminOnly') }}
              </span>
            </div>
            <div class="space-y-2">
              <div
                v-if="props.error?.message"
                class="text-sm txt-secondary font-mono bg-black/5 dark:bg-white/5 p-3 rounded break-words"
                data-testid="text-error-message"
              >
                {{ props.error.message }}
              </div>
              <div
                v-if="props.error?.statusCode"
                class="text-xs txt-secondary"
                data-testid="text-error-status"
              >
                <span class="font-semibold">{{ $t('error.statusCode') }}:</span>
                {{ props.error.statusCode }}
              </div>
              <div
                v-if="props.error?.source"
                class="text-xs txt-secondary"
                data-testid="text-error-source"
              >
                <span class="font-semibold">{{ $t('error.source') }}:</span>
                {{ props.error.source }}
              </div>
              <div
                v-if="props.error?.reason"
                class="text-xs txt-secondary"
                data-testid="text-error-reason"
              >
                <span class="font-semibold">{{ $t('error.reason') }}:</span>
                {{ props.error.reason }}
              </div>
            </div>
          </div>
        </div>

        <button
          v-if="props.error?.stack"
          class="text-xs txt-secondary hover:txt-primary flex items-center gap-1 transition-colors"
          data-testid="btn-toggle-stack"
          @click="stackExpanded = !stackExpanded"
        >
          <ChevronRightIcon
            :class="['w-4 h-4 transition-transform', stackExpanded && 'rotate-90']"
          />
          {{ stackExpanded ? $t('error.hideStack') : $t('error.showStack') }}
        </button>

        <Transition
          enter-active-class="transition-all duration-200 ease-out"
          enter-from-class="max-h-0 opacity-0"
          enter-to-class="max-h-[400px] opacity-100"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="max-h-[400px] opacity-100"
          leave-to-class="max-h-0 opacity-0"
        >
          <div v-if="stackExpanded && props.error?.stack" class="mt-3 overflow-hidden">
            <pre
              class="text-xs txt-secondary font-mono bg-black/5 dark:bg-white/5 p-3 rounded overflow-x-auto max-h-[300px] overflow-y-auto scroll-thin"
              data-testid="text-error-stack"
              >{{ props.error.stack }}</pre
            >
          </div>
        </Transition>
      </div>

      <div
        class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-8"
        data-testid="section-actions"
      >
        <button
          class="btn-primary px-8 py-3 rounded-lg font-semibold flex items-center gap-2 min-w-[200px] justify-center"
          data-testid="btn-retry"
          @click="handleRetry"
        >
          <ArrowPathIcon class="w-5 h-5" />
          {{ $t('error.retry') }}
        </button>
        <button
          class="px-8 py-3 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors font-semibold flex items-center gap-2 min-w-[200px] justify-center"
          data-testid="btn-home"
          @click="handleGoHome"
        >
          <HomeIcon class="w-5 h-5" />
          {{ $t('error.goHome') }}
        </button>
      </div>

      <div class="surface-card p-6 rounded-xl" data-testid="section-support">
        <div class="flex items-start gap-4">
          <div
            class="w-10 h-10 rounded-full bg-blue-500/10 flex items-center justify-center flex-shrink-0"
          >
            <ChatBubbleLeftRightIcon class="w-5 h-5 text-blue-500" />
          </div>
          <div class="text-left flex-1">
            <h3 class="text-sm font-semibold txt-primary mb-1">
              {{ $t('error.needHelp') }}
            </h3>
            <p class="text-sm txt-secondary mb-3">
              {{ $t('error.contactSupport') }}
            </p>
            <button
              class="text-sm font-medium hover:text-[var(--brand)] transition-colors flex items-center gap-2"
              style="color: var(--brand)"
              data-testid="btn-copy-error"
              @click="copyErrorInfo"
            >
              <ClipboardDocumentIcon class="w-4 h-4" />
              {{ copied ? $t('error.copied') : $t('error.copyError') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ArrowPathIcon,
  ChatBubbleLeftRightIcon,
  ChevronRightIcon,
  ClipboardDocumentIcon,
  CodeBracketIcon,
  ExclamationTriangleIcon,
  HomeIcon,
} from '@heroicons/vue/24/outline'
import { useAuth } from '@/composables/useAuth'
import { APP_NAME } from '@/router'
import type { GlobalErrorPayload, GlobalErrorReason } from '@/stores/globalError'

interface Props {
  /**
   * Full error payload. Sensitive fields (`message`, `stack`, `statusCode`,
   * `reason`, `source`) are only rendered for admin users — see template.
   */
  error?: GlobalErrorPayload
  /**
   * Called when the user clicks "Retry". Defaults to `window.location.reload()`.
   * Pass a callback (e.g. `globalError.clear`) when ErrorView is used inline so
   * the host can recover without a full page reload.
   */
  onRetry?: () => void
  /**
   * Called when the user clicks "Go to Home". Defaults to `router.push('/')`.
   * Same rationale as `onRetry`.
   */
  onGoHome?: () => void
}

const props = withDefaults(defineProps<Props>(), {
  error: undefined,
  onRetry: undefined,
  onGoHome: undefined,
})

const { t } = useI18n()
const { isAdmin, isImpersonating } = useAuth()
const router = useRouter()

/**
 * Admins always see the full diagnostic payload. Impersonating admins do too,
 * even though the CURRENT principal (the impersonated user) is not an admin —
 * otherwise the operator would lose visibility into errors triggered while
 * acting as someone else, defeating the point of impersonation.
 */
const canSeeDebugDetails = computed(() => isAdmin.value || isImpersonating.value)

const stackExpanded = ref(false)
const copied = ref(false)
const previousTitle = ref<string>('')

/**
 * Map "infrastructure" errors raised by router guards or auth bootstrap to a
 * user-friendly heading + description. End users see the generic copy from
 * `error.title` / `error.description`; admins additionally get the raw payload
 * in the details panel below.
 */
const REASON_COPY: Record<GlobalErrorReason, { titleKey: string; descriptionKey: string } | null> =
  {
    redirect_loop: {
      titleKey: 'error.reasons.redirectLoop.title',
      descriptionKey: 'error.reasons.redirectLoop.description',
    },
    auth_timeout: {
      titleKey: 'error.reasons.authTimeout.title',
      descriptionKey: 'error.reasons.authTimeout.description',
    },
    router_navigation: null,
    unknown: null,
  }

const effectiveError = computed(() => {
  const reason = props.error?.reason
  const reasonCopy = reason ? REASON_COPY[reason] : null
  return {
    title: reasonCopy ? t(reasonCopy.titleKey) : t('error.title'),
    description: reasonCopy ? t(reasonCopy.descriptionKey) : t('error.description'),
  }
})

const hasAdminDetails = computed(() =>
  Boolean(
    props.error?.message ||
    props.error?.stack ||
    props.error?.statusCode ||
    props.error?.source ||
    props.error?.reason
  )
)

const handleRetry = () => {
  if (props.onRetry) {
    props.onRetry()
    return
  }
  window.location.reload()
}

const handleGoHome = () => {
  if (props.onGoHome) {
    props.onGoHome()
    return
  }
  router.push('/')
}

const copyErrorInfo = async () => {
  // End users get a redacted payload — they should never receive the raw stack
  // or internal status code via the clipboard either, since they typically
  // forward it directly to support channels. Impersonating admins are treated
  // like admins (see canSeeDebugDetails for the rationale).
  const payload = canSeeDebugDetails.value
    ? {
        message: props.error?.message ?? 'Unknown error',
        statusCode: props.error?.statusCode ?? null,
        reason: props.error?.reason ?? null,
        source: props.error?.source ?? null,
        stack: props.error?.stack ?? null,
        timestamp: new Date().toISOString(),
        userAgent: navigator.userAgent,
        url: window.location.href,
      }
    : {
        timestamp: new Date().toISOString(),
        url: window.location.href,
        userAgent: navigator.userAgent,
      }

  try {
    await navigator.clipboard.writeText(JSON.stringify(payload, null, 2))
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy error info to clipboard:', err)
  }
}

// Mirror what the router's afterEach() would do for the (now removed) /error
// route: when ErrorView is mounted (e.g. via ErrorBoundary), keep the document
// title in sync so browser tabs and screen-readers reflect the error state,
// then restore the original title on unmount so the host route's title comes
// back transparently.
onMounted(() => {
  previousTitle.value = document.title
  document.title = `${t('pageTitles.error')} | ${APP_NAME}`
})

onBeforeUnmount(() => {
  if (previousTitle.value) {
    document.title = previousTitle.value
  }
})
</script>
