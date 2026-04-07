/**
 * Ollama stub helpers — stub runs as Docker service (ollama-stub:11434).
 * Test runner hits stub at getStubBaseUrl() (localhost:11434) for /__requests / /__reset / /__configure.
 */

export interface StubRequestRecord {
  method: string
  path: string
  headers: Record<string, string>
  body: unknown
}

export interface StubConfig {
  chatResponse?: string
  enableThinking?: boolean
  thinkingText?: string
  streamDelayMs?: number
  models?: Array<{ name: string; model: string; size: number; details: Record<string, unknown> }>
  simulateError?: { endpoint: string; statusCode: number; count: number } | null
}

type RequestLike = {
  get: (url: string) => Promise<{ status: () => number; json: () => Promise<unknown> }>
  post: (
    url: string,
    opts?: { data?: object }
  ) => Promise<{ status: () => number; json: () => Promise<unknown> }>
}

export function getStubBaseUrl(): string {
  return process.env.OLLAMA_STUB_URL || 'http://localhost:11434'
}

export async function getStubRequests(
  request: RequestLike,
  baseUrl = getStubBaseUrl()
): Promise<StubRequestRecord[]> {
  const res = await request.get(`${baseUrl}/__requests`)
  if (res.status() !== 200) {
    throw new Error(`Ollama stub __requests returned ${res.status()}`)
  }
  return (await res.json()) as StubRequestRecord[]
}

export async function resetStub(request: RequestLike, baseUrl = getStubBaseUrl()): Promise<void> {
  const res = await request.post(`${baseUrl}/__reset`)
  if (res.status() !== 200) {
    throw new Error(`Ollama stub __reset returned ${res.status()}`)
  }
}

export async function configureStub(
  request: RequestLike,
  config: StubConfig,
  baseUrl = getStubBaseUrl()
): Promise<void> {
  const res = await request.post(`${baseUrl}/__configure`, { data: config })
  if (res.status() !== 200) {
    throw new Error(`Ollama stub __configure returned ${res.status()}`)
  }
}

export function getChatRequests(requests: StubRequestRecord[]): StubRequestRecord[] {
  return requests.filter((r) => r.method === 'POST' && r.path === '/api/chat')
}

export function getEmbedRequests(requests: StubRequestRecord[]): StubRequestRecord[] {
  return requests.filter((r) => r.method === 'POST' && r.path === '/api/embed')
}

export function getTagsRequests(requests: StubRequestRecord[]): StubRequestRecord[] {
  return requests.filter((r) => r.method === 'GET' && r.path === '/api/tags')
}
