/**
 * WhatsApp smoke: stub runs as Docker service (whatsapp-stub:3999).
 * Test runner hits stub at getStubBaseUrl() (localhost:3999) for __requests / __reset.
 */

const API_VERSION = 'v21.0'
export const OUTBOUND_WAIT_MS = 5000
export const STABILITY_WINDOW_MS = 2000
export const POLL_MS = 200

export interface StubRequestRecord {
  method: string
  path: string
  headers: Record<string, string>
  body: unknown
}

export function getStubBaseUrl(): string {
  return process.env.WHATSAPP_STUB_URL || 'http://localhost:3999'
}

/** Throws on non-200 (fail-fast). */
export async function getStubRequests(
  request: { get: (url: string) => Promise<{ status: () => number; json: () => Promise<StubRequestRecord[]> }> },
  baseUrl = getStubBaseUrl(),
  runId?: string
): Promise<StubRequestRecord[]> {
  const q = runId ? `?runId=${encodeURIComponent(runId)}` : ''
  const res = await request.get(`${baseUrl}/__requests${q}`)
  if (res.status() !== 200) {
    throw new Error(`Stub __requests returned ${res.status()}`)
  }
  return res.json()
}

export async function resetStub(
  request: { post: (url: string, opts?: { data?: object }) => Promise<{ status: () => number }> },
  baseUrl = getStubBaseUrl(),
  runId?: string
): Promise<string> {
  const body = runId ? { runId } : {}
  const res = await request.post(`${baseUrl}/__reset`, { data: body })
  if (res.status() !== 200) throw new Error(`Stub __reset returned ${res.status()}`)
  return runId ?? 'default'
}

export async function simulateStubFail(
  request: { post: (url: string, opts: { data?: object }) => Promise<{ status: () => number }> },
  baseUrl: string,
  nextResponses: number
): Promise<void> {
  const res = await request.post(`${baseUrl}/__simulate_fail`, { data: { nextResponses } })
  if (res.status() !== 200) throw new Error(`Stub __simulate_fail returned ${res.status()}`)
}

function isSendMessageRequest(r: StubRequestRecord): boolean {
  if (r.method !== 'POST' || !new RegExp(`^/${API_VERSION}/[^/]+/messages$`).test(r.path)) return false
  const body = r.body as Record<string, unknown> | null
  return body != null && 'type' in body && body.status !== 'read'
}

export function countPostMessages(requests: StubRequestRecord[]): number {
  return requests.filter(isSendMessageRequest).length
}

export function getPostMessageRecords(requests: StubRequestRecord[]): StubRequestRecord[] {
  return requests.filter(isSendMessageRequest)
}

export function getPostMessageBodies(requests: StubRequestRecord[]): Array<Record<string, unknown>> {
  return getPostMessageRecords(requests).map((r) => (r.body as Record<string, unknown>) ?? {})
}

export function assertStubContract(
  requests: StubRequestRecord[],
  options: {
    expectedPathExact?: string
    expectedBearerToken?: string
    expectedContentType?: string
  }
): void {
  const posts = getPostMessageRecords(requests)
  if (posts.length === 0) throw new Error('No POST .../messages requests to assert')
  const r = posts[0]
  if (options.expectedPathExact !== undefined) {
    if (r.path !== options.expectedPathExact) {
      throw new Error(`Expected path ${options.expectedPathExact}, got ${r.path}`)
    }
  }
  if (options.expectedBearerToken !== undefined) {
    const auth = r.headers['authorization']
    const expected = `Bearer ${options.expectedBearerToken}`
    if (auth !== expected) {
      throw new Error(`Expected Authorization ${expected}, got ${auth}`)
    }
  }
  if (options.expectedContentType !== undefined) {
    const ct = r.headers['content-type']
    if (!ct || !ct.includes(options.expectedContentType)) {
      throw new Error(`Expected Content-Type to contain ${options.expectedContentType}, got ${ct}`)
    }
  }
}

export async function waitUntil(
  check: () => boolean | Promise<boolean>,
  options: { timeoutMs: number; pollMs?: number }
): Promise<boolean> {
  const { timeoutMs, pollMs = POLL_MS } = options
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    if (await Promise.resolve(check())) return true
    await new Promise((r) => setTimeout(r, pollMs))
  }
  return false
}

export async function awaitOutboundExactlyOnce(
  request: Parameters<typeof getStubRequests>[0],
  baseUrl: string,
  options: { waitMs?: number; stabilityWindowMs?: number; runId?: string } = {}
): Promise<StubRequestRecord[]> {
  const waitMs = options.waitMs ?? OUTBOUND_WAIT_MS
  const stabilityWindowMs = options.stabilityWindowMs ?? STABILITY_WINDOW_MS
  const runId = options.runId

  const ok = await waitUntil(
    async () => {
      const reqs = await getStubRequests(request, baseUrl, runId)
      return countPostMessages(reqs) === 1
    },
    { timeoutMs: waitMs, pollMs: POLL_MS }
  )
  if (!ok) {
    const reqs = await getStubRequests(request, baseUrl, runId)
    throw new Error(
      `Expected exactly 1 POST .../messages within ${waitMs}ms, got ${countPostMessages(reqs)}`
    )
  }

  /* Stability window: ensure terminal state (no extra outbound) before returning. */
  const windowDeadline = Date.now() + stabilityWindowMs
  while (Date.now() < windowDeadline) {
    const reqs = await getStubRequests(request, baseUrl, runId)
    if (countPostMessages(reqs) !== 1) {
      throw new Error(`Stability window: expected count to stay 1, got ${countPostMessages(reqs)}`)
    }
    await new Promise((r) => setTimeout(r, POLL_MS))
  }

  const reqs = await getStubRequests(request, baseUrl, runId)
  if (countPostMessages(reqs) !== 1) {
    throw new Error(`After stability window: expected 1 POST, got ${countPostMessages(reqs)}`)
  }
  return reqs
}

export async function assertNoSecondOutboundWithinWindow(
  request: Parameters<typeof getStubRequests>[0],
  baseUrl: string,
  options: { stabilityWindowMs?: number; runId?: string } = {}
): Promise<void> {
  const stabilityWindowMs = options.stabilityWindowMs ?? STABILITY_WINDOW_MS
  const runId = options.runId
  const windowDeadline = Date.now() + stabilityWindowMs
  while (Date.now() < windowDeadline) {
    const reqs = await getStubRequests(request, baseUrl, runId)
    if (countPostMessages(reqs) !== 1) {
      throw new Error(`Idempotency: expected count to stay 1, got ${countPostMessages(reqs)}`)
    }
    await new Promise((r) => setTimeout(r, POLL_MS))
  }
}

export function makeRunId(testId?: string): string {
  return testId ? `run-${testId}` : `run-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}
