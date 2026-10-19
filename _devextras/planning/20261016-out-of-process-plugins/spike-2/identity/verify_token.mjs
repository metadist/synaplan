// Review spike (2026-10-18) — not product code. Verifies the 02_contracts.md §3
// call-token vector the way a Node.js service could, with WebCrypto only (no package).
//
//   node verify_token.mjs vector.json
//
// A real service checks the clock with Date.now(); the vector pins "now".
import { readFileSync } from 'node:fs'
import { createHash, webcrypto } from 'node:crypto'

const TOKEN_TYPE = 'synaplan-call+jwt'
const NOW = 1792130030
const LEEWAY = 30

const fromB64url = (s) => Buffer.from(s, 'base64url')
const b64url = (buf) => Buffer.from(buf).toString('base64url')

async function verify(jwks, token, audience, method, url, body, now) {
  const [h, p, s] = token.split('.')
  const header = JSON.parse(fromB64url(h).toString())
  if (header.typ !== TOKEN_TYPE || header.alg !== 'ES256') throw new Error('wrong typ or alg')
  const jwk = jwks.keys.find((k) => k.kid === header.kid)
  if (!jwk) throw new Error('unknown key id')
  const key = await webcrypto.subtle.importKey('jwk', jwk, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
  const valid = await webcrypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, key, fromB64url(s), Buffer.from(`${h}.${p}`))
  if (!valid) throw new Error('signature verification failed')
  const claims = JSON.parse(fromB64url(p).toString())
  if (claims.aud !== audience) throw new Error('wrong audience')
  if (now > claims.exp + LEEWAY || claims.iat > now + LEEWAY) throw new Error('expired or not yet valid')
  if (claims.htm !== method || claims.htu !== url) throw new Error('wrong method or URL')
  if (claims.bh !== b64url(createHash('sha256').update(body).digest())) throw new Error('body hash mismatch')
  return claims
}

const vector = JSON.parse(readFileSync(process.argv[2], 'utf8'))
const { method, url, body } = vector.request
const [h, p, s] = vector.token.split('.')
const forged = `${h}.${b64url(Buffer.from(fromB64url(p).toString().replace(vector.claims.sub, 'u_' + '0'.repeat(32))))}.${s}`
const cases = [
  ['valid', vector.token, url, method, body, NOW, true],
  ['changed body', vector.token, url, method, body.replace('hello', 'HELLO'), NOW, false],
  ['other audience', vector.token, 'http://other-tool:8000/mcp', method, body, NOW, false],
  ['other method', vector.token, url, 'DELETE', body, NOW, false],
  ['forged subject', forged, url, method, body, NOW, false],
  ['expired (now + 170 s)', vector.token, url, method, body, NOW + 170, false],
]
let failed = 0
for (const [label, token, audience, m, b, now, expectOk] of cases) {
  let reason = null
  try {
    await verify(vector.jwks, token, audience, m, url, b, now)
  } catch (e) {
    reason = e.message
  }
  const ok = (reason === null) === expectOk
  if (!ok) failed++
  console.log(`node   ${(label + ':').padEnd(24)} ${ok ? 'as expected' : 'UNEXPECTED'}${reason === null ? ' (accepted)' : ` (refused: ${reason})`}`)
}
process.exit(failed ? 1 : 0)
