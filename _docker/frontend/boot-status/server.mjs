/**
 * Synaplan boot-status server (dev stack only).
 *
 * Started by the frontend container entrypoint BEFORE `npm ci`, so
 * http://localhost:5173 answers within seconds of `docker compose up -d`
 * instead of showing a connection error for minutes on a cold first boot.
 * It serves a self-contained onboarding page (index.html) plus an aggregated
 * status feed the page polls. The entrypoint stops this server right before
 * Vite takes over port 5173; the page detects the handover and reloads into
 * the real app.
 *
 * Zero dependencies on purpose: it must run before any npm install.
 *
 * Status sources (all optional, all failure-tolerant):
 *   - BACKEND_URL/api/health ............... backend fully up
 *   - <backend var dir>/boot-status.json ... backend boot phase (entrypoint milestones)
 *   - <backend var dir>/ollama-download.json  local AI model download progress
 *   - BOOT_STATUS_OLLAMA_URL/api/tags ...... Ollama reachability + installed models
 *   - tcp BOOT_STATUS_DB_HOST:3306 ......... database reachability
 *   - BOOT_PHASE_FILE ...................... this container's own phase (npm ci, schemas)
 */
import http from 'node:http'
import net from 'node:net'
import { readFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const PORT = Number(process.env.BOOT_STATUS_PORT || 5173)
const BACKEND_URL = (process.env.BACKEND_URL || 'http://backend').replace(/\/$/, '')
const OLLAMA_URL = (process.env.BOOT_STATUS_OLLAMA_URL || '').replace(/\/$/, '')
const DB_HOST = process.env.BOOT_STATUS_DB_HOST || ''
const DB_PORT = Number(process.env.BOOT_STATUS_DB_PORT || 3306)
const PHASE_FILE = process.env.BOOT_PHASE_FILE || '/tmp/synaplan-boot-phase.json'
const BACKEND_VAR_DIR = process.env.BOOT_STATUS_BACKEND_VAR || '/synaplan-backend-var'

const HTML_PATH = path.join(path.dirname(fileURLToPath(import.meta.url)), 'index.html')
const serverStartedAt = Date.now()

const readJsonFile = async (file) => {
  try {
    return JSON.parse(await readFile(file, 'utf8'))
  } catch {
    return null
  }
}

const tcpCheck = (host, port, timeoutMs = 1500) =>
  new Promise((resolve) => {
    const socket = net.connect({ host, port })
    let settled = false
    const done = (ok) => {
      if (settled) return
      settled = true
      socket.destroy()
      resolve(ok)
    }
    socket.setTimeout(timeoutMs, () => done(false))
    socket.once('connect', () => done(true))
    socket.once('error', () => done(false))
  })

const fetchJson = async (url, timeoutMs = 2500) => {
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(timeoutMs) })
    if (!res.ok) return { ok: false, status: res.status }
    return { ok: true, body: await res.json().catch(() => null) }
  } catch {
    return { ok: false }
  }
}

const collectStatus = async () => {
  const [db, health, ollamaTags, backendBoot, ollamaDownload, frontendPhase] = await Promise.all([
    DB_HOST ? tcpCheck(DB_HOST, DB_PORT) : Promise.resolve(null),
    fetchJson(`${BACKEND_URL}/api/health`),
    OLLAMA_URL ? fetchJson(`${OLLAMA_URL}/api/tags`) : Promise.resolve(null),
    readJsonFile(`${BACKEND_VAR_DIR}/boot-status.json`),
    readJsonFile(`${BACKEND_VAR_DIR}/ollama-download.json`),
    readJsonFile(PHASE_FILE),
  ])

  return {
    boot: true,
    now: Date.now(),
    serverStartedAt,
    db: DB_HOST ? { reachable: db === true } : null,
    backend: {
      healthy: Boolean(health?.ok),
      boot: backendBoot,
    },
    localAi: OLLAMA_URL
      ? {
          reachable: Boolean(ollamaTags?.ok),
          installedModels: (ollamaTags?.body?.models || []).map((m) => m?.name).filter(Boolean),
          download: ollamaDownload,
        }
      : null,
    frontend: frontendPhase || { phase: 'init' },
  }
}

const server = http.createServer(async (req, res) => {
  const url = (req.url || '/').split('?')[0]

  if (url === '/boot-status.json') {
    const status = await collectStatus()
    res.writeHead(200, {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
      'X-Synaplan-Boot': '1',
    })
    res.end(JSON.stringify(status))
    return
  }

  // Any other path gets the onboarding page (read fresh so edits show up
  // without a container restart while developing the page itself).
  try {
    const html = await readFile(HTML_PATH)
    res.writeHead(200, {
      'Content-Type': 'text/html; charset=utf-8',
      'Cache-Control': 'no-store',
      'X-Synaplan-Boot': '1',
    })
    res.end(html)
  } catch (err) {
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8', 'X-Synaplan-Boot': '1' })
    res.end(`Synaplan is starting... (boot-status page unavailable: ${err?.message})`)
  }
})

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[boot-status] onboarding page listening on :${PORT} (until Vite takes over)`)
})

// Exit fast on shutdown so the entrypoint can hand port 5173 to Vite without
// waiting for keep-alive sockets to drain.
for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => {
    console.log(`[boot-status] ${signal} received, releasing :${PORT} for the app`)
    process.exit(0)
  })
}
