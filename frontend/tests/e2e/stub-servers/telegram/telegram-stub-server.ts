/**
 * Telegram Bot API stub. Backend uses TELEGRAM_API_BASE_URL=http://telegram-stub:3998.
 * The test runner uses http://localhost:3998 for __requests / __reset.
 * A token whose secret part contains INVALID makes getMe return 401.
 * POST /__blocked?on=1 makes sendMessage and sendChatAction answer 403 like a
 * bot the user blocked; /__reset clears it.
 */

import http from 'http'

const PORT = Number(process.env.PORT) || 3998

type RequestRecord = {
  method: string
  path: string
  headers: Record<string, string>
  body: unknown
}

const requestsByRunId: Record<string, RequestRecord[]> = { default: [] }
let currentRunId = 'default'
let blocked = false

function getRequests(): RequestRecord[] {
  return requestsByRunId[currentRunId] ?? (requestsByRunId[currentRunId] = [])
}

function parsePath(url: string): string {
  try {
    return decodeURIComponent(new URL(url, 'http://x').pathname)
  } catch {
    return url
  }
}

function collectHeaders(req: http.IncomingMessage): Record<string, string> {
  const headers: Record<string, string> = {}
  for (const [key, value] of Object.entries(req.headers)) {
    if (typeof value === 'string') headers[key.toLowerCase()] = value
    else if (Array.isArray(value)) headers[key.toLowerCase()] = value[0] ?? ''
  }
  return headers
}

function send(res: http.ServerResponse, status: number, body: unknown): void {
  res.writeHead(status, { 'Content-Type': 'application/json' })
  res.end(JSON.stringify(body))
}

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

    if (method === 'GET' && path.startsWith('/__requests')) {
      const url = new URL(req.url ?? '/__requests', 'http://x')
      const runId = url.searchParams.get('runId') ?? currentRunId
      send(res, 200, requestsByRunId[runId] ?? [])
      return
    }

    if (method === 'POST' && path === '/__reset') {
      const url = new URL(req.url ?? '/__reset', 'http://x')
      const runId = url.searchParams.get('runId')
      if (runId) {
        currentRunId = runId
        requestsByRunId[runId] = []
      } else {
        requestsByRunId[currentRunId] = []
      }
      blocked = false
      send(res, 200, { ok: true })
      return
    }

    if (method === 'POST' && path === '/__blocked') {
      const url = new URL(req.url ?? '/__blocked', 'http://x')
      blocked = url.searchParams.get('on') === '1'
      send(res, 200, { ok: true, blocked })
      return
    }

    const headers = collectHeaders(req)
    getRequests().push({ method, path, headers, body })

    const match = path.match(/^\/bot([^/]+)\/(\w+)$/)
    if (!match || method !== 'POST') {
      send(res, 404, { ok: false, error_code: 404, description: 'Not Found' })
      return
    }

    const token = match[1] ?? ''
    const apiMethod = match[2]
    if (token.includes('INVALID') && apiMethod === 'getMe') {
      send(res, 401, { ok: false, error_code: 401, description: 'Unauthorized' })
      return
    }

    if (blocked && (apiMethod === 'sendMessage' || apiMethod === 'sendChatAction')) {
      send(res, 403, {
        ok: false,
        error_code: 403,
        description: 'Forbidden: bot was blocked by the user',
      })
      return
    }

    if (apiMethod === 'getMe') {
      send(res, 200, {
        ok: true,
        result: { id: 4242, is_bot: true, first_name: 'Synaplan', username: 'synaplan_test_bot' },
      })
      return
    }

    if (
      apiMethod === 'setWebhook' ||
      apiMethod === 'deleteWebhook' ||
      apiMethod === 'sendChatAction'
    ) {
      send(res, 200, { ok: true, result: true })
      return
    }

    if (apiMethod === 'sendMessage') {
      const payload =
        body != null && typeof body === 'object' ? (body as Record<string, unknown>) : {}
      send(res, 200, {
        ok: true,
        result: {
          message_id: getRequests().length,
          chat: { id: payload.chat_id },
          text: payload.text,
        },
      })
      return
    }

    send(res, 400, { ok: false, error_code: 400, description: 'Bad Request' })
  })
})

server.listen(PORT, '0.0.0.0', () => {
  process.stdout.write(`telegram stub listening on ${PORT}\n`)
})
