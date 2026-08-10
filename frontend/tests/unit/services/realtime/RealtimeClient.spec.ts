import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * `RealtimeClient` wraps the `centrifuge` library. We mock the whole module
 * so we can drive the lifecycle deterministically (connecting → connected →
 * disconnected) and assert how the client maps it to our internal state.
 */

interface FakeSubscription {
  on: ReturnType<typeof vi.fn>
  subscribe: ReturnType<typeof vi.fn>
  unsubscribe: ReturnType<typeof vi.fn>
  removeAllListeners: ReturnType<typeof vi.fn>
  publish: ReturnType<typeof vi.fn>
  /** Options passed to `newSubscription` (e.g. the `getToken` callback). */
  __options: { getToken?: () => Promise<string> }
  /** Helper used by the tests to fire an event back into the wrapper. */
  __emit: (event: string, ctx: unknown) => void
}

interface FakeCentrifuge {
  connect: ReturnType<typeof vi.fn>
  disconnect: ReturnType<typeof vi.fn>
  on: ReturnType<typeof vi.fn>
  removeAllListeners: ReturnType<typeof vi.fn>
  newSubscription: ReturnType<typeof vi.fn>
  removeSubscription: ReturnType<typeof vi.fn>
  __emit: (event: string, ctx?: unknown) => void
  __lastSubscription: FakeSubscription | null
  /** Options passed to `new Centrifuge(url, options)` (e.g. the connection `getToken`). */
  __options: { getToken?: () => Promise<string> }
}

const instances: FakeCentrifuge[] = []

function buildFakeSubscription(): FakeSubscription {
  const handlers = new Map<string, (ctx: unknown) => void>()
  return {
    on: vi.fn((event: string, handler: (ctx: unknown) => void) => {
      handlers.set(event, handler)
    }),
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    removeAllListeners: vi.fn(),
    publish: vi.fn().mockResolvedValue({ ok: true }),
    __options: {},
    __emit(event, ctx) {
      handlers.get(event)?.(ctx)
    },
  }
}

function buildFakeCentrifuge(options?: { getToken?: () => Promise<string> }): FakeCentrifuge {
  const handlers = new Map<string, (ctx: unknown) => void>()
  let lastSub: FakeSubscription | null = null
  const fake: FakeCentrifuge = {
    connect: vi.fn(),
    disconnect: vi.fn(),
    on: vi.fn((event: string, handler: (ctx: unknown) => void) => {
      handlers.set(event, handler)
    }),
    removeAllListeners: vi.fn(),
    newSubscription: vi.fn((_channel: string, options?: { getToken?: () => Promise<string> }) => {
      lastSub = buildFakeSubscription()
      lastSub.__options = options ?? {}
      fake.__lastSubscription = lastSub
      return lastSub
    }),
    removeSubscription: vi.fn(),
    __emit: (event, ctx) => handlers.get(event)?.(ctx),
    __lastSubscription: null,
    __options: options ?? {},
  }
  return fake
}

vi.mock('centrifuge', () => {
  // The wrapper instantiates with `new Centrifuge(url, options)` — vi.fn
  // alone is not newable, so we hand back a plain function constructor that
  // captures the connection-level `getToken` for tests to invoke directly.
  function Centrifuge(_url: string, options?: { getToken?: () => Promise<string> }) {
    const inst = buildFakeCentrifuge(options)
    instances.push(inst)
    return inst
  }
  // Declared inside the factory (which is hoisted) so it exists before the
  // wrapper's `import { UnauthorizedError } from 'centrifuge'` resolves.
  class UnauthorizedError extends Error {}
  const disconnectedCodes = {
    disconnectCalled: 0,
    unauthorized: 1,
    badProtocol: 2,
    messageSizeLimit: 3,
  }
  return { Centrifuge, UnauthorizedError, disconnectedCodes }
})

vi.mock('@/services/realtime/tokenApi', () => ({
  fetchOperatorConnectionToken: vi.fn().mockResolvedValue({
    token: 'op-token',
    expiresIn: 60,
    subject: 'user:1',
  }),
  fetchVisitorConnectionToken: vi.fn().mockResolvedValue({
    token: 'visitor-token',
    expiresIn: 60,
    subject: 'visitor:abc',
  }),
  fetchSubscriptionToken: vi.fn().mockResolvedValue({
    token: 'sub-token',
    channel: 'widget:operators.w_1',
    expiresIn: 60,
  }),
}))

import { UnauthorizedError, disconnectedCodes } from 'centrifuge'
import { RealtimeClient } from '@/services/realtime/RealtimeClient'
import type { ConnectionState } from '@/services/realtime/types'
import { fetchSubscriptionToken, fetchVisitorConnectionToken } from '@/services/realtime/tokenApi'
import { ApiError } from '@/services/api/httpClient'

const fetchSubscriptionTokenMock = vi.mocked(fetchSubscriptionToken)
const fetchVisitorConnectionTokenMock = vi.mocked(fetchVisitorConnectionToken)

describe('RealtimeClient', () => {
  beforeEach(() => {
    instances.length = 0
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  function buildClient(stateSink: { state?: ConnectionState; error?: string }) {
    return new RealtimeClient({
      runtime: { enabled: true, wsUrl: 'wss://example.test/connection/websocket' },
      identity: { kind: 'operator' },
      onStateChange: (state, error) => {
        stateSink.state = state
        stateSink.error = error
      },
    })
  }

  it('reports the disabled state when realtime is turned off', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = new RealtimeClient({
      runtime: { enabled: false, wsUrl: '' },
      identity: { kind: 'operator' },
      onStateChange: (s, e) => {
        sink.state = s
        sink.error = e
      },
    })

    await client.connect()

    // No centrifuge instance built. The badge / consumers see "disabled"
    // (a deliberate kill-switch) instead of "disconnected" (a fault) so
    // the UX layer can respond appropriately.
    expect(instances).toHaveLength(0)
    expect(client.getState()).toBe('disabled')
    expect(sink.state).toBe('disabled')
    expect(sink.error).toBe('realtime-disabled')
  })

  it('returns a no-op subscription when realtime is disabled', async () => {
    const client = new RealtimeClient({
      runtime: { enabled: false, wsUrl: '' },
      identity: { kind: 'operator' },
    })
    const events: unknown[] = []

    const handle = await client.subscribe('widget:operators.w_1', {
      onPublication: (e) => events.push(e),
    })

    expect(instances).toHaveLength(0)
    expect(typeof handle.unsubscribe).toBe('function')
    handle.unsubscribe() // must not throw
    expect(events).toEqual([])
  })

  it('mirrors the centrifuge connection lifecycle into ConnectionState', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)

    await client.connect()
    const c = instances[0]
    expect(c.connect).toHaveBeenCalledOnce()

    c.__emit('connecting')
    expect(sink.state).toBe('connecting')

    c.__emit('connected')
    expect(sink.state).toBe('connected')

    c.__emit('disconnected', { code: 4500 })
    expect(sink.state).toBe('reconnecting')
    expect(sink.error).toBe('disconnect:4500')
  })

  it('maps a centrifuge disconnect with the unauthorized code to the terminal error state (#1451)', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)

    await client.connect()
    const c = instances[0]

    // _failUnauthorized() in centrifuge-js disconnects with this code and
    // deliberately does NOT schedule a reconnect — surfacing it as
    // 'reconnecting' would be misleading and would stop ChatWidget from
    // ever tearing the dead connection down.
    c.__emit('disconnected', { code: disconnectedCodes.unauthorized })
    expect(sink.state).toBe('error')
  })

  it('still reports ordinary disconnects as reconnecting, not error', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)

    await client.connect()
    instances[0].__emit('disconnected', { code: disconnectedCodes.badProtocol })
    expect(sink.state).toBe('reconnecting')
  })

  it('translates a terminal visitor connection-token failure into UnauthorizedError (#1451)', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = new RealtimeClient({
      runtime: { enabled: true, wsUrl: 'wss://example.test/connection/websocket' },
      identity: { kind: 'visitor', widgetId: 'wdg_1', sessionId: 'sid_1' },
      onStateChange: (state, error) => {
        sink.state = state
        sink.error = error
      },
    })

    await client.connect()
    const getToken = instances[0].__options.getToken!
    expect(typeof getToken).toBe('function')

    // An expired-but-unknown or disallowed-origin pair — see
    // fetchVisitorConnectionToken() and WidgetVisitorAccessChecker.
    fetchVisitorConnectionTokenMock.mockRejectedValueOnce(new ApiError(404, 'not_found'))

    await expect(getToken()).rejects.toBeInstanceOf(UnauthorizedError)
  })

  it('rethrows a transient visitor connection-token failure so centrifuge can retry', async () => {
    const client = new RealtimeClient({
      runtime: { enabled: true, wsUrl: 'wss://example.test/connection/websocket' },
      identity: { kind: 'visitor', widgetId: 'wdg_1', sessionId: 'sid_1' },
    })

    await client.connect()
    const getToken = instances[0].__options.getToken!

    fetchVisitorConnectionTokenMock.mockRejectedValueOnce(new Error('network down'))

    await expect(getToken()).rejects.not.toBeInstanceOf(UnauthorizedError)
  })

  it('reports errors via onStateChange', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)
    await client.connect()
    instances[0].__emit('error', { error: { message: 'boom' } })

    expect(sink.state).toBe('error')
    expect(sink.error).toBe('boom')
  })

  it('subscribes lazily and dispatches publications via the envelope', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)
    const received: unknown[] = []

    const handle = await client.subscribe('widget:operators.w_1', {
      onPublication: (event) => received.push(event),
    })
    const c = instances[0]
    expect(c.newSubscription).toHaveBeenCalledWith('widget:operators.w_1', expect.any(Object))

    const sub = c.__lastSubscription!
    expect(sub.subscribe).toHaveBeenCalledOnce()

    sub.__emit('publication', {
      data: { type: 'notification', ts: 1, data: { count: 3 } },
    })
    expect(received).toEqual([{ type: 'notification', ts: 1, data: { count: 3 } }])

    handle.unsubscribe()
    expect(sub.unsubscribe).toHaveBeenCalledOnce()
    expect(c.removeSubscription).toHaveBeenCalledOnce()
  })

  it('converts a 403 subscription-token failure into UnauthorizedError to stop the retry loop (#1381)', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)

    await client.subscribe('user:1', { onPublication: () => {} })
    const getToken = instances[0].__lastSubscription!.__options.getToken!
    expect(typeof getToken).toBe('function')

    // A stale subscription to another principal's channel (e.g. after
    // impersonation) resolves to a 403 that no token refresh can fix.
    fetchSubscriptionTokenMock.mockRejectedValueOnce(new ApiError(403, 'forbidden'))

    await expect(getToken()).rejects.toBeInstanceOf(UnauthorizedError)
  })

  it('rethrows a transient subscription-token failure so centrifuge can retry (#1381)', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)

    await client.subscribe('user:1', { onPublication: () => {} })
    const getToken = instances[0].__lastSubscription!.__options.getToken!

    // A network blip is not an auth failure — must NOT become UnauthorizedError.
    fetchSubscriptionTokenMock.mockRejectedValueOnce(new Error('network down'))

    await expect(getToken()).rejects.not.toBeInstanceOf(UnauthorizedError)
  })

  it('forwards subscription errors via onError', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)
    const errors: string[] = []

    await client.subscribe('widget:operators.w_1', {
      onPublication: () => {},
      onError: (msg) => errors.push(msg),
    })

    instances[0].__lastSubscription!.__emit('error', { error: { message: 'auth-failed' } })

    expect(errors).toEqual(['auth-failed'])
  })

  it('exposes a publish() handle that forwards to centrifuge sub.publish', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)

    const handle = await client.subscribe('widgettyping:w_1.s_1', {
      onPublication: () => {},
    })
    const sub = instances[0].__lastSubscription!

    await handle.publish({ from: 'visitor', text: 'hi', ts: 1, cid: 'c' })

    expect(sub.publish).toHaveBeenCalledWith({ from: 'visitor', text: 'hi', ts: 1, cid: 'c' })
  })

  it('publish() degrades to a no-op when realtime is disabled', async () => {
    const client = new RealtimeClient({
      runtime: { enabled: false, wsUrl: '' },
      identity: { kind: 'operator' },
    })

    const handle = await client.subscribe('widgettyping:w.s', { onPublication: () => {} })
    // Must resolve cleanly even though the underlying centrifuge instance
    // was never built — callers may publish() optimistically without a
    // feature-flag check.
    await expect(handle.publish({ x: 1 })).resolves.toBeUndefined()
  })

  it('disconnect clears subscriptions and state', async () => {
    const sink: { state?: ConnectionState; error?: string } = {}
    const client = buildClient(sink)
    await client.subscribe('widget:operators.w_1', { onPublication: () => {} })
    instances[0].__emit('connected')
    expect(sink.state).toBe('connected')

    await client.disconnect()

    const c = instances[0]
    expect(c.disconnect).toHaveBeenCalledOnce()
    expect(c.removeAllListeners).toHaveBeenCalledOnce()
    expect(client.getState()).toBe('disconnected')
    expect(sink.state).toBe('disconnected')

    // After disconnect the client must refuse to spin up another centrifuge.
    await client.connect()
    expect(instances).toHaveLength(1)
  })
})
