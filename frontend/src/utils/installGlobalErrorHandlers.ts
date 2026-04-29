import type { App } from 'vue'
import { useGlobalErrorStore, type GlobalErrorPayload } from '@/stores/globalError'
import { getErrorMessage } from '@/utils/errorMessage'

/**
 * Patterns we deliberately ignore. These are typically benign noise produced by
 * browser extensions, dev-tools, or harmless ResizeObserver loops — surfacing
 * them through the full-screen ErrorView would be more disruptive than helpful.
 *
 * Add patterns conservatively: every entry hides a potentially real bug from
 * end users. Keep the list short and document why each pattern is safe.
 */
const IGNORED_PATTERNS: RegExp[] = [
  // Chrome's internal ResizeObserver loop guard — fires for harmless layout
  // thrashing in scroll containers and is explicitly recommended-to-ignore by
  // the spec.
  /ResizeObserver loop (?:limit exceeded|completed with undelivered notifications)/i,
  // Browser-extension content scripts injecting into our page.
  /^Script error\.?$/i,
  // Network request aborted by the user (navigation away, slow connection).
  /^The user aborted a request\.?$/i,
  /AbortError/i,
]

function shouldIgnore(message: string | undefined | null): boolean {
  if (!message) return false
  return IGNORED_PATTERNS.some((pattern) => pattern.test(message))
}

function toPayload(
  err: unknown,
  source: GlobalErrorPayload['source'],
  fallbackMessage: string
): GlobalErrorPayload {
  const message = getErrorMessage(err) ?? fallbackMessage
  const stack = err instanceof Error ? (err.stack ?? '') : ''
  return {
    message,
    stack,
    reason: 'unknown',
    source,
  }
}

/**
 * Centralised error handlers that route every "escapes the component tree"
 * failure into the globalError Pinia store, which in turn renders the inline
 * ErrorView via ErrorBoundary. Covers four orthogonal channels:
 *
 *   1. Vue's app.config.errorHandler  — backstop for everything onErrorCaptured
 *      lets through (rare, but real for some async lifecycle bugs).
 *   2. window 'error'                 — script errors that never enter Vue
 *      (setTimeout/setInterval callbacks, raw addEventListener handlers,
 *      <script> tags injected at runtime).
 *   3. window 'unhandledrejection'    — promise chains without await/.catch()
 *      (the most common modern source of silent failures).
 *
 * MUST be called *after* the Pinia plugin has been registered on the app, so
 * the store is reachable. Idempotent: safe to call multiple times in dev with
 * Vite HMR — duplicate listeners would still be deduplicated by the browser
 * because we pass the same function reference (we keep them as module-scoped
 * variables that survive HMR boundaries).
 */
let windowErrorHandler: ((event: ErrorEvent) => void) | null = null
let windowRejectionHandler: ((event: PromiseRejectionEvent) => void) | null = null

export function installGlobalErrorHandlers(app: App): void {
  const store = useGlobalErrorStore()

  app.config.errorHandler = (err, _instance, info) => {
    console.error('Vue errorHandler caught:', err, '\nInfo:', info)
    if (shouldIgnore(getErrorMessage(err))) return
    store.setError(toPayload(err, `vue:${info}`, 'A Vue lifecycle hook failed'))
  }

  if (windowErrorHandler) {
    window.removeEventListener('error', windowErrorHandler)
  }
  windowErrorHandler = (event: ErrorEvent) => {
    if (shouldIgnore(event.message)) return
    console.error('window error caught:', event.error ?? event.message)
    store.setError(toPayload(event.error ?? event.message, 'window:error', event.message))
  }
  window.addEventListener('error', windowErrorHandler)

  if (windowRejectionHandler) {
    window.removeEventListener('unhandledrejection', windowRejectionHandler)
  }
  windowRejectionHandler = (event: PromiseRejectionEvent) => {
    const reason = event.reason
    const message = getErrorMessage(reason)
    if (shouldIgnore(message)) return
    console.error('Unhandled promise rejection caught:', reason)
    store.setError(
      toPayload(reason, 'window:unhandledrejection', 'An async operation failed silently')
    )
  }
  window.addEventListener('unhandledrejection', windowRejectionHandler)
}
