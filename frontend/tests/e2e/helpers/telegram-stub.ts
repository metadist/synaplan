/**
 * Telegram smoke: stub runs as Docker service (telegram-stub:3998).
 * The test runner hits getTelegramStubBaseUrl() for __requests / __reset.
 */

export interface TelegramStubRequest {
  method: string
  path: string
  headers: Record<string, string>
  body: unknown
}

type RequestLike = {
  get: (
    url: string
  ) => Promise<{ status: () => number; json: () => Promise<TelegramStubRequest[]> }>
  post: (url: string) => Promise<{ status: () => number }>
}

export function getTelegramStubBaseUrl(): string {
  return process.env.TELEGRAM_STUB_URL || 'http://localhost:3998'
}

export async function resetTelegramStub(request: RequestLike, runId: string): Promise<void> {
  const res = await request.post(
    `${getTelegramStubBaseUrl()}/__reset?runId=${encodeURIComponent(runId)}`
  )
  if (res.status() !== 200) {
    throw new Error(`Telegram stub __reset returned ${res.status()}`)
  }
}

export async function setTelegramStubBlocked(
  request: RequestLike,
  blocked: boolean
): Promise<void> {
  const res = await request.post(`${getTelegramStubBaseUrl()}/__blocked?on=${blocked ? '1' : '0'}`)
  if (res.status() !== 200) {
    throw new Error(`Telegram stub __blocked returned ${res.status()}`)
  }
}

export async function getTelegramStubRequests(
  request: RequestLike,
  runId?: string
): Promise<TelegramStubRequest[]> {
  const query = runId ? `?runId=${encodeURIComponent(runId)}` : ''
  const res = await request.get(`${getTelegramStubBaseUrl()}/__requests${query}`)
  if (res.status() !== 200) {
    throw new Error(`Telegram stub __requests returned ${res.status()}`)
  }
  return res.json()
}
