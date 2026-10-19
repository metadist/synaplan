// Starts server.mjs on a random port and walks every route once. Exits non-zero on any mismatch.
import { spawn } from 'node:child_process'
import { createHash, randomUUID } from 'node:crypto'
import { fileURLToPath } from 'node:url'

const SERVER = fileURLToPath(new URL('./server.mjs', import.meta.url))
const POW_BITS = 8
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
let failures = 0
let passes = 0

function check(name, condition, detail = '') {
  if (condition) {
    passes++
    console.log(`  ok   ${name}`)
  } else {
    failures++
    console.log(`  FAIL ${name}${detail ? ` -- ${detail}` : ''}`)
  }
}

const sameKeys = (obj, expected) => JSON.stringify(Object.keys(obj ?? {}).sort()) === JSON.stringify([...expected].sort())

function powOk(installId, nonce, bits) {
  const hash = createHash('sha256').update(`${installId}:${nonce}`).digest()
  let need = bits
  let index = 0
  for (; need >= 8; need -= 8, index++) if (hash[index] !== 0) return false
  return need === 0 || (hash[index] & ((0xff << (8 - need)) & 0xff)) === 0
}

function findNonce(installId, wanted) {
  for (let i = 0; ; i++) if (powOk(installId, String(i), POW_BITS) === wanted) return String(i)
}

function startServer(env) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [SERVER], { env: { ...process.env, PORT: '0', ...env }, stdio: ['ignore', 'pipe', 'inherit'] })
    child.stdout.on('data', (data) => {
      const match = /listening on (\d+)/.exec(String(data))
      if (match) resolve({ child, base: `http://127.0.0.1:${match[1]}` })
    })
    child.on('exit', (code) => reject(new Error(`server exited early with ${code}`)))
  })
}

async function call(base, method, path, { key, body, headers = {}, raw } = {}) {
  const init = { method, headers: { ...headers } }
  if (key) init.headers.Authorization = `Bearer ${key}`
  if (raw !== undefined) init.body = raw
  else if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(body)
  }
  const res = await fetch(base + path, init)
  const text = await res.text()
  let json = null
  try { json = JSON.parse(text) } catch { /* SSE or plain text */ }
  return { status: res.status, headers: res.headers, text, json }
}

async function mainSuite() {
  console.log('main server')
  const { child, base } = await startServer({ FAKE_POW_BITS: String(POW_BITS), FAKE_MESSAGES_PER_DAY: '2', FAKE_IMAGES_PER_DAY: '1' })
  try {
    const health = await call(base, 'GET', '/healthz')
    check('healthz answers ok', health.status === 200 && health.text === 'ok')

    const installId = randomUUID()
    const payload = { install_id: installId, platform: 'dev', version: '1.0.0', protocol: 1, terms_version: '2026-10',
      locale: 'en', host_kind: 'localhost', host_hash: 'a'.repeat(64) }

    const invalid = await call(base, 'POST', '/v1/free/keys', { body: { ...payload, install_id: 'nope', pow_nonce: '1' } })
    check('invalid install_id is 400 invalid_request', invalid.status === 400 && invalid.json?.error?.param === 'install_id'
      && invalid.json.error.message === 'Invalid install_id.' && invalid.json.error.code === 'invalid_request')

    const badPow = await call(base, 'POST', '/v1/free/keys', { body: { ...payload, pow_nonce: findNonce(installId, false) } })
    check('bad proof of work is freeapi_pow_required', badPow.status === 400 && badPow.json?.error?.code === 'freeapi_pow_required')

    const nonce = findNonce(installId, true)
    const issued = await call(base, 'POST', '/v1/free/keys', { body: { ...payload, pow_nonce: nonce } })
    const b = issued.json ?? {}
    check('issuance returns 200', issued.status === 200, issued.text)
    check('issuance body has the DemoService shape', sameKeys(b, ['key', 'key_id', 'expires_at', 'days_left', 'limits', 'aliases', 'terms_url', 'base_url', 'protocol_min']))
    check('key format and ids', /^sk-freeapi-[0-9a-f]+$/.test(b.key) && Number.isInteger(b.key_id) && b.days_left === 14 && b.protocol_min === 1)
    check('limits block', sameKeys(b.limits, ['messages_per_day', 'images_per_day', 'cost_per_day_usd', 'embed_tokens_per_day', 'key_ttl_days']))
    check('aliases are {id,name}', b.aliases?.length === 5 && b.aliases.every((a) => sameKeys(a, ['id', 'name'])))
    check('base_url defaults to the compose host', b.base_url === 'http://freeapi-fake:8787')
    const key = b.key

    const again = await call(base, 'POST', '/v1/free/keys', { body: { ...payload, pow_nonce: nonce } })
    check('issuance is idempotent per install_id', again.json?.key === key && again.json?.key_id === b.key_id)

    const status = await call(base, 'GET', '/v1/free/status', { key })
    check('status has the DemoService shape', status.status === 200 && sameKeys(status.json, ['service', 'messages_remaining', 'messages_limit',
      'images_remaining', 'images_limit', 'resets_at', 'expires_at', 'days_left', 'protocol_min', 'base_url']))
    check('status counts are fresh', status.json?.service === 'ok' && status.json.messages_remaining === 2 && status.json.resets_at > Date.now() / 1000)

    const models = await call(base, 'GET', '/v1/models', { key })
    check('models lists the aliases', models.json?.object === 'list' && models.json.data.length === 5
      && models.json.data.every((m) => m.object === 'model' && m.owned_by === 'synaplan-demo'))

    const chat = await call(base, 'POST', '/v1/chat/completions', { key, body: { model: 'free-chat', messages: [{ role: 'user', content: 'hello' }] } })
    check('chat completion echoes the user', chat.json?.object === 'chat.completion' && chat.json.choices[0].message.content === 'Synaplan Demo (fake): hello'
      && chat.json.usage?.total_tokens > 0)
    check('chat carries rate-limit headers', chat.headers.get('x-ratelimit-remaining-requests') === '1' && chat.headers.get('x-ratelimit-limit-requests') === '2'
      && Number(chat.headers.get('x-ratelimit-reset')) > 0)

    const wrong = await call(base, 'POST', '/v1/chat/completions', { key, body: { model: 'free-embed', messages: [{ role: 'user', content: 'x' }] } })
    check('non-chat alias on chat is freeapi_not_included', wrong.status === 403 && wrong.json?.error?.code === 'freeapi_not_included')

    const stream = await call(base, 'POST', '/v1/chat/completions', { key, body: { model: 'free-chat', stream: true, messages: [{ role: 'user', content: 'stream me' }] } })
    const events = stream.text.split('\n\n').filter(Boolean).map((e) => e.replace(/^data: /, ''))
    const content = events.slice(0, -1).map((e) => JSON.parse(e).choices[0].delta.content ?? '').join('')
    check('stream is SSE ending with [DONE]', stream.headers.get('content-type') === 'text/event-stream' && events.at(-1) === '[DONE]')
    check('stream chunks reassemble the answer', content === 'Synaplan Demo (fake): stream me', content)

    const limited = await call(base, 'POST', '/v1/chat/completions', { key, body: { model: 'free-chat', messages: [{ role: 'user', content: 'x' }] } })
    check('messages over the daily limit are freeapi_limit_reached', limited.status === 429 && limited.json?.error?.code === 'freeapi_limit_reached'
      && Number(limited.headers.get('retry-after')) > 0)

    const sort = await call(base, 'POST', '/v1/chat/completions', { key, body: { model: 'free-sort', messages: [{ role: 'user', content: 'x' }] } })
    check('counts_as=call aliases are not message-limited', sort.status === 200)

    const emb = await call(base, 'POST', '/v1/embeddings', { key, body: { model: 'free-embed', input: ['a', 'b', 'a'] } })
    const vectors = emb.json?.data?.map((d) => d.embedding) ?? []
    check('embeddings are 1024-dim and deterministic', vectors.length === 3 && vectors.every((v) => v.length === 1024)
      && JSON.stringify(vectors[0]) === JSON.stringify(vectors[2]) && JSON.stringify(vectors[0]) !== JSON.stringify(vectors[1]))
    const emb64 = await call(base, 'POST', '/v1/embeddings', { key, body: { model: 'free-embed', input: 'a', encoding_format: 'base64' } })
    const buf = Buffer.from(emb64.json?.data?.[0]?.embedding ?? '', 'base64')
    const decoded = Array.from({ length: buf.length / 4 }, (_, i) => buf.readFloatLE(i * 4))
    check('base64 embeddings decode to the same float32 vector', decoded.length === 1024 && decoded.every((v, i) => v === vectors[0][i]))

    const img = await call(base, 'POST', '/v1/images/generations', { key, body: { model: 'free-image', prompt: 'cat', response_format: 'b64_json' } })
    check('image returns the 1x1 PNG', img.json?.data?.[0]?.b64_json === PNG_1X1)
    const img2 = await call(base, 'POST', '/v1/images/generations', { key, body: { model: 'free-image', prompt: 'cat' } })
    check('images over the daily limit are freeapi_limit_reached', img2.status === 429 && img2.json?.error?.code === 'freeapi_limit_reached')

    const forced = await call(base, 'GET', '/v1/models', { key, headers: { 'X-Fake-Error': 'freeapi_paused' } })
    check('X-Fake-Error forces the contract error', forced.status === 503 && forced.json?.error?.type === 'server_error'
      && forced.json.error.param === null && forced.headers.get('retry-after') === '60')

    const big = await call(base, 'POST', '/v1/embeddings', { key, raw: 'x'.repeat(1024 * 1024 + 10), headers: { 'Content-Type': 'application/json' } })
    check('bodies over 1 MiB are rejected', big.status === 413)

    const unknown = await call(base, 'POST', '/v1/audio/speech', { key, body: {} })
    check('unknown route is 404 OpenAI shape', unknown.status === 404 && typeof unknown.json?.error?.message === 'string')
    const anonymous = await call(base, 'GET', '/v1/models')
    check('missing bearer is 401 invalid_api_key', anonymous.status === 401 && anonymous.json?.error?.code === 'invalid_api_key')

    const revoked = await call(base, 'DELETE', '/v1/free/keys/self', { key })
    check('revoke answers {revoked:true}', revoked.status === 200 && revoked.json?.revoked === true)
    const after = await call(base, 'GET', '/v1/free/status', { key })
    check('revoked key is 401 invalid_api_key', after.status === 401 && after.json?.error?.code === 'invalid_api_key')
  } finally {
    child.kill()
  }
}

async function forcedSuite() {
  console.log('server with FAKE_FORCE_ERROR=freeapi_full_today')
  const { child, base } = await startServer({ FAKE_POW_BITS: String(POW_BITS), FAKE_FORCE_ERROR: 'freeapi_full_today' })
  try {
    const installId = randomUUID()
    const issued = await call(base, 'POST', '/v1/free/keys', { body: { install_id: installId, platform: 'selfhost', protocol: 1,
      host_kind: 'public', host_hash: 'b'.repeat(64), pow_nonce: findNonce(installId, true) } })
    check('issuance is not affected by FAKE_FORCE_ERROR', issued.status === 200)
    const chat = await call(base, 'POST', '/v1/chat/completions', { key: issued.json?.key, body: { model: 'free-chat', messages: [{ role: 'user', content: 'x' }] } })
    check('gateway answers the forced error', chat.status === 429 && chat.json?.error?.code === 'freeapi_full_today' && Number(chat.headers.get('retry-after')) > 0)
  } finally {
    child.kill()
  }
}

try {
  await mainSuite()
  await forcedSuite()
} catch (err) {
  failures++
  console.error(err)
}
console.log(`\n${passes} passed, ${failures} failed`)
process.exit(failures === 0 ? 0 : 1)
