// Offline fake of the Synaplan Demo (FreeAPI) server for local development and tests.
// Dependency-free: node:http, node:crypto and node:fs only. All state is in memory.
import { createServer } from 'node:http'
import { createHash, randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const DEFAULT_CONTRACT = fileURLToPath(new URL('../../backend/tests/Fixtures/freeapi-contract/v1/', import.meta.url))
const CONTRACT_DIR = resolve(process.env.CONTRACT_DIR || DEFAULT_CONTRACT)
const load = (name) => JSON.parse(readFileSync(resolve(CONTRACT_DIR, name), 'utf8'))
const ALIASES = load('aliases.json').aliases
const ERRORS = load('errors.json')
const LIMITS = load('limits.json')

const envInt = (name, fallback) => (process.env[name] ? Number.parseInt(process.env[name], 10) : fallback)
const PORT = envInt('PORT', 8787)
const POW_BITS = envInt('FAKE_POW_BITS', LIMITS.pow_bits)
const MESSAGES_PER_DAY = envInt('FAKE_MESSAGES_PER_DAY', LIMITS.messages_per_day)
const IMAGES_PER_DAY = envInt('FAKE_IMAGES_PER_DAY', LIMITS.images_per_day)
const PROTOCOL_MIN = envInt('FAKE_PROTOCOL_MIN', 1)
const BASE_URL = process.env.FAKE_BASE_URL || 'http://freeapi-fake:8787'
const TERMS_URL = process.env.FAKE_TERMS_URL || 'https://web.synaplan.com/demo/terms'
const FORCE_ERROR = process.env.FAKE_FORCE_ERROR || ''
const MAX_BODY = 1024 * 1024
const EMBED_DIM = 1024
const STARTED = Math.floor(Date.now() / 1000)
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
const PLATFORMS = ['dev', 'minimal', 'quickstart', 'lite', 'selfhost', 'helm']
const HOST_KINDS = ['localhost', 'private', 'public']

const installs = new Map() // install_id -> key record
const keys = new Map() // secret -> key record
let nextKeyId = 1000

const now = () => Math.floor(Date.now() / 1000)
const sha256 = (input) => createHash('sha256').update(input).digest()

function berlinDay(t) {
  return new Intl.DateTimeFormat('en-CA', { timeZone: LIMITS.day_timezone }).format(new Date(t * 1000))
}

function wallClockAsUtc(t) {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: LIMITS.day_timezone, hourCycle: 'h23', year: 'numeric', month: '2-digit',
    day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }).formatToParts(new Date(t * 1000))
  const p = Object.fromEntries(parts.map((x) => [x.type, Number(x.value)]))
  return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second) / 1000
}

function nextMidnight(t) {
  const [y, m, d] = berlinDay(t).split('-').map(Number)
  const guess = Date.UTC(y, m - 1, d + 1) / 1000
  return guess - (wallClockAsUtc(guess) - guess)
}

function verifyPow(installId, nonce, bits) {
  if (bits < 1 || bits > 32 || nonce === '' || nonce.length > 128 || !/^[0-9a-f-]{36}$/.test(installId)) return false
  const hash = sha256(`${installId}:${nonce}`)
  let need = bits
  let index = 0
  for (; need >= 8; need -= 8, index++) if (hash[index] !== 0) return false
  return need === 0 || (hash[index] & ((0xff << (8 - need)) & 0xff)) === 0
}

function parseIssuance(body) {
  const str = (v, max) => (typeof v === 'string' && v.trim().length <= max ? v.trim() : '')
  const installId = str(body.install_id, 36)
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(installId)) return { error: 'install_id' }
  if (!PLATFORMS.includes(str(body.platform, 32))) return { error: 'platform' }
  const numeric = typeof body.protocol === 'number' || (typeof body.protocol === 'string' && /^\s*[+-]?\d+(\.\d+)?\s*$/.test(body.protocol))
  const protocol = numeric ? Math.trunc(Number(body.protocol)) : 0
  if (protocol < 1) return { error: 'protocol' }
  const locale = str(body.locale, 16)
  if (locale !== '' && !/^[A-Za-z-]{2,16}$/.test(locale)) return { error: 'locale' }
  if (!HOST_KINDS.includes(str(body.host_kind, 16))) return { error: 'host_kind' }
  if (!/^[0-9a-f]{64}$/.test(str(body.host_hash, 64).toLowerCase())) return { error: 'host_hash' }
  const nonce = str(body.pow_nonce, 128)
  if (nonce === '') return { error: 'pow_nonce' }
  const email = typeof body.contact_email === 'string' ? body.contact_email.trim() : ''
  if (email !== '' && (email.length > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) return { error: 'contact_email' }
  return { installId, protocol, nonce }
}

const openAiError = (status, code, message, param = null, type = 'invalid_request_error') => ({
  status, body: { error: { message, type, param, code } },
})

function freeapiError(code) {
  const entry = ERRORS[code]
  const res = openAiError(entry.http, code, entry.message, null, entry.type)
  if (entry.http === 429 || entry.http === 503) {
    const untilMidnight = nextMidnight(now()) - now()
    res.headers = { 'Retry-After': String(['freeapi_limit_reached', 'freeapi_full_today'].includes(code) ? untilMidnight : 60) }
  }
  return res
}

const invalidKey = () => openAiError(401, 'invalid_api_key', 'Invalid API key.')

function usage(record) {
  const day = berlinDay(now())
  if (record.day !== day) Object.assign(record, { day, messages: 0, images: 0, embedTokens: 0 })
  return record
}

function rateHeaders(record) {
  return {
    'x-ratelimit-limit-requests': String(MESSAGES_PER_DAY),
    'x-ratelimit-remaining-requests': String(Math.max(0, MESSAGES_PER_DAY - record.messages)),
    'x-ratelimit-limit-images': String(IMAGES_PER_DAY),
    'x-ratelimit-remaining-images': String(Math.max(0, IMAGES_PER_DAY - record.images)),
    'x-ratelimit-reset': String(nextMidnight(now())),
  }
}

function issue(body) {
  const payload = parseIssuance(body && typeof body === 'object' && !Array.isArray(body) ? body : {})
  if (payload.error === 'pow_nonce') return freeapiError('freeapi_pow_required')
  if (payload.error) return openAiError(400, 'invalid_request', `Invalid ${payload.error}.`, payload.error)
  if (payload.protocol < PROTOCOL_MIN) return freeapiError('freeapi_upgrade_required')
  let record = installs.get(payload.installId)
  if (!record || record.revoked || record.expiresAt <= now()) {
    if (!verifyPow(payload.installId, payload.nonce, POW_BITS)) return freeapiError('freeapi_pow_required')
    record = { secret: `sk-freeapi-${randomBytes(24).toString('hex')}`, keyId: nextKeyId++, installId: payload.installId,
      expiresAt: now() + LIMITS.key_ttl_days * 86400, revoked: false, calls: 0 }
    installs.set(record.installId, record)
    keys.set(record.secret, record)
  }
  return { status: 200, body: {
    key: record.secret, key_id: record.keyId, expires_at: record.expiresAt,
    days_left: Math.max(0, Math.ceil((record.expiresAt - now()) / 86400)),
    limits: { messages_per_day: MESSAGES_PER_DAY, images_per_day: IMAGES_PER_DAY, cost_per_day_usd: LIMITS.cost_per_day_usd,
      embed_tokens_per_day: LIMITS.embed_tokens_per_day, key_ttl_days: LIMITS.key_ttl_days },
    aliases: ALIASES.map((a) => ({ id: a.id, name: a.label })),
    terms_url: TERMS_URL, base_url: BASE_URL, protocol_min: PROTOCOL_MIN,
  } }
}

function status(record) {
  const t = now()
  return { status: 200, body: {
    service: 'ok',
    messages_remaining: Math.max(0, MESSAGES_PER_DAY - record.messages), messages_limit: MESSAGES_PER_DAY,
    images_remaining: Math.max(0, IMAGES_PER_DAY - record.images), images_limit: IMAGES_PER_DAY,
    resets_at: nextMidnight(t), expires_at: record.expiresAt,
    days_left: Math.max(0, Math.ceil((record.expiresAt - t) / 86400)), protocol_min: PROTOCOL_MIN, base_url: BASE_URL,
  } }
}

function lastUserText(messages) {
  const last = [...messages].reverse().find((m) => m && m.role === 'user')
  if (!last) return ''
  if (typeof last.content === 'string') return last.content
  return Array.isArray(last.content) ? last.content.filter((p) => p && p.type === 'text').map((p) => p.text).join(' ') : ''
}

function chat(record, body, alias) {
  if (!Array.isArray(body.messages) || body.messages.length === 0) return openAiError(400, 'invalid_request', 'Invalid messages.', 'messages')
  if (alias.counts_as === 'message') {
    if (record.messages >= MESSAGES_PER_DAY) return freeapiError('freeapi_limit_reached')
    record.messages++
  }
  const text = `Synaplan Demo (fake): ${lastUserText(body.messages)}`
  const id = `chatcmpl-${randomBytes(12).toString('hex')}`
  const created = now()
  const promptTokens = Math.ceil(JSON.stringify(body.messages).length / 4)
  const completionTokens = Math.ceil(text.length / 4)
  const usageBlock = { prompt_tokens: promptTokens, completion_tokens: completionTokens, total_tokens: promptTokens + completionTokens }
  if (body.stream !== true) {
    return { status: 200, body: { id, object: 'chat.completion', created, model: alias.id,
      choices: [{ index: 0, message: { role: 'assistant', content: text }, finish_reason: 'stop' }], usage: usageBlock } }
  }
  const chunk = (delta, finish = null, extra = {}) =>
    `data: ${JSON.stringify({ id, object: 'chat.completion.chunk', created, model: alias.id, choices: [{ index: 0, delta, finish_reason: finish }], ...extra })}\n\n`
  const parts = [chunk({ role: 'assistant', content: '' })]
  for (const word of text.match(/\S+\s*/g) || []) parts.push(chunk({ content: word }))
  parts.push(chunk({}, 'stop', body.stream_options?.include_usage ? { usage: usageBlock } : {}))
  parts.push('data: [DONE]\n\n')
  return { status: 200, stream: parts }
}

function embedVector(text) {
  const out = new Array(EMBED_DIM)
  let norm = 0
  for (let block = 0; block * 32 < EMBED_DIM * 2; block++) {
    const bytes = sha256(`${text}#${block}`)
    for (let i = 0; i < 16 && block * 16 + i < EMBED_DIM; i++) {
      const v = bytes.readUInt16LE(i * 2) / 32767.5 - 1
      out[block * 16 + i] = v
      norm += v * v
    }
  }
  norm = Math.sqrt(norm) || 1
  return out.map((v) => Math.fround(v / norm))
}

function embeddings(record, body) {
  const inputs = (Array.isArray(body.input) ? body.input : [body.input]).map((i) => (typeof i === 'string' ? i : JSON.stringify(i)))
  if (body.input === undefined || inputs.length === 0 || inputs.length > LIMITS.max_embed_inputs) return openAiError(400, 'invalid_request', 'Invalid input.', 'input')
  const tokens = inputs.reduce((sum, s) => sum + Math.ceil(s.length / 4), 0)
  if (record.embedTokens + tokens > LIMITS.embed_tokens_per_day) return freeapiError('freeapi_limit_reached')
  record.embedTokens += tokens
  const data = inputs.map((input, index) => {
    const vector = embedVector(input)
    if (body.encoding_format !== 'base64') return { object: 'embedding', index, embedding: vector }
    const buf = Buffer.alloc(EMBED_DIM * 4)
    vector.forEach((v, i) => buf.writeFloatLE(v, i * 4))
    return { object: 'embedding', index, embedding: buf.toString('base64') }
  })
  return { status: 200, body: { object: 'list', data, model: 'free-embed', usage: { prompt_tokens: tokens, total_tokens: tokens } } }
}

function images(record, body) {
  const n = Number.isInteger(body.n) && body.n > 0 ? body.n : 1
  if (record.images + n > IMAGES_PER_DAY) return freeapiError('freeapi_limit_reached')
  record.images += n
  const item = body.response_format === 'b64_json' ? { b64_json: PNG_1X1 } : { url: `data:image/png;base64,${PNG_1X1}` }
  return { status: 200, body: { created: now(), data: Array.from({ length: n }, () => ({ ...item })) } }
}

const ROUTE_OF = { '/v1/chat/completions': 'chat', '/v1/embeddings': 'embeddings', '/v1/images/generations': 'images' }

function gateway(record, path, body) {
  if (path === '/v1/models') {
    return { status: 200, body: { object: 'list', data: ALIASES.map((a) => ({ id: a.id, object: 'model', created: STARTED, owned_by: 'synaplan-demo' })) } }
  }
  if (!body || typeof body !== 'object' || Array.isArray(body)) return openAiError(400, 'invalid_json', 'Invalid JSON body.')
  const alias = ALIASES.find((a) => a.id === body.model && a.route === ROUTE_OF[path])
  if (!alias) return freeapiError('freeapi_not_included')
  return { chat, embeddings, images }[alias.route](record, body, alias)
}

async function readBody(req) {
  const chunks = []
  let size = 0
  for await (const chunk of req) {
    size += chunk.length
    if (size <= MAX_BODY) chunks.push(chunk)
  }
  if (size > MAX_BODY) return { tooLarge: true }
  if (size === 0) return { value: {} }
  try { return { value: JSON.parse(Buffer.concat(chunks).toString('utf8')) } } catch { return { value: null } }
}

async function handle(req) {
  const path = new URL(req.url, 'http://fake').pathname
  const route = `${req.method} ${path}`
  if (route === 'GET /healthz') return { status: 200, text: 'ok' }
  const isGateway = route === 'GET /v1/models' || (req.method === 'POST' && path in ROUTE_OF)
  const known = isGateway || ['POST /v1/free/keys', 'GET /v1/free/status', 'DELETE /v1/free/keys/self'].includes(route)
  if (!known) return openAiError(404, 'not_found', `Unknown route: ${route}`)
  const body = await readBody(req)
  if (body.tooLarge) return { ...openAiError(413, 'payload_too_large', 'Request body exceeds 1 MiB.'), headers: { Connection: 'close' } }
  const forced = [req.headers['x-fake-error'], isGateway || route === 'GET /v1/free/status' ? FORCE_ERROR : ''].find((c) => c && ERRORS[c])
  if (route === 'POST /v1/free/keys') return forced ? freeapiError(forced) : issue(body.value)
  const auth = /^Bearer\s+(\S+)$/i.exec(req.headers.authorization || '')
  const record = auth ? keys.get(auth[1]) : undefined
  if (!record || record.revoked) return invalidKey()
  usage(record).calls++
  const withHeaders = (res) => ({ ...res, headers: { ...rateHeaders(record), ...res.headers } })
  if (forced) return withHeaders(freeapiError(forced))
  if (record.expiresAt <= now()) return withHeaders(freeapiError('freeapi_expired'))
  if (route === 'DELETE /v1/free/keys/self') {
    record.revoked = true
    keys.delete(record.secret)
    return { status: 200, body: { revoked: true } }
  }
  if (route === 'GET /v1/free/status') return withHeaders(status(record))
  const protocol = req.headers['x-synaplan-protocol']
  if (protocol !== undefined && Number.parseInt(protocol, 10) < PROTOCOL_MIN) return withHeaders(freeapiError('freeapi_upgrade_required'))
  return withHeaders(gateway(record, path, body.value))
}

const server = createServer(async (req, res) => {
  let result
  try {
    result = await handle(req)
  } catch (err) {
    console.error(err)
    result = openAiError(500, 'internal_error', 'The fake server failed.', null, 'server_error')
  }
  const headers = result.headers || {}
  if (result.stream) {
    res.writeHead(result.status, { ...headers, 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache' })
    for (const part of result.stream) res.write(part)
    return res.end()
  }
  if (result.text !== undefined) {
    res.writeHead(result.status, { ...headers, 'Content-Type': 'text/plain' })
    return res.end(result.text)
  }
  res.writeHead(result.status, { ...headers, 'Content-Type': 'application/json' })
  res.end(JSON.stringify(result.body))
})

server.listen(PORT, '0.0.0.0', () => {
  console.log(`freeapi-fake listening on ${server.address().port} (contract ${CONTRACT_DIR}, pow_bits ${POW_BITS})`)
})

for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => server.close(() => process.exit(0)))
