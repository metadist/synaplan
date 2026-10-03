import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { chmodSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const script = join(root, 'scripts/check-project-env.sh')

const composeVariables = [
  'NAME                    REQUIRED  DEFAULT VALUE           ALTERNATE VALUE',
  'COMPOSE_PROFILES        false',
  'LOCK_DSN                false     redis://redis:6379',
  'OLLAMA_BASE_URL         false',
  'SYNAPLAN_FRONTEND_PORT  false     5173',
].join('\n')

function runCheck(envContent, { dockerOutput = composeVariables, dockerExit = 0 } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'check-project-env-'))
  try {
    const bin = join(dir, 'bin')
    const fakeDocker = join(bin, 'docker')
    mkdirSync(bin)
    writeFileSync(join(dir, 'docker-output.txt'), `${dockerOutput}\n`)
    writeFileSync(
      fakeDocker,
      `#!/usr/bin/env bash\ncat "${join(dir, 'docker-output.txt')}"\nexit ${dockerExit}\n`
    )
    chmodSync(fakeDocker, 0o755)

    const envFile = join(dir, '.env')
    writeFileSync(envFile, envContent)

    const result = spawnSync('bash', [script, envFile], {
      encoding: 'utf8',
      env: { ...process.env, PATH: `${bin}:${process.env.PATH}` },
    })
    return { status: result.status, stderr: result.stderr, stdout: result.stdout }
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }
}

test('documented knobs alone stay silent', () => {
  const result = runCheck('SYNAPLAN_FRONTEND_PORT=5174\nCOMPOSE_PROFILES=local-ai\n# comment\n')
  assert.equal(result.status, 0)
  assert.equal(result.stderr, '')
})

test('a copied backend env reports overrides and ignored settings by name only', () => {
  const result = runCheck(
    [
      'APP_ENV=test',
      'APP_SECRET=super-secret-value',
      'export LOCK_DSN=flock',
      'OLLAMA_BASE_URL=http://ollama_test:11434',
      'SYNAPLAN_FRONTEND_PORT=5173',
    ].join('\n')
  )
  assert.equal(result.status, 0)
  assert.match(result.stderr, /replaces 2 Docker default\(s\)/)
  assert.match(result.stderr, /LOCK_DSN, OLLAMA_BASE_URL/)
  assert.match(result.stderr, /has 2 setting\(s\) Docker Compose never reads/)
  assert.match(result.stderr, /APP_ENV, APP_SECRET/)
  assert.doesNotMatch(result.stderr, /super-secret-value|flock|ollama_test/)
  assert.doesNotMatch(result.stderr, /SYNAPLAN_FRONTEND_PORT/)
})

test('a misspelled port name is reported as ignored', () => {
  const result = runCheck('SYNAPLAN_FRONTED_PORT=5174\n')
  assert.equal(result.status, 0)
  assert.match(result.stderr, /has 1 setting\(s\) Docker Compose never reads/)
  assert.match(result.stderr, /SYNAPLAN_FRONTED_PORT/)
})

test('documented port names stay silent even when this stack does not read them', () => {
  const result = runCheck('SYNAPLAN_KEYCLOAK_HTTP_PORT=8081\nSYNAPLAN_OLLAMA_PORT=11436\n')
  assert.equal(result.status, 0)
  assert.equal(result.stderr, '')
})

test('long lists are truncated', () => {
  const keys = Array.from({ length: 11 }, (_, i) => `UNUSED_${String(i).padStart(2, '0')}=x`)
  const result = runCheck(keys.join('\n'))
  assert.match(result.stderr, /has 11 setting\(s\)/)
  assert.match(result.stderr, /UNUSED_07, \+3 more/)
})

test('a Compose without config --variables never blocks the start', () => {
  const result = runCheck('LOCK_DSN=flock\n', { dockerOutput: 'unknown flag: --variables', dockerExit: 1 })
  assert.equal(result.status, 0)
  assert.equal(result.stderr, '')
})

test('a missing env file is fine', () => {
  const result = spawnSync('bash', [script, join(tmpdir(), 'does-not-exist.env')], { encoding: 'utf8' })
  assert.equal(result.status, 0)
  assert.equal(result.stderr, '')
})
