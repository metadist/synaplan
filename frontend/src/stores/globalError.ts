import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

/**
 * Reason codes that map to a friendlier admin-visible explanation in ErrorView.
 * Add new codes here when adding new "synthetic" errors raised from router guards
 * or other infrastructure layers.
 */
export type GlobalErrorReason = 'redirect_loop' | 'auth_timeout' | 'router_navigation' | 'unknown'

export interface GlobalErrorPayload {
  message?: string
  statusCode?: number
  stack?: string
  reason?: GlobalErrorReason
  /**
   * Optional source identifier (e.g. "router", "auth", "fetch:/api/v1/foo").
   * Surfaced in the admin-only details panel to speed up debugging.
   */
  source?: string
}

/**
 * Application-wide error state. ErrorBoundary watches this store and replaces
 * the live <router-view /> with an inline ErrorView whenever an error is set,
 * which lets us avoid a dedicated /error route while still presenting the same
 * full-screen error UX.
 *
 * Setters MUST normalise the payload to plain values (no Error instances) so
 * the store stays serialisable for devtools and snapshot tests.
 */
export const useGlobalErrorStore = defineStore('globalError', () => {
  const current = ref<GlobalErrorPayload | null>(null)

  const hasError = computed(() => current.value !== null)

  function setError(payload: GlobalErrorPayload | Error): void {
    if (payload instanceof Error) {
      current.value = {
        message: payload.message,
        stack: payload.stack ?? '',
        reason: 'unknown',
      }
      return
    }
    current.value = { ...payload }
  }

  function clear(): void {
    current.value = null
  }

  return {
    current,
    hasError,
    setError,
    clear,
  }
})
