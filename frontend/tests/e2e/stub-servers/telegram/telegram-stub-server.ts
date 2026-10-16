/**
 * Telegram Bot API stub. Backend uses TELEGRAM_API_BASE_URL=http://telegram-stub:3998.
 * The test runner uses http://localhost:3998 for __requests / __reset.
 * A token whose secret part contains INVALID makes getMe return 401.
 * POST /__blocked?on=1 makes every send answer 403 like a bot the user
 * blocked; /__reset clears it.
 *
 * Files: getFile maps a file_id prefix to a fixture (photo_* → PNG,
 * doc_* → PDF); a file_id starting with "huge" has no download path, like
 * a file above Telegram's 20 MB bot limit. GET /file/bot<token>/<path>
 * serves the fixture. Uploads (sendPhoto, sendDocument, …) are multipart;
 * the stub records their text fields and the uploaded file name.
 */

import http from 'http'

const PORT = Number(process.env.PORT) || 3998

// 1×1 transparent PNG
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64'
)
const PDF = Buffer.from(
  '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
    '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n' +
    '4 0 obj<</Length 44>>stream\nBT /F1 12 Tf 20 100 Td (Invoice total 42 EUR) Tj ET\nendstream endobj\n' +
    '5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n',
  'latin1'
)

const UPLOAD_METHODS = ['sendPhoto', 'sendVideo', 'sendAudio', 'sendVoice', 'sendDocument']
const OK_METHODS = [
  'setWebhook',
  'deleteWebhook',
  'sendChatAction',
  'setMyCommands',
  'editMessageReplyMarkup',
  'answerCallbackQuery',
]

type RequestRecord = {
  method: string
  path: string
  headers: Record<string, string>
  body: unknown
}

const requestsByRunId: Record<string, RequestRecord[]> = { default: [] }
let currentRunId = 'default'
let blocked = false
let nextMessageId = 1

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

/** Text fields of a multipart body plus `file`: the uploaded file name. */
function parseMultipart(raw: Buffer, contentType: string): Record<string, string> {
  const boundary = /boundary=("?)([^";]+)\1/.exec(contentType)?.[2]
  if (!boundary) return {}
  const fields: Record<string, string> = {}
  for (const part of raw.toString('latin1').split(`--${boundary}`)) {
    const headerEnd = part.indexOf('\r\n\r\n')
    if (headerEnd < 0) continue
    const header = part.slice(0, headerEnd)
    const name = /name="([^"]+)"/.exec(header)?.[1]
    if (!name) continue
    const filename = /filename="([^"]*)"/.exec(header)?.[1]
    if (filename !== undefined) {
      fields.file = filename
      fields.field = name
      continue
    }
    fields[name] = Buffer.from(part.slice(headerEnd + 4).replace(/\r\n$/, ''), 'latin1').toString(
      'utf8'
    )
  }
  return fields
}

function fixtureFor(filePath: string): Buffer | null {
  if (filePath.startsWith('photos/')) return PNG
  if (filePath.startsWith('documents/')) return PDF
  return null
}

const server = http.createServer((req, res) => {
  const path = parsePath(req.url ?? '')
  const method = req.method ?? 'GET'
  const chunks: Buffer[] = []
  req.on('data', (chunk: Buffer) => chunks.push(chunk))
  req.on('end', () => {
    const headers = collectHeaders(req)
    const raw = Buffer.concat(chunks)
    let body: unknown = null
    if ((headers['content-type'] ?? '').startsWith('multipart/form-data')) {
      body = parseMultipart(raw, headers['content-type'] ?? '')
    } else if (raw.length > 0) {
      const text = raw.toString('utf8')
      try {
        body = text ? JSON.parse(text) : null
      } catch {
        body = text
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

    getRequests().push({ method, path, headers, body })

    const file = path.match(/^\/file\/bot[^/]+\/(.+)$/)
    if (file && method === 'GET') {
      const content = fixtureFor(file[1] ?? '')
      if (!content) {
        send(res, 404, { ok: false, error_code: 404, description: 'Not Found' })
        return
      }
      res.writeHead(200, { 'Content-Type': 'application/octet-stream' })
      res.end(content)
      return
    }

    const match = path.match(/^\/bot([^/]+)\/(\w+)$/)
    if (!match || method !== 'POST') {
      send(res, 404, { ok: false, error_code: 404, description: 'Not Found' })
      return
    }

    const token = match[1] ?? ''
    const apiMethod = match[2] ?? ''
    const payload =
      body != null && typeof body === 'object' ? (body as Record<string, unknown>) : {}
    if (token.includes('INVALID') && apiMethod === 'getMe') {
      send(res, 401, { ok: false, error_code: 401, description: 'Unauthorized' })
      return
    }

    const sends =
      apiMethod === 'sendMessage' ||
      apiMethod === 'sendChatAction' ||
      UPLOAD_METHODS.includes(apiMethod)
    if (blocked && sends) {
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

    if (OK_METHODS.includes(apiMethod)) {
      send(res, 200, { ok: true, result: true })
      return
    }

    if (apiMethod === 'getFile') {
      const fileId = String(payload.file_id ?? '')
      if (fileId.startsWith('huge')) {
        send(res, 200, { ok: true, result: { file_id: fileId, file_size: 30_000_000 } })
        return
      }
      const folder = fileId.startsWith('doc_') ? 'documents' : 'photos'
      const extension = folder === 'documents' ? 'pdf' : 'png'
      send(res, 200, {
        ok: true,
        result: { file_id: fileId, file_path: `${folder}/${fileId}.${extension}` },
      })
      return
    }

    if (apiMethod === 'editMessageText') {
      send(res, 200, {
        ok: true,
        result: {
          message_id: payload.message_id,
          chat: { id: payload.chat_id },
          text: payload.text,
        },
      })
      return
    }

    if (apiMethod === 'sendMessage' || UPLOAD_METHODS.includes(apiMethod)) {
      send(res, 200, {
        ok: true,
        result: {
          message_id: nextMessageId++,
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
