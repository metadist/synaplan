import type { App } from 'vue'
import { useGlobalErrorStore, type GlobalErrorPayload } from '@/stores/globalError'
import { getErrorMessage } from '@/utils/errorMessage'
import { useNotification } from '@/composables/useNotification'

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

/**
 * Patterns that signal a *transient* failure (network blip, chunk cache
 * eviction after a tab spent time in the background, SSE reconnection
 * timeout). We do NOT want to nuke the entire UI with the full-screen
 * ErrorView for these — they almost always self-recover on the next user
 * action — but we DO want to surface a non-fatal toast so the user knows
 * something just went wrong with their last operation.
 *
 * This was the root cause of issue #897 on mobile: dynamic-import failures
 * after a backgrounded tab and SSE network errors hit the global handler
 * and replaced the entire chat view with "Etwas ist schiefgelaufen", forcing
 * a manual reload to recover. The chat view itself is healthy — only one
 * async operation failed.
 *
 * Add patterns conservatively. Anything matched here is invisible to the
 * full-screen ErrorView, so a *real* fatal bug whose message accidentally
 * matches one of these regexes will be silently downgraded.
 */
const TRANSIENT_PATTERNS: RegExp[] = [
  // Vite dynamic-import / chunk-load failures.
  // Examples seen in the wild on a tab returning from background:
  //   "Loading chunk 42 failed."
  //   "Failed to fetch dynamically imported module: https://.../assets/foo-X.js"
  //   "Importing a module script failed."
  //   "ChunkLoadError: ..."
  /Loading chunk \d+ failed/i,
  /Failed to fetch dynamically imported module/i,
  /Importing a module script failed/i,
  /ChunkLoadError/i,
  // Generic network / fetch / SSE errors. These happen on flaky cellular
  // connections and during background-resume "stale connection" recovery.
  /^Failed to fetch\.?$/i,
  /^Load failed\.?$/i, // Safari's equivalent of "Failed to fetch"
  /^NetworkError when attempting to fetch resource\.?$/i,
  /^TypeError: NetworkError/i,
  /EventSource.*failed/i,
]

function shouldIgnore(message: string | undefined | null): boolean {
  if (!message) return false
  return IGNORED_PATTERNS.some((pattern) => pattern.test(message))
}

function isTransient(message: string | undefined | null): boolean {
  if (!message) return false
  return TRANSIENT_PATTERNS.some((pattern) => pattern.test(message))
}

/**
 * Surface a transient failure as a non-fatal warning toast instead of the
 * full-screen ErrorView. Keeps the chat / view alive so the user can retry
 * by re-sending the message or simply continue.
 */
function reportTransient(message: string, source: GlobalErrorPayload['source']): void {
  // useNotification keeps its `notifications` ref at module scope, so calling
  // it outside a Vue setup() is intentional and safe — no Vue context needed.
  // We deliberately use `warning` (orange) rather than `error` (red): the
  // operation that failed (a single `fetch`, a single chunk load) can be
  // retried by the user's next interaction without engineering involvement.
  const { warning } = useNotification()
  warning(`Connection hiccup. Please try again. (${message})`)
  // Still log so a Sentry-equivalent / console-watcher picks it up.
  console.warn(`[transient:${source ?? 'unknown'}]`, message)
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
 * ErrorView via ErrorBoundary. Covers three orthogonal channels:
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
 * the store is reachable.
 *
 * Idempotent: safe to call multiple times in dev with Vite HMR. We track the
 * previously installed listener in a module-scoped variable and explicitly
 * `removeEventListener()` it before binding a new one, so each install ends
 * up with exactly one active handler per channel — even though every install
 * creates a fresh closure (so the browser's "same function reference"
 * deduplication does NOT apply here). The Vue handler is just an assignment
 * (no listener bookkeeping) and is therefore naturally idempotent.
 */
let windowErrorHandler: ((event: ErrorEvent) => void) | null = null
let windowRejectionHandler: ((event: PromiseRejectionEvent) => void) | null = null

export function installGlobalErrorHandlers(app: App): void {
  const store = useGlobalErrorStore()

  app.config.errorHandler = (err, _instance, info) => {
    console.error('Vue errorHandler caught:', err, '\nInfo:', info)
    const message = getErrorMessage(err)
    if (shouldIgnore(message)) return
    if (isTransient(message)) {
      reportTransient(message ?? 'unknown', `vue:${info}`)
      return
    }
    store.setError(toPayload(err, `vue:${info}`, 'A Vue lifecycle hook failed'))
  }

  if (windowErrorHandler) {
    window.removeEventListener('error', windowErrorHandler)
  }
  windowErrorHandler = (event: ErrorEvent) => {
    if (shouldIgnore(event.message)) return
    if (isTransient(event.message) || isTransient(getErrorMessage(event.error))) {
      reportTransient(event.message || 'unknown', 'window:error')
      return
    }
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
    if (isTransient(message)) {
      reportTransient(message ?? 'unknown', 'window:unhandledrejection')
      return
    }
    console.error('Unhandled promise rejection caught:', reason)
    store.setError(
      toPayload(reason, 'window:unhandledrejection', 'An async operation failed silently')
    )
  }
  window.addEventListener('unhandledrejection', windowRejectionHandler)
}
