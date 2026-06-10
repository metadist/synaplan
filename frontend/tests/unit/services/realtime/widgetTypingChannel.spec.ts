import { describe, expect, it, vi } from 'vitest'
import {
  TYPING_TEXT_MAX_CHARS,
  TypingFrameSchema,
  openWidgetTypingChannel,
  type TypingFrame,
} from '@/services/realtime/widgetTypingChannel'
import type { RealtimeClient } from '@/services/realtime/RealtimeClient'

/**
 * The typing channel is the only place in the app that publishes from
 * the browser, so the tests here focus on the security-shaped invariants:
 *
 *   * Inbound frames are validated with Zod — a misbehaving peer cannot
 *     corrupt local state by sending a non-conforming object.
 *   * Echo prevention works (publisher does not see itself "typing").
 *   * Outbound text is capped before it ever reaches the wire.
 *   * Subscribe failure is reported via onError, never silently swallowed
 *     into a hung handle.
 */

interface FakeSubHandle {
  unsubscribe: ReturnType<typeof vi.fn>
  publish: ReturnType<typeof vi.fn>
}

interface FakeClient {
  subscribe: ReturnType<typeof vi.fn>
}

function buildClient(): {
  client: FakeClient
  /** Drives the publication callback that centrifuge would invoke. */
  emit: (envelope: { type: string; ts: number; data: Record<string, unknown> }) => void
  /** Forces the deferred subscribe to reject. */
  fail: (err: Error) => void
  handle: FakeSubHandle
  resolveSubscribe: () => void
} {
  const handle: FakeSubHandle = {
    unsubscribe: vi.fn(),
    publish: vi.fn().mockResolvedValue(undefined),
  }
  let publication: (env: {
    type: string
    ts: number
    data: Record<string, unknown>
  }) => void = () => undefined
  let resolveFn: (h: FakeSubHandle) => void = () => undefined
  let rejectFn: (err: Error) => void = () => undefined

  const client: FakeClient = {
    subscribe: vi.fn(
      (
        _channel: string,
        handlers: { onPublication: typeof publication; onError?: (msg: string) => void }
      ) => {
        publication = handlers.onPublication
        // Stash the error reporter so fail() below can drive it as well.
        ;(client as unknown as { onError?: (msg: string) => void }).onError = handlers.onError
        return new Promise<FakeSubHandle>((resolve, reject) => {
          resolveFn = resolve
          rejectFn = reject
        })
      }
    ),
  }

  return {
    client,
    emit: (envelope) => publication(envelope),
    fail: (err) => rejectFn(err),
    handle,
    resolveSubscribe: () => resolveFn(handle),
  }
}

describe('openWidgetTypingChannel', () => {
  it('subscribes to widgettyping:{widgetId}.{sessionId}', async () => {
    const { client } = buildClient()

    openWidgetTypingChannel({
      client: client as unknown as RealtimeClient,
      widgetId: 'wdg_1',
      sessionId: 'sid_2',
      from: 'visitor',
      onTyping: () => undefined,
    })

    expect(client.subscribe).toHaveBeenCalledWith('widgettyping:wdg_1.sid_2', expect.any(Object))
  })

  it('publish() and onPublication() agree on the wire shape (regression)', async () => {
    // Regression: previously the publisher sent the bare frame while the
    // receiver expected an `{ type, ts, data }` envelope, so EVERY typing
    // frame round-tripping through Centrifugo was silently dropped. This
    // test feeds whatever the publisher emits straight back into the
    // receiver — a client that publishes for itself MUST be parseable by
    // the same client.
    const { client, emit, handle, resolveSubscribe } = buildClient()
    const received: TypingFrame[] = []
    const ch = openWidgetTypingChannel({
      client: client as unknown as RealtimeClient,
      widgetId: 'w',
      sessionId: 's',
      from: 'operator',
      // We swap out our own cid filter to verify the parse path even
      // though the publisher would normally see its own publication
      // dropped by the echo guard.
      onTyping: (f) => received.push(f),
    })
    resolveSubscribe()
    await Promise.resolve()
    await Promise.resolve()

    ch.publish('hi')
    const wireEnvelope = handle.publish.mock.calls[0][0] as {
      type: string
      ts: number
      data: { from: string; text: string; ts: number; cid: string }
    }
    // Replay the wire envelope back through the receiver from a "different"
    // peer (rewrite cid so the echo guard doesn't drop it).
    emit({
      type: wireEnvelope.type,
      ts: wireEnvelope.ts,
      data: { ...wireEnvelope.data, cid: 'peer' },
    })

    expect(received).toHaveLength(1)
    expect(received[0].text).toBe('hi')
    expect(received[0].from).toBe('operator')
  })

  it('drops malformed frames (Zod parse failure) without invoking onTyping', async () => {
    const { client, emit, resolveSubscribe } = buildClient()
    const received: TypingFrame[] = []

    openWidgetTypingChannel({
      client: client as unknown as RealtimeClient,
      widgetId: 'w',
      sessionId: 's',
      from: 'visitor',
      onTyping: (f) => received.push(f),
    })
    resolveSubscribe()

    // Garbage frame — no `from`, no `cid`, wrong types. MUST NOT reach onTyping.
    emit({ type: 'typing', ts: 1, data: { whatever: true } })
    expect(received).toEqual([])
  })

  it('filters echoes by client id (publisher does not see its own frames)', async () => {
    const { client, emit, handle, resolveSubscribe } = buildClient()
    const received: TypingFrame[] = []

    const ch = openWidgetTypingChannel({
      client: client as unknown as RealtimeClient,
      widgetId: 'w',
      sessionId: 's',
      from: 'visitor',
      onTyping: (f) => received.push(f),
    })
    resolveSubscribe()
    // Drain microtasks so the helper's `.then(h => handle = h)` runs before
    // we publish — the helper deliberately drops publishes issued during
    // the subscribe handshake.
    await Promise.resolve()
    await Promise.resolve()

    ch.publish('hello')
    expect(handle.publish).toHaveBeenCalledOnce()
    // The publisher wraps the frame in the standard `{type, ts, data}`
    // envelope (mirrors the backend `RealtimePublisher` shape) — pull the
    // frame out of `.data` to check the cid.
    const sentEnvelope = handle.publish.mock.calls[0][0] as { data: TypingFrame }
    expect(sentEnvelope.data.cid).toMatch(/.+/)
    const myFrame = sentEnvelope.data

    // Echo back with our own cid → must be ignored.
    emit({ type: 'typing', ts: Date.now(), data: { ...myFrame } })
    expect(received).toEqual([])

    // Frame from another client (different cid) → delivered.
    emit({
      type: 'typing',
      ts: Date.now(),
      data: { from: 'operator', text: 'hi', ts: Date.now(), cid: 'someone-else' },
    })
    expect(received).toHaveLength(1)
    expect(received[0].from).toBe('operator')
  })

  it('caps published text at TYPING_TEXT_MAX_CHARS before hitting the wire', async () => {
    const { client, handle, resolveSubscribe } = buildClient()
    const ch = openWidgetTypingChannel({
      client: client as unknown as RealtimeClient,
      widgetId: 'w',
      sessionId: 's',
      from: 'visitor',
      onTyping: () => undefined,
    })
    resolveSubscribe()
    await Promise.resolve()
    await Promise.resolve()

    const huge = 'x'.repeat(TYPING_TEXT_MAX_CHARS + 100)
    ch.publish(huge)

    const sentEnvelope = handle.publish.mock.calls[0][0] as { data: TypingFrame }
    expect(sentEnvelope.data.text.length).toBe(TYPING_TEXT_MAX_CHARS)
  })

  it('queues no publishes before the subscribe handshake resolves', async () => {
    const { client, handle } = buildClient()
    const ch = openWidgetTypingChannel({
      client: client as unknown as RealtimeClient,
      widgetId: 'w',
      sessionId: 's',
      from: 'visitor',
      onTyping: () => undefined,
    })

    // Calling publish() before resolveSubscribe() must NOT throw and must
    // NOT queue — typing is best-effort, not a delivery guarantee.
    ch.publish('hi')
    expect(handle.publish).not.toHaveBeenCalled()
  })

  it('reports subscribe failures via onError', async () => {
    const { client, fail } = buildClient()
    const errors: string[] = []

    openWidgetTypingChannel({
      client: client as unknown as RealtimeClient,
      widgetId: 'w',
      sessionId: 's',
      from: 'visitor',
      onTyping: () => undefined,
      onError: (msg) => errors.push(msg),
    })

    fail(new Error('subscribe failed'))
    // Microtask drain so the promise rejection handler runs.
    await Promise.resolve()
    await Promise.resolve()

    expect(errors).toEqual(['subscribe failed'])
  })

  it('TypingFrameSchema rejects oversized payloads', () => {
    const huge = 'x'.repeat(TYPING_TEXT_MAX_CHARS + 1)
    const result = TypingFrameSchema.safeParse({
      from: 'visitor',
      text: huge,
      ts: 1,
      cid: 'cid_1',
    })
    expect(result.success).toBe(false)
  })

  it('TypingFrameSchema requires a non-empty cid', () => {
    expect(TypingFrameSchema.safeParse({ from: 'visitor', text: '', ts: 1, cid: '' }).success).toBe(
      false
    )
  })

  it('TypingFrameSchema rejects unknown `from` values', () => {
    expect(
      TypingFrameSchema.safeParse({ from: 'attacker', text: '', ts: 1, cid: 'c' }).success
    ).toBe(false)
  })
})
