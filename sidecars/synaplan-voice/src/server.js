import http from 'node:http'
import { WebSocketServer } from 'ws'
import { adapterFor } from './adapters/index.js'
import { handleConnection } from './session.js'

export function createServer(options = {}) {
  const deps = {
    synaplanUrl: options.synaplanUrl ?? process.env.SYNAPLAN_URL ?? 'http://backend',
    secret: options.secret ?? process.env.LIVE_VOICE_GATEWAY_SECRET ?? '',
    allowedOrigins: options.allowedOrigins ?? process.env.LIVE_VOICE_ALLOWED_ORIGINS ?? '',
    tickSeconds: options.tickSeconds ?? process.env.TICK_SECONDS ?? 15,
    idleSeconds: options.idleSeconds ?? process.env.IDLE_SECONDS ?? 120,
    reconnectGraceMs: options.reconnectGraceMs,
    fetch: options.fetch ?? globalThis.fetch,
    adapterFor: options.adapterFor ?? ((provider, model) => adapterFor(provider, process.env, model)),
    openaiApiKey: options.openaiApiKey ?? process.env.OPENAI_API_KEY ?? '',
    liveHttpUrl: options.liveHttpUrl,
    realtimeHttpUrl: options.realtimeHttpUrl,
    sidebandUrlFor: options.sidebandUrlFor,
    WebSocketImpl: options.WebSocketImpl,
    log: options.log ?? ((fields) => {
      console.log(JSON.stringify({ service: 'synaplan-voice', ...fields }))
    }),
  }

  const server = http.createServer((req, res) => {
    if (req.url === '/health') {
      res.writeHead(200, { 'content-type': 'application/json' })
      res.end(JSON.stringify({ ok: true }))
      return
    }
    res.writeHead(404)
    res.end()
  })

  const wss = new WebSocketServer({ server, path: '/voice/ws' })
  wss.on('connection', (socket, request) => handleConnection(socket, request, deps))
  return server
}
