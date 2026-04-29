<template>
  <ErrorView
    v-if="activeError"
    :error="activeError"
    :on-retry="handleRetry"
    :on-go-home="handleGoHome"
    data-testid="comp-error-boundary"
  />
  <slot v-else />
</template>

<script setup lang="ts">
import { computed, onErrorCaptured, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ErrorView from '@/views/ErrorView.vue'
import { useGlobalErrorStore, type GlobalErrorPayload } from '@/stores/globalError'
import { getErrorMessage } from '@/utils/errorMessage'

/**
 * App-wide error boundary. We render <ErrorView /> *inline* (replacing the
 * router-view slot) instead of navigating to a dedicated /error route, which
 * keeps the URL stable for the user and lets the host route recover when the
 * underlying problem clears.
 *
 * Two ingestion paths feed `activeError`:
 *   1. Vue's onErrorCaptured() → component-tree errors thrown during render
 *      or in lifecycle hooks of any descendant.
 *   2. globalErrorStore → "infrastructure" errors raised by router guards,
 *      auth bootstrap, lazy-import failures, etc. Anything that lives outside
 *      the component tree pushes through this store instead of router.push().
 */

const localError = ref<GlobalErrorPayload | null>(null)
const globalErrorStore = useGlobalErrorStore()
const router = useRouter()
const route = useRoute()
const lastErrorPath = ref<string | null>(null)

const activeError = computed<GlobalErrorPayload | null>(
  () => localError.value ?? globalErrorStore.current
)

onErrorCaptured((err: unknown) => {
  console.error('Component error captured by ErrorBoundary:', err)

  const statusFromErr =
    err &&
    typeof err === 'object' &&
    'statusCode' in err &&
    typeof (err as { statusCode: unknown }).statusCode === 'number'
      ? (err as { statusCode: number }).statusCode
      : undefined

  localError.value = {
    message: getErrorMessage(err) ?? undefined,
    statusCode: statusFromErr,
    stack: err instanceof Error ? (err.stack ?? '') : '',
    reason: 'unknown',
    source: 'component',
  }
  lastErrorPath.value = route.fullPath

  // Returning false stops the error from bubbling further so Vue does not
  // log it again at the app root and the rest of the boundary can render.
  return false
})

/**
 * If the user navigates to a different route while an error is being shown,
 * assume they are trying to escape the failure (e.g. via "Go to Home" or any
 * sidebar link) and clear the error so the new route gets a chance to render.
 */
watch(
  () => route.fullPath,
  (next) => {
    if (next !== lastErrorPath.value && activeError.value) {
      clearAll()
    }
  }
)

function clearAll(): void {
  localError.value = null
  globalErrorStore.clear()
  lastErrorPath.value = null
}

function handleRetry(): void {
  // Component-tree errors usually need a remount to recover, so we keep the
  // hard reload as the safe default. Infrastructure errors that were pushed
  // through the global store can usually be cleared in-place; we still do a
  // best-effort `router.replace` to refresh the current route's setup().
  if (localError.value) {
    window.location.reload()
    return
  }
  const targetPath = route.fullPath
  clearAll()
  router.replace(targetPath).catch(() => {
    // Fall back to a hard reload if the router refuses (e.g. same-route nav
    // resolving to undefined).
    window.location.reload()
  })
}

function handleGoHome(): void {
  clearAll()
  router.push('/').catch(() => {
    window.location.assign('/')
  })
}
</script>
