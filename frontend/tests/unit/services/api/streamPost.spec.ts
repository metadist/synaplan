import { afterEach, describe, expect, it, vi } from 'vitest'
import { runStreamPost } from '@/services/api/streamPost'
import type { StreamUpdatePayload } from '@/types/chatStream'

describe('runStreamPost', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('does not rebuild a drop that never received a run id', async () => {
    const fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
    const events: StreamUpdatePayload[] = []
    const body = new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(new TextEncoder().encode('data: {"status":"data","chunk":"Hi"}\n\n'))
        controller.close()
      },
    })

    await runStreamPost({
      onUpdate: (data) => events.push(data),
      doFetch: async () => new Response(body, { status: 200 }),
      signal: new AbortController().signal,
      isStopped: () => false,
    })

    expect(fetchMock).not.toHaveBeenCalled()
    expect(events.some((event) => event.status === 'reconnecting')).toBe(false)
    expect(events.some((event) => event.status === 'stream_rebuild')).toBe(false)
    expect(events.at(-1)).toEqual({ status: 'error', error: 'Connection interrupted' })
  })
})
