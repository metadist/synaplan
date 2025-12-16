<template>
  <div class="min-h-screen flex items-center justify-center bg-app px-6" data-testid="page-error">
    <div class="max-w-2xl w-full text-center" data-testid="section-card">
      <!-- Error Icon -->
      <div class="mb-8 relative" data-testid="section-icon">
        <div class="inline-flex items-center justify-center w-32 h-32 rounded-full bg-gradient-to-br from-red-500/10 to-orange-500/10 relative">
          <div class="absolute inset-0 rounded-full bg-gradient-to-br from-red-500/20 to-orange-500/20 animate-ping"></div>
          <ExclamationTriangleIcon class="w-16 h-16 text-red-500 relative z-10" />
        </div>
      </div>

      <!-- Content -->
      <div class="space-y-4 mb-8" data-testid="section-content">
        <h1 class="text-4xl md:text-5xl font-bold txt-primary">
          {{ $t('error.title') }}
        </h1>
        <p class="text-lg txt-secondary max-w-lg mx-auto">
          {{ $t('error.description') }}
        </p>
      </div>

      <!-- Error Details (if provided) -->
      <div v-if="effectiveError" class="surface-card p-6 rounded-xl mb-8 text-left" data-testid="section-error-details">
        <div class="flex items-start gap-3 mb-4">
          <CodeBracketIcon class="w-5 h-5 txt-secondary flex-shrink-0 mt-0.5" />
          <div class="flex-1">
            <h3 class="text-sm font-semibold txt-primary mb-2">
              {{ $t('error.details') }}
            </h3>
            <div class="space-y-2">
              <div v-if="effectiveError.message" class="text-sm txt-secondary font-mono bg-black/5 dark:bg-white/5 p-3 rounded">
                {{ effectiveError.message }}
              </div>
              <div v-if="effectiveError.statusCode" class="text-xs txt-secondary">
                <span class="font-semibold">{{ $t('error.statusCode') }}:</span> {{ effectiveError.statusCode }}
              </div>
            </div>
          </div>
        </div>

        <button
          v-if="showStack && props.error?.stack"
          @click="stackExpanded = !stackExpanded"
          class="text-xs txt-secondary hover:txt-primary flex items-center gap-1 transition-colors"
        >
          <ChevronRightIcon :class="['w-4 h-4 transition-transform', stackExpanded && 'rotate-90']" />
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
            <pre class="text-xs txt-secondary font-mono bg-black/5 dark:bg-white/5 p-3 rounded overflow-x-auto max-h-[300px] overflow-y-auto scroll-thin">{{ props.error.stack }}</pre>
          </div>
        </Transition>
      </div>

      <!-- Actions -->
      <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-8" data-testid="section-actions">
        <button
          @click="handleReload"
          class="btn-primary px-8 py-3 rounded-lg font-semibold flex items-center gap-2 min-w-[200px] justify-center"
          data-testid="btn-reload"
        >
          <ArrowPathIcon class="w-5 h-5" />
          {{ $t('error.reload') }}
        </button>
        <router-link
          to="/"
          class="px-8 py-3 rounded-lg border-2 border-light-border/30 dark:border-dark-border/20 txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors font-semibold flex items-center gap-2 min-w-[200px] justify-center"
          data-testid="btn-home"
        >
          <HomeIcon class="w-5 h-5" />
          {{ $t('error.goHome') }}
        </router-link>
      </div>

      <!-- Support Info -->
      <div class="surface-card p-6 rounded-xl" data-testid="section-support">
        <div class="flex items-start gap-4">
          <div class="w-10 h-10 rounded-full bg-blue-500/10 flex items-center justify-center flex-shrink-0">
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
              @click="copyErrorInfo"
              class="text-sm font-medium hover:text-[var(--brand)] transition-colors flex items-center gap-2"
              style="color: var(--brand)"
              data-testid="btn-copy-error"
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
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import {
  ExclamationTriangleIcon,
  HomeIcon,
  ArrowPathIcon,
  CodeBracketIcon,
  ChevronRightIcon,
  ChatBubbleLeftRightIcon,
  ClipboardDocumentIcon
} from '@heroicons/vue/24/outline'

interface Props {
  error?: {
    message?: string
    statusCode?: number
    stack?: string
  }
  showStack?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  showStack: import.meta.env.VITE_SHOW_ERROR_STACK === 'true'
})

const route = useRoute()

// Check for specific error reasons from query params
const isRedirectLoop = computed(() => route.query.reason === 'redirect_loop')
const isAuthTimeout = computed(() => route.query.reason === 'auth_timeout')

// Provide more specific error info based on reason
const effectiveError = computed(() => {
  if (isRedirectLoop.value) {
    return {
      message: 'A redirect loop was detected. This usually happens when authentication state is inconsistent. Please clear your browser cookies and try again.',
      statusCode: 508
    }
  }
  if (isAuthTimeout.value) {
    return {
      message: 'Authentication check timed out. The server may be unavailable. Please try again later.',
      statusCode: 504
    }
  }
  return props.error
})

const stackExpanded = ref(false)
const copied = ref(false)

const handleReload = () => {
  window.location.reload()
}

const copyErrorInfo = async () => {
  const errorInfo = {
    message: effectiveError.value?.message || 'Unknown error',
    statusCode: effectiveError.value?.statusCode || 500,
    timestamp: new Date().toISOString(),
    userAgent: navigator.userAgent,
    url: window.location.href
  }

  try {
    await navigator.clipboard.writeText(JSON.stringify(errorInfo, null, 2))
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}
</script>

