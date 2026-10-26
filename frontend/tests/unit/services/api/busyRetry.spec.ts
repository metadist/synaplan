import { describe, expect, it, vi } from 'vitest'
import { fetchWithBusyRetry, isServerBusyBody, retryAfterMs } from '@/services/api/busyRetry'

const busyResponse = (retryAfter?: string) =>
  new Response(JSON.stringify({ error: 'server_busy', retryAfter: 5 }), {
    status: 503,
    headers: retryAfter ? { 'Retry-After': retryAfter } : {},
  })

describe('busy retry transport', () => {
  it('recognises every busy body shape and nothing else', () => {
    expect(isServerBusyBody('{"error":"server_busy","retryAfter":5}')).toBe(true)
    expect(isServerBusyBody('{"error":{"code":"server_busy"}}')).toBe(true)
    expect(
      isServerBusyBody('{"type":"error","error":{"type":"overloaded_error","message":"busy"}}')
    ).toBe(true)
    expect(isServerBusyBody('{"error":"SETUP_REQUIRED"}')).toBe(false)
    expect(isServerBusyBody('not json')).toBe(false)
  })

  it('reads Retry-After and falls back to five seconds', () => {
    expect(retryAfterMs(new Headers({ 'Retry-After': '2' }))).toBe(2000)
    expect(retryAfterMs(new Headers())).toBe(5000)
    expect(retryAfterMs(new Headers({ 'Retry-After': 'soon' }))).toBe(5000)
  })

  it('waits the Retry-After delay and returns the admitted response', async () => {
    const admitted = new Response('data: ok', { status: 200 })
    const attempt = vi
      .fn<() => Promise<Response>>()
      .mockResolvedValueOnce(busyResponse('2'))
      .mockResolvedValueOnce(busyResponse())
      .mockResolvedValueOnce(admitted)
    const sleeps: number[] = []
    const busyCalls: number[] = []

    const response = await fetchWithBusyRetry(attempt, {
      sleep: async (ms) => {
        sleeps.push(ms)
      },
      jitterMs: () => 0,
      onBusy: (retry) => busyCalls.push(retry),
    })

    expect(response).toBe(admitted)
    expect(attempt).toHaveBeenCalledTimes(3)
    expect(sleeps).toEqual([2000, 5000])
    expect(busyCalls).toEqual([1, 2])
  })

  it('stops after three retries and returns the last busy response', async () => {
    const attempt = vi.fn<() => Promise<Response>>().mockImplementation(async () => busyResponse())
    const busyCalls: number[] = []

    const response = await fetchWithBusyRetry(attempt, {
      sleep: async () => {},
      jitterMs: () => 0,
      onBusy: (retry) => busyCalls.push(retry),
    })

    expect(response.status).toBe(503)
    expect(attempt).toHaveBeenCalledTimes(4)
    expect(busyCalls).toEqual([1, 2, 3])
  })

  it('never retries another status or a 503 without the busy body', async () => {
    const serverError = vi
      .fn<() => Promise<Response>>()
      .mockResolvedValue(new Response('boom', { status: 500 }))
    await fetchWithBusyRetry(serverError, { sleep: async () => {}, jitterMs: () => 0 })
    expect(serverError).toHaveBeenCalledTimes(1)

    const setupRequired = vi
      .fn<() => Promise<Response>>()
      .mockResolvedValue(new Response(JSON.stringify({ error: 'SETUP_REQUIRED' }), { status: 503 }))
    await fetchWithBusyRetry(setupRequired, { sleep: async () => {}, jitterMs: () => 0 })
    expect(setupRequired).toHaveBeenCalledTimes(1)
  })
})
