/**
 * Meta WhatsApp Graph API stub – runs as Docker service (whatsapp-stub:3999).
 * Backend uses WHATSAPP_GRAPH_API_BASE_URL=http://whatsapp-stub:3999.
 * Test runner (host) uses http://localhost:3999 for __requests / __reset.
 * Enforces: Authorization Bearer (any token), Content-Type application/json for POST .../messages.
 */

import http from 'http'

const PORT = Number(process.env.PORT) || 3999
const API_VERSION = 'v21.0'
const STUB_HOST = process.env.STUB_HOST || 'whatsapp-stub'

/** Requests by runId; default key for backward compat. */
const requestsByRunId = { default: [] }
let currentRunId = 'default'
let simulateFailCount = 0

function getRequests() {
  return requestsByRunId[currentRunId] ?? (requestsByRunId[currentRunId] = [])
}

function parsePath(url) {
  try {
    return new URL(url, 'http://x').pathname
  } catch {
    return url
  }
}

function collectHeaders(req) {
  const h = {}
  for (const [k, v] of Object.entries(req.headers)) {
    h[k.toLowerCase()] = v
  }
  return h
}

const server = http.createServer((req, res) => {
  const path = parsePath(req.url || '')
  const method = req.method || 'GET'

  const chunks = []
  req.on('data', (chunk) => chunks.push(chunk))
  req.on('end', () => {
    let body = null
    if (chunks.length > 0) {
      const raw = Buffer.concat(chunks).toString('utf8')
      try {
        body = raw ? JSON.parse(raw) : null
      } catch {
        body = raw
      }
    }

    const headers = collectHeaders(req)
    const requests = getRequests()

    // Control: return requests (for test assertions). GET /__requests?runId=xxx
    if (method === 'GET' && path.startsWith('/__requests')) {
      const u = new URL(req.url || '/__requests', 'http://x')
      const runId = u.searchParams.get('runId') || currentRunId
      const list = requestsByRunId[runId] || []
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify(list))
      return
    }

    // Control: clear requests. POST /__reset body { runId?: string } sets currentRunId and clears that bucket
    if (method === 'POST' && path === '/__reset') {
      const runId = (body && body.runId) || `run-${Date.now()}`
      currentRunId = runId
      requestsByRunId[runId] = []
      simulateFailCount = 0
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ runId }))
      return
    }

    // Control: next N POST .../messages will return 500
    if (method === 'POST' && path === '/__simulate_fail') {
      const n = (body && typeof body.nextResponses === 'number') ? body.nextResponses : 1
      simulateFailCount = n
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ ok: true, nextFailCount: n }))
      return
    }

    // POST /v21.0/:phoneId/messages – enforce Authorization + Content-Type
    const postMessagesMatch = path.match(new RegExp(`^/${API_VERSION}/([^/]+)/messages$`))
    if (method === 'POST' && postMessagesMatch) {
      const auth = headers['authorization']
      if (!auth || !auth.startsWith('Bearer ')) {
        res.writeHead(401, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: { message: 'Missing or invalid Authorization' } }))
        return
      }
      const ct = headers['content-type'] || ''
      if (!ct.includes('application/json')) {
        res.writeHead(400, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: { message: 'Content-Type must be application/json' } }))
        return
      }
      requests.push({ method, path, headers, body })

      // Only simulate failure for "send message" (body.type), not markAsRead (body.status === 'read')
      const isSendMessage = body && body.type && body.status !== 'read'
      if (simulateFailCount > 0 && isSendMessage) {
        simulateFailCount--
        res.writeHead(500, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: { message: 'Stub simulated failure' } }))
        return
      }
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify({ messages: [{ id: 'stub-msg-id' }] }))
      return
    }

    getRequests().push({ method, path, headers, body })

    // GET /v21.0/:mediaId (media metadata)
    const getMediaMatch = path.match(new RegExp(`^/${API_VERSION}/([^/]+)$`))
    if (method === 'GET' && getMediaMatch) {
      const mediaId = getMediaMatch[1]
      const base = `http://${STUB_HOST}:${PORT}`
      res.writeHead(200, { 'Content-Type': 'application/json' })
      res.end(
        JSON.stringify({
          url: `${base}/download/${mediaId}`,
          mime_type: mediaId.startsWith('audio') ? 'audio/ogg' : 'image/jpeg',
          id: mediaId,
        })
      )
      return
    }

    // GET /download/:mediaId
    const downloadMatch = path.match(/^\/download\/(.+)$/)
    if (method === 'GET' && downloadMatch) {
      const mediaId = downloadMatch[1]
      const mime = mediaId.startsWith('audio') ? 'audio/ogg' : 'image/jpeg'
      res.writeHead(200, { 'Content-Type': mime })
      res.end(Buffer.from([0x00, 0x01, 0x02]))
      return
    }

    res.writeHead(404)
    res.end()
  })
})

server.listen(PORT, '0.0.0.0', () => {
  console.log(`WhatsApp stub listening on ${PORT} (STUB_HOST=${STUB_HOST})`)
})
