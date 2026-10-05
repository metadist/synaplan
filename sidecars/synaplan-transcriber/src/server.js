import { createServer } from 'node:http'
import { WebSocketServer } from 'ws'
import { ELEMENT_CALL_LATER } from './matrix.js'
import { createMeetingSession } from './jitsiSession.js'
import { createPluginSession, isSessionRef } from './pluginSession.js'
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

function refuse(socket, status, reason) {
  socket.write(`HTTP/1.1 ${status} ${reason}\r\nConnection: close\r\n\r\n`)
  socket.destroy()
}

/**
 * synaScriber plugin mode: the room's metadata put `notes=<ref>` on the
 * bridge URL. The plugin must confirm a live session for this meeting
 * before any audio is accepted; afterwards every window goes to the plugin.
 * `connected` is what moves the plugin session from starting to running.
 */
function upgradePlugin({ sockets, request, socket, head, ref, meetingId, plugin, config, log }) {
  plugin.bind(ref, meetingId).then((binding) => {
    if (!binding) {
      log(`Notes ${ref}: no active session, connection refused.`)
      refuse(socket, 410, 'Gone')
      return
    }
    sockets.handleUpgrade(request, socket, head, (ws) => {
      const session = createPluginSession({
        ref,
        meetingId,
        language: binding.language || config.language,
        sessionId: binding.sessionId || '',
        captions: binding.captions !== false,
        speakers: binding.speakers || {},
        glossary: Array.isArray(binding.glossary) ? binding.glossary : [],
        client: plugin,
        commitAfterMs: config.commitAfterMs,
      })
      const send = (message) => {
        if (ws.readyState === ws.OPEN) {
          ws.send(JSON.stringify(message))
        }
      }
      const pending = []
      let ready = false
      const dispatch = (data) => {
        let message
        try {
          message = JSON.parse(data.toString())
        } catch {
          return
        }
        session.handle(message, send).catch((error) => {
          log(`Notes ${ref}: ${error.message}`)
        })
      }
      ws.on('message', (data) => {
        if (!ready) {
          pending.push(data)
          return
        }
        dispatch(data)
      })
      ws.on('close', () => {
        if (!ready) {
          return
        }
        session.finish(send).then((result) => {
          const stats = session.stats()
          log(`Notes ${ref}: ${result.state} after ${stats.windows} windows from ${stats.speakers} speakers (${stats.failures} failed, ${stats.gaps} gaps).`)
        }).catch((error) => {
          log(`Notes ${ref} were not finished: ${error.message}`)
        })
      })
      session.connect().then(() => {
        ready = true
        log(`Notes ${ref}: bridge connected (${binding.language}).`)
        for (const data of pending) {
          dispatch(data)
        }
      }).catch((error) => {
        log(`Notes ${ref}: connected event failed (${error.message}), connection closed.`)
        ws.close()
      })
    })
  }).catch((error) => {
    log(`Notes ${ref}: Synaplan not reachable (${error.message}), connection refused.`)
    refuse(socket, 503, 'Service Unavailable')
  })
}

export function createApp(config, { synaplan, plugin = null, log = () => {} } = {}) {
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
    const notesRef = url.searchParams.get('notes')
    if (notesRef) {
      if (!plugin || !isSessionRef(notesRef)) {
        refuse(socket, 400, 'Bad Request')
        return
      }
      const meetingId = url.searchParams.get('sessionId') || url.searchParams.get('meetingId') || ''
      upgradePlugin({ sockets, request, socket, head, ref: notesRef, meetingId, plugin, config, log })
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
