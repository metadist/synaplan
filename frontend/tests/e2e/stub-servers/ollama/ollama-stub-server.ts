/**
 * Ollama API stub – runs as Docker service (ollama-stub:11434).
 * Backend uses OLLAMA_BASE_URL=http://ollama-stub:11434.
 * Test runner (host) uses http://localhost:11434 for /__requests / /__reset / /__configure.
 *
 * Implements the exact Ollama API surface that OllamaProvider.php uses:
 *   GET  /api/tags   – model listing (used by getStatus, isAvailable, getAvailableModels)
 *   POST /api/chat   – chat completion (non-streaming + NDJSON streaming with thinking)
 *   POST /api/embed  – text embeddings (single + batch)
 *
 * Control endpoints (same pattern as whatsapp-stub):
 *   GET  /__requests   – return captured requests for assertions
 *   POST /__reset      – clear requests and reset configuration
 *   POST /__configure  – set stub behavior (thinking, errors, custom response)
 */

import http from 'http'

const PORT = Number(process.env.PORT) || 11434

// --- Types ---

type RequestRecord = {
  method: string
  path: string
  headers: Record<string, string>
  body: unknown
}

type StubConfig = {
  models: Array<{
    name: string
    model: string
    size: number
    modified_at: string
    digest: string
    details: Record<string, unknown>
  }>
  chatResponse: string
  enableThinking: boolean
  thinkingText: string
  streamDelayMs: number
  simulateError: { endpoint: string; statusCode: number; count: number } | null
}

// --- State ---

const requests: RequestRecord[] = []

const STUB_MODEL_DETAILS = {
  format: 'gguf',
  family: 'llama',
  parameter_size: '3B',
  quantization_level: 'Q4_0',
  families: ['llama'],
  parent_model: '',
}

const DEFAULT_CONFIG: StubConfig = {
  models: [
    {
      name: 'stub-chat-model',
      model: 'stub-chat-model',
      size: 1_000_000,
      modified_at: '2025-01-01T00:00:00Z',
      digest: 'sha256:stub-chat-digest',
      details: STUB_MODEL_DETAILS,
    },
    {
      name: 'stub-embed-model',
      model: 'stub-embed-model',
      size: 500_000,
      modified_at: '2025-01-01T00:00:00Z',
      digest: 'sha256:stub-embed-digest',
      details: STUB_MODEL_DETAILS,
    },
  ],
  chatResponse: 'Ollama stub response',
  enableThinking: false,
  thinkingText: 'Let me think about this step by step...',
  streamDelayMs: 10,
  simulateError: null,
}

let config: StubConfig = { ...DEFAULT_CONFIG, models: [...DEFAULT_CONFIG.models] }

// --- Helpers ---

function parsePath(url: string): string {
  try {
    return new URL(url, 'http://x').pathname
  } catch {
    return url
  }
}

function collectHeaders(req: http.IncomingMessage): Record<string, string> {
  const h: Record<string, string> = {}
  for (const [k, v] of Object.entries(req.headers)) {
    if (typeof v === 'string') h[k.toLowerCase()] = v
    else if (Array.isArray(v)) h[k.toLowerCase()] = v[0] ?? ''
  }
  return h
}

function getBody(obj: unknown): Record<string, unknown> | null {
  return obj != null && typeof obj === 'object' && !Array.isArray(obj)
    ? (obj as Record<string, unknown>)
    : null
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms))
}

function shouldSimulateError(endpoint: string): number | null {
  if (!config.simulateError) return null
  if (config.simulateError.endpoint !== endpoint) return null
  if (config.simulateError.count <= 0) return null
  config.simulateError.count--
  return config.simulateError.statusCode
}

function modelExists(name: string): boolean {
  const lower = name.toLowerCase()
  return config.models.some(
    (m) => m.name.toLowerCase() === lower || m.model.toLowerCase() === lower
  )
}

// --- NDJSON streaming helper ---

async function streamChat(res: http.ServerResponse, model: string): Promise<void> {
  res.writeHead(200, { 'Content-Type': 'application/x-ndjson', 'Transfer-Encoding': 'chunked' })

  // Thinking chunks (if enabled)
  if (config.enableThinking) {
    const thinkingChunks = config.thinkingText.split(' ')
    for (const word of thinkingChunks) {
      const line = JSON.stringify({
        model,
        message: { role: 'assistant', content: '', thinking: word + ' ' },
        done: false,
      })
      res.write(line + '\n')
      await sleep(config.streamDelayMs)
    }
  }

  // Content chunks
  const words = config.chatResponse.split(' ')
  for (const word of words) {
    const line = JSON.stringify({
      model,
      message: { role: 'assistant', content: word + ' ' },
      done: false,
    })
    res.write(line + '\n')
    await sleep(config.streamDelayMs)
  }

  // Done signal
  const doneLine = JSON.stringify({
    model,
    message: { role: 'assistant', content: '' },
    done: true,
    done_reason: 'stop',
    prompt_eval_count: 10,
    eval_count: 20,
  })
  res.write(doneLine + '\n')
  res.end()
}

// --- Server ---

const server = http.createServer((req, res) => {
  const path = parsePath(req.url ?? '')
  const method = req.method ?? 'GET'

  const chunks: Buffer[] = []
  req.on('data', (chunk: Buffer) => chunks.push(chunk))
  req.on('end', () => {
    let body: unknown = null
    if (chunks.length > 0) {
      const raw = Buffer.concat(chunks).toString('utf8')
      try {
        body = raw ? JSON.parse(raw) : null
      } catch {
        body = raw
      }
    }
    const bodyObj = getBody(body)
    const headers = collectHeaders(req)

    // --- Control endpoints ---

    if (method === 'GET' && path === '/__requests') {
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify(requests))
      return
    }

    if (method === 'POST' && path === '/__reset') {
      requests.length = 0
      config = { ...DEFAULT_CONFIG, models: [...DEFAULT_CONFIG.models] }
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ ok: true }))
      return
    }

    if (method === 'POST' && path === '/__configure') {
      if (bodyObj) {
        if (typeof bodyObj.chatResponse === 'string') config.chatResponse = bodyObj.chatResponse
        if (typeof bodyObj.enableThinking === 'boolean')
          config.enableThinking = bodyObj.enableThinking
        if (typeof bodyObj.thinkingText === 'string') config.thinkingText = bodyObj.thinkingText
        if (typeof bodyObj.streamDelayMs === 'number') config.streamDelayMs = bodyObj.streamDelayMs
        if (Array.isArray(bodyObj.models)) config.models = bodyObj.models as StubConfig['models']
        if (bodyObj.simulateError != null)
          config.simulateError = bodyObj.simulateError as StubConfig['simulateError']
      }
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ ok: true, config }))
      return
    }

    // Record all non-control requests
    requests.push({ method, path, headers, body })

    // --- Ollama API endpoints ---

    // GET /api/tags – model listing
    if (method === 'GET' && path === '/api/tags') {
      const errorStatus = shouldSimulateError('/api/tags')
      if (errorStatus) {
        res.writeHead(errorStatus, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: 'Simulated error' }))
        return
      }
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ models: config.models }))
      return
    }

    // POST /api/chat – chat completion
    if (method === 'POST' && path === '/api/chat') {
      const errorStatus = shouldSimulateError('/api/chat')
      if (errorStatus) {
        res.writeHead(errorStatus, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: 'Simulated error' }))
        return
      }

      const model = (bodyObj?.model as string) ?? ''
      const stream = bodyObj?.stream === true

      if (!modelExists(model)) {
        res.writeHead(404, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: `model '${model}' not found` }))
        return
      }

      if (stream) {
        streamChat(res, model)
        return
      }

      // Non-streaming response
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(
        JSON.stringify({
          model,
          message: { role: 'assistant', content: config.chatResponse },
          done: true,
          prompt_eval_count: 10,
          eval_count: 20,
        })
      )
      return
    }

    // POST /api/embed – embeddings
    if (method === 'POST' && path === '/api/embed') {
      const errorStatus = shouldSimulateError('/api/embed')
      if (errorStatus) {
        res.writeHead(errorStatus, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: 'Simulated error' }))
        return
      }

      const model = (bodyObj?.model as string) ?? ''
      const input = (bodyObj?.input as string[]) ?? []

      if (!modelExists(model)) {
        res.writeHead(404, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: `model '${model}' not found` }))
        return
      }

      // Deterministic 1024-dim embeddings (different per input for distinguishability)
      const embeddings = input.map((_text: string, idx: number) => {
        const base = 0.1 + idx * 0.01
        return Array.from({ length: 1024 }, (_, i) => Math.round((base + i * 0.001) * 1000) / 1000)
      })

      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(
        JSON.stringify({
          model,
          embeddings,
          prompt_eval_count: input.length * 8,
        })
      )
      return
    }

    // Unknown endpoint
    res.writeHead(404, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ error: `unknown endpoint: ${method} ${path}` }))
  })
})

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Ollama stub listening on ${PORT}`)
})
