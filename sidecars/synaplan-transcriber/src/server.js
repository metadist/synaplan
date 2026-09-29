import { createServer } from 'node:http'
import { WebSocketServer } from 'ws'
import { ELEMENT_CALL_LATER } from './matrix.js'
import { createMeetingSession } from './jitsiSession.js'
import { publishMeeting } from './publish.js'

function json(response, status, body) {
  const payload = JSON.stringify(body)
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(payload),
    'Cache-Control': 'no-store',
  })
  response.end(payload)
}

function authorized(request, token) {
  if (!token) {
    return false
  }
  return (request.headers.authorization || '') === `Bearer ${token}`
}

export function createApp(config, { synaplan, log = () => {} } = {}) {
  const sockets = new WebSocketServer({ noServer: true })

  const server = createServer((request, response) => {
    const url = new URL(request.url || '/', 'http://127.0.0.1')
    if (request.method === 'GET' && url.pathname === '/health') {
      json(response, 200, {
        ok: true,
        product: 'Meeting notes',
        mode: config.mode,
        jitsi: config.jitsi,
        element: config.element,
        elementCall: ELEMENT_CALL_LATER,
        synaplan: true,
      })
      return
    }
    if (request.method === 'GET' && url.pathname === '/element-call') {
      json(response, 501, {
        error: 'not_in_this_version',
        message: ELEMENT_CALL_LATER,
      })
      return
    }
    if (request.method === 'GET' && url.pathname === '/') {
      json(response, 200, {
        product: 'Meeting notes',
        mode: config.mode,
        transcribe: config.jitsi ? '/transcribe' : null,
      })
      return
    }
    json(response, 404, { error: 'not_found' })
  })

  server.on('upgrade', (request, socket, head) => {
    let url
    try {
      url = new URL(request.url || '/', 'http://127.0.0.1')
    } catch {
      socket.destroy()
      return
    }
    if (url.pathname !== '/transcribe' || !config.jitsi) {
      socket.write('HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n')
      socket.destroy()
      return
    }
    if (!authorized(request, config.authToken)) {
      socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n')
      socket.destroy()
      return
    }
    const meetingId = url.searchParams.get('sessionId') || url.searchParams.get('meetingId') || 'meeting'
    sockets.handleUpgrade(request, socket, head, (ws) => {
      const session = createMeetingSession({
        meetingId,
        synaplan,
        commitAfterMs: config.commitAfterMs,
        language: url.searchParams.get('lang') || config.language,
        model: config.model,
      })
      const send = (message) => {
        if (ws.readyState === ws.OPEN) {
          ws.send(JSON.stringify(message))
        }
      }
      ws.on('message', (data) => {
        let message
        try {
          message = JSON.parse(data.toString())
        } catch {
          return
        }
        session.handle(message, send).catch((error) => {
          log(`Meeting ${meetingId}: ${error.message}`)
        })
      })
      ws.on('close', () => {
        session.finish(send).then((result) => {
          if (!result.hadAudio && result.lines.length === 0) {
            log(`Meeting ${meetingId} ended before any audio arrived.`)
            return null
          }
          return publishMeeting({
            config,
            synaplan,
            meetingId,
            lines: result.lines,
          })
        }).then((published) => {
          if (published) {
            log(`Meeting ${meetingId}: ${published.sentence}`)
          }
        }).catch((error) => {
          log(`Meeting ${meetingId} was not saved: ${error.message}`)
        })
      })
    })
  })

  return server
}
