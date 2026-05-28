'use strict'

const http = require('http')
const { WebSocketServer } = require('ws')
const jwt = require('jsonwebtoken')

const PORT = parseInt(process.env.WS_PORT || '3002', 10)
const INTERNAL_PORT = parseInt(process.env.WS_INTERNAL_PORT || '3002', 10)
const JWT_SECRET = process.env.TOKEN_SECRET || 'change_me_in_production_use_openssl_rand_hex_32'

// userId -> Set<WebSocket>
const clients = new Map()

function addClient(userId, ws) {
  if (!clients.has(userId)) {
    clients.set(userId, new Set())
  }
  clients.get(userId).add(ws)
}

function removeClient(userId, ws) {
  const set = clients.get(userId)
  if (set) {
    set.delete(ws)
    if (set.size === 0) {
      clients.delete(userId)
    }
  }
}

function notifyUser(userId, payload) {
  const set = clients.get(userId)
  if (!set || set.size === 0) return 0

  const message = JSON.stringify(payload)
  let sent = 0
  for (const ws of set) {
    if (ws.readyState === ws.OPEN) {
      ws.send(message)
      sent++
    }
  }
  return sent
}

// HTTP server handles both WebSocket upgrade and internal notify endpoint
const server = http.createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/internal/notify') {
    let body = ''
    req.on('data', chunk => { body += chunk })
    req.on('end', () => {
      try {
        const payload = JSON.parse(body)
        const userId = payload.user_id
        if (!userId) {
          res.writeHead(400, { 'Content-Type': 'application/json' })
          res.end(JSON.stringify({ error: 'user_id required' }))
          return
        }
        const sent = notifyUser(userId, payload)
        res.writeHead(200, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ sent }))
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ error: e.message }))
      }
    })
    return
  }

  if (req.method === 'GET' && req.url === '/health') {
    const totalClients = Array.from(clients.values()).reduce((sum, set) => sum + set.size, 0)
    res.writeHead(200, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify({ status: 'ok', connections: totalClients, users: clients.size }))
    return
  }

  res.writeHead(404)
  res.end()
})

const wss = new WebSocketServer({ server })

wss.on('connection', (ws, req) => {
  // Extract token from query string: ws://host:port?token=JWT
  const url = new URL(req.url, `http://localhost:${PORT}`)
  const token = url.searchParams.get('token')

  if (!token) {
    ws.close(4001, 'Authentication required')
    return
  }

  let userId
  try {
    const decoded = jwt.verify(token, JWT_SECRET)
    userId = decoded.user_id || decoded.sub || decoded.id
    if (!userId) throw new Error('No user ID in token')
  } catch (e) {
    ws.close(4003, 'Invalid token')
    return
  }

  addClient(userId, ws)
  ws.userId = userId

  ws.on('close', () => {
    removeClient(userId, ws)
  })

  ws.on('error', () => {
    removeClient(userId, ws)
  })

  // Heartbeat
  ws.isAlive = true
  ws.on('pong', () => { ws.isAlive = true })
})

// Ping interval to detect dead connections
const pingInterval = setInterval(() => {
  wss.clients.forEach(ws => {
    if (!ws.isAlive) {
      removeClient(ws.userId, ws)
      return ws.terminate()
    }
    ws.isAlive = false
    ws.ping()
  })
}, 30000)

wss.on('close', () => clearInterval(pingInterval))

server.listen(INTERNAL_PORT, '0.0.0.0', () => {
  console.log(`[ws-server] WebSocket + HTTP notify on port ${INTERNAL_PORT}`)
  console.log(`[ws-server] Health: GET /health`)
  console.log(`[ws-server] Notify: POST /internal/notify`)
})
