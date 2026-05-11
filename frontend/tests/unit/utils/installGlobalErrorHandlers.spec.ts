import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { installGlobalErrorHandlers } from '@/utils/installGlobalErrorHandlers'
import { useGlobalErrorStore } from '@/stores/globalError'
import { useNotification } from '@/composables/useNotification'

describe('installGlobalErrorHandlers', () => {
  let app: ReturnType<typeof createApp>

  beforeEach(() => {
    setActivePinia(createPinia())
    app = createApp({ template: '<div />' })
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    // Each test starts from a clean toast queue so the transient-path
    // assertions don't bleed across cases.
    const { notifications } = useNotification()
    notifications.value.splice(0, notifications.value.length)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('wires app.config.errorHandler into the global error store', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()

    const err = new Error('component failed')
    app.config.errorHandler!(err, null, 'render hook')

    expect(store.hasError).toBe(true)
    expect(store.current?.message).toBe('component failed')
    expect(store.current?.source).toBe('vue:render hook')
  })

  it('routes window error events into the global error store', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()

    const err = new Error('script blew up')
    window.dispatchEvent(
      new ErrorEvent('error', {
        error: err,
        message: err.message,
      })
    )

    expect(store.current?.message).toBe('script blew up')
    expect(store.current?.source).toBe('window:error')
  })

  it('routes unhandled promise rejections into the global error store', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()

    const reason = new Error('async chain failed')
    // PromiseRejectionEvent is not constructable in jsdom; emulate the shape
    // the production listener actually reads.
    const event = new Event('unhandledrejection') as Event & {
      reason: unknown
      promise: Promise<unknown>
    }
    event.reason = reason
    event.promise = Promise.reject(reason)
    // Prevent the rejection from bubbling into Vitest's own unhandledrejection
    // tracking and failing the test run.
    event.promise.catch(() => {})

    window.dispatchEvent(event)

    expect(store.current?.message).toBe('async chain failed')
    expect(store.current?.source).toBe('window:unhandledrejection')
  })

  it('ignores ResizeObserver loop noise', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()

    window.dispatchEvent(
      new ErrorEvent('error', {
        message: 'ResizeObserver loop completed with undelivered notifications.',
      })
    )

    expect(store.hasError).toBe(false)
  })

  it('ignores opaque cross-origin "Script error." events from extensions', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()

    window.dispatchEvent(
      new ErrorEvent('error', {
        message: 'Script error.',
      })
    )

    expect(store.hasError).toBe(false)
  })

  it('ignores user-aborted fetch rejections', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()

    const reason = new DOMException('The user aborted a request.', 'AbortError')
    const event = new Event('unhandledrejection') as Event & {
      reason: unknown
      promise: Promise<unknown>
    }
    event.reason = reason
    event.promise = Promise.reject(reason)
    event.promise.catch(() => {})
    window.dispatchEvent(event)

    expect(store.hasError).toBe(false)
  })

  // -----------------------------------------------------------------------
  // Issue #897 — transient (chunk-load / network) errors must NOT replace
  // the entire UI with the full-screen ErrorView. They should fire a
  // non-fatal toast warning instead so the user can simply retry their
  // last action. See `TRANSIENT_PATTERNS` in installGlobalErrorHandlers.ts.
  // -----------------------------------------------------------------------

  const transientCases: Array<{ label: string; message: string }> = [
    { label: 'Vite chunk-load failure', message: 'Loading chunk 42 failed.' },
    {
      label: 'dynamic-import failure after background-resume',
      message:
        'Failed to fetch dynamically imported module: https://example.test/assets/filesService-DEAD.js',
    },
    { label: 'ChunkLoadError shape', message: 'ChunkLoadError: Loading chunk 7 failed.' },
    { label: 'generic Failed to fetch', message: 'Failed to fetch' },
    { label: 'Safari Load failed', message: 'Load failed' },
    { label: 'EventSource SSE error', message: 'EventSource connection to /api/v1/stream failed' },
  ]

  it.each(transientCases)(
    'transient error ($label) does NOT raise the full-screen ErrorView',
    ({ message }) => {
      installGlobalErrorHandlers(app)
      const store = useGlobalErrorStore()
      const { notifications } = useNotification()

      window.dispatchEvent(
        new ErrorEvent('error', {
          error: new Error(message),
          message,
        })
      )

      // Full-screen view is NOT triggered.
      expect(store.hasError).toBe(false)
      // A non-fatal toast IS shown to the user.
      expect(notifications.value).toHaveLength(1)
      expect(notifications.value[0]?.type).toBe('warning')
      expect(notifications.value[0]?.message).toContain(message)
    }
  )

  it('routes transient unhandledrejection into a warning toast (Scenario A: file upload + send)', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()
    const { notifications } = useNotification()

    // Simulates the issue's Scenario A: handleSendMessage's dynamic
    // `import('@/services/filesService')` rejects on a flaky cellular
    // connection. Pre-fix, this hit the global handler and replaced the
    // chat with the full-screen "Etwas ist schiefgelaufen" view.
    const reason = new Error(
      'Failed to fetch dynamically imported module: https://example.test/assets/filesService-DEAD.js'
    )
    const event = new Event('unhandledrejection') as Event & {
      reason: unknown
      promise: Promise<unknown>
    }
    event.reason = reason
    event.promise = Promise.reject(reason)
    event.promise.catch(() => {})
    window.dispatchEvent(event)

    expect(store.hasError).toBe(false)
    expect(notifications.value).toHaveLength(1)
    expect(notifications.value[0]?.type).toBe('warning')
  })

  it('non-transient errors still raise the full-screen ErrorView', () => {
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()
    const { notifications } = useNotification()

    // A real, unexpected programming error must NOT be silently downgraded.
    window.dispatchEvent(
      new ErrorEvent('error', {
        error: new TypeError("Cannot read properties of undefined (reading 'foo')"),
        message: "TypeError: Cannot read properties of undefined (reading 'foo')",
      })
    )

    expect(store.hasError).toBe(true)
    expect(store.current?.message).toContain('Cannot read properties of undefined')
    expect(notifications.value).toHaveLength(0)
  })

  it('is idempotent — re-installing replaces previous listeners cleanly', () => {
    installGlobalErrorHandlers(app)
    installGlobalErrorHandlers(app)
    const store = useGlobalErrorStore()

    window.dispatchEvent(
      new ErrorEvent('error', {
        error: new Error('only once'),
        message: 'only once',
      })
    )

    // Single payload, not duplicated — the second install replaced the first
    // listener instead of stacking on top.
    expect(store.current?.message).toBe('only once')
  })
})
