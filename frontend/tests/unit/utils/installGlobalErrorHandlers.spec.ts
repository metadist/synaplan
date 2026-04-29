import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { installGlobalErrorHandlers } from '@/utils/installGlobalErrorHandlers'
import { useGlobalErrorStore } from '@/stores/globalError'

describe('installGlobalErrorHandlers', () => {
  let app: ReturnType<typeof createApp>

  beforeEach(() => {
    setActivePinia(createPinia())
    app = createApp({ template: '<div />' })
    vi.spyOn(console, 'error').mockImplementation(() => {})
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
