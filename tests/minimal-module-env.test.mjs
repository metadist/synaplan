import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')

function keysInEnvMinimal() {
  const raw = readFileSync(join(root, 'backend/.env.minimal'), 'utf8')
  return [...raw.matchAll(/^([A-Z][A-Z0-9_]*)=/gm)].map((m) => m[1]).sort()
}

function keysInComposeOverlay() {
  const raw = readFileSync(join(root, 'docker-compose.minimal.yml'), 'utf8')
  return [...raw.matchAll(/^\s+([A-Z][A-Z0-9_]*):/gm)].map((m) => m[1]).sort()
}

test('minimal overlay files list the same env keys', () => {
  const envKeys = keysInEnvMinimal()
  const composeKeys = keysInComposeOverlay()
  assert.deepEqual(
    composeKeys,
    envKeys,
    'backend/.env.minimal and docker-compose.minimal.yml must stay in lockstep'
  )
  assert.ok(envKeys.includes('TIKA_BASE_URL'))
  assert.ok(envKeys.includes('FEATURE_MODULES_GATE_WHATSAPP'))
  assert.ok(envKeys.includes('WHATSAPP_ENABLED'))
})
