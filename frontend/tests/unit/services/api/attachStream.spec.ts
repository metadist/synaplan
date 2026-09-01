import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { chatApi } from '@/services/api/chatApi'
import type { StreamUpdatePayload } from '@/types/chatStream'

vi.mock('@/services/api/httpClient', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/services/api/httpClient')>()),
  getApiBaseUrl: () => 'http://backend.test',
}))

/**
 * Re-attaching to a turn that kept generating after the tab left is the whole
 * point of the feature, so the transport has to behave exactly like the live
 * stream: same payloads, same terminal event, and a drop reported in the
 * wording `isRecoverableStreamError()` recognises — otherwise the chat view
 * never falls back to reloading the persisted answer.
 */
describe('chatApi.attachStream', () => {
  const sseStream = (frames: string[], onCancel?: () => void) =>
    new ReadableStream<Uint8Array>({
      start(controller) {
        const encoder = new TextEncoder()
        for (const frame of frames) controller.enqueue(encoder.encode(frame))
        controller.close()
      },
      cancel() {
        onCancel?.()
      },
    })

  /** Typed like the real `fetch` so `mock.calls` keeps the url/init arguments. */
  const respondWith = (response: Record<string, unknown>) =>
    vi.fn<(url: string, init?: RequestInit) => Promise<unknown>>(async () => response)

  const collect = (fetchMock: ReturnType<typeof vi.fn>) => {
    vi.stubGlobal('fetch', fetchMock)
    const events: StreamUpdatePayload[] = []
    const stop = chatApi.attachStream({ runId: 'run-1', onUpdate: (data) => events.push(data) })
    return { events, stop }
  }

  const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('replays the buffered turn through the same payload contract as a live stream', async () => {
    const { events } = collect(
      vi.fn(async () => ({
        ok: true,
        status: 200,
        body: sseStream([
          'id: 1\ndata: {"status":"data","chunk":"Hello "}\n\n',
          'id: 2\ndata: {"status":"data","chunk":"world"}\n\n',
          'id: 3\ndata: {"status":"complete","messageId":42}\n\n',
        ]),
      }))
    )

    await flush()

    expect(events).toEqual([
      { status: 'data', chunk: 'Hello ' },
      { status: 'data', chunk: 'world' },
      { status: 'complete', messageId: 42 },
    ])
  })

  it('requests the run from the sequence the caller already rendered', async () => {
    const fetchMock = respondWith({
      ok: true,
      status: 200,
      body: sseStream(['data: {"status":"complete"}\n\n']),
    })
    vi.stubGlobal('fetch', fetchMock)

    chatApi.attachStream({ runId: 'run-7', from: 12, onUpdate: () => {} })
    await flush()

    const url = new URL(fetchMock.mock.calls[0][0])
    expect(url.pathname).toBe('/api/v1/messages/stream/attach')
    expect(url.searchParams.get('runId')).toBe('run-7')
    expect(url.searchParams.get('from')).toBe('12')
  })

  it('identifies a guest so the server can check ownership of the run', async () => {
    const fetchMock = respondWith({
      ok: true,
      status: 200,
      body: sseStream(['data: {"status":"complete"}\n\n']),
    })
    vi.stubGlobal('fetch', fetchMock)

    chatApi.attachStream({ runId: 'run-9', guestSessionId: 'guest-abc', onUpdate: () => {} })
    await flush()

    const url = new URL(fetchMock.mock.calls[0][0])
    expect(url.searchParams.get('guestSession')).toBe('guest-abc')
  })

  it('sends the widget session headers when attaching from an embedded widget', async () => {
    const fetchMock = respondWith({
      ok: true,
      status: 200,
      body: sseStream(['data: {"status":"complete"}\n\n']),
    })
    vi.stubGlobal('fetch', fetchMock)

    chatApi.attachStream({
      runId: 'run-w',
      widgetId: 'w-1',
      widgetSessionId: 'sess-1',
      onUpdate: () => {},
    })
    await flush()

    const headers = fetchMock.mock.calls[0][1]?.headers as Record<string, string>
    expect(headers['X-Widget-Id']).toBe('w-1')
    expect(headers['X-Widget-Session']).toBe('sess-1')
  })

  it('reports a vanished run as a recoverable drop instead of a dead bubble', async () => {
    // The run expired or its terminal retention lapsed. The answer may still be
    // in the database, so the caller must be pushed into history recovery.
    const { events } = collect(respondWith({ ok: false, status: 404, body: null }))

    await flush()

    expect(events).toEqual([{ status: 'error', error: 'Connection interrupted' }])
  })

  it('resumes once from the last sequence it saw before giving up', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        body: sseStream(['id: 5\ndata: {"status":"data","chunk":"half"}\n\n']),
      })
      .mockResolvedValueOnce({
        ok: true,
        status: 200,
        body: sseStream(['id: 6\ndata: {"status":"complete"}\n\n']),
      })
    vi.stubGlobal('fetch', fetchMock)

    const events: StreamUpdatePayload[] = []
    chatApi.attachStream({ runId: 'run-r', onUpdate: (data) => events.push(data) })
    await flush()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    // The retry must not replay what was already rendered.
    expect(new URL(fetchMock.mock.calls[1][0] as string).searchParams.get('from')).toBe('5')
    expect(events).toEqual([{ status: 'data', chunk: 'half' }, { status: 'complete' }])
  })

  it('treats a backend error as the end of the turn, not as a dropped connection', async () => {
    // A real failure (rate limit, cost budget, missing model) ends the turn just
    // like `complete` does. Retrying would replay the log a second time and then
    // append a bogus "Connection interrupted" over the error the user has to
    // read — and because that wording counts as recoverable, the chat view would
    // reload history and drop the explanation entirely.
    const fetchMock = respondWith({
      ok: true,
      status: 200,
      body: sseStream([
        'id: 1\ndata: {"status":"error","error":"Rate limit exceeded","limit_type":"lifetime"}\n\n',
      ]),
    })

    const events: StreamUpdatePayload[] = []
    vi.stubGlobal('fetch', fetchMock)
    chatApi.attachStream({ runId: 'run-e', onUpdate: (data) => events.push(data) })
    await flush()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(events).toEqual([
      { status: 'error', error: 'Rate limit exceeded', limit_type: 'lifetime' },
    ])
  })

  it('stops delivering events once the caller detaches', async () => {
    // Detaching is not a cancel: the turn keeps generating server-side, this
    // client just stops rendering it.
    const fetchMock = respondWith({
      ok: true,
      status: 200,
      body: sseStream(['data: {"status":"data","chunk":"a"}\n\n']),
    })
    vi.stubGlobal('fetch', fetchMock)

    const events: StreamUpdatePayload[] = []
    const stop = chatApi.attachStream({ runId: 'run-s', onUpdate: (data) => events.push(data) })
    stop()
    await flush()

    expect(events).toEqual([])
    expect(fetchMock.mock.calls[0][1]?.signal?.aborted).toBe(true)
  })
})
