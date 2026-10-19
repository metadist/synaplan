import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

// The two dev compose files start in two waves: what chat needs first, every
// optional service once the backend is healthy. A wave-1 service that waits
// for a wave-2 one would undo that, and a wave-2 service that the backend
// itself waits for would never start at all.

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')

const WAVE_1 = new Set(['db', 'redis', 'centrifugo', 'backend', 'worker', 'scheduler', 'tika', 'frontend', 'frontend-widgets', 'startup-notes'])
const WAVE_2 = new Set([
  'ollama', 'qdrant', 'whisper-models', 'tts', 'docling', 'searxng', 'collabora',
  'compute', 'compute-node', 'compute-python', 'voice', 'transcriber',
  'phpmyadmin', 'mailhog', 'keycloak', 'keycloak_certs',
])

const DEV_FILES = ['docker-compose.yml', 'docker-compose-minimal.yml']
const ALL_FILES = [...DEV_FILES, 'deploy/compose.yaml', 'deploy/quickstart/compose.yaml']

/** service name → { dependency → condition } for the top-level `services:` block. */
const dependencies = (text) => {
  const lines = text.split('\n')
  const start = lines.indexOf('services:')
  assert.ok(start >= 0, 'no services block')
  const services = new Map()
  let current = null
  let inDepends = false
  let lastDependency = null
  for (const line of lines.slice(start + 1)) {
    if (/^[A-Za-z]/.test(line)) break
    const header = /^ {2}([A-Za-z0-9_-]+):\s*$/.exec(line)
    if (header) {
      current = header[1]
      services.set(current, {})
      inDepends = false
      continue
    }
    if (!current) continue
    if (/^ {4}depends_on:\s*$/.test(line)) {
      inDepends = true
      continue
    }
    if (inDepends && /^ {4}\S/.test(line)) inDepends = false
    if (!inDepends) continue
    const listed = /^ {6}- ([A-Za-z0-9_-]+)\s*$/.exec(line)
    const mapped = /^ {6}([A-Za-z0-9_-]+):\s*$/.exec(line)
    const condition = /^ {8}condition:\s*(\S+)\s*$/.exec(line)
    if (listed) services.get(current)[listed[1]] = 'service_started'
    if (mapped) {
      lastDependency = mapped[1]
      services.get(current)[lastDependency] = 'service_started'
    }
    if (condition && lastDependency) services.get(current)[lastDependency] = condition[1]
  }
  return services
}

const reachable = (services, from) => {
  const seen = new Set()
  const stack = [...Object.keys(services.get(from) ?? {})]
  while (stack.length) {
    const next = stack.pop()
    if (seen.has(next)) continue
    seen.add(next)
    stack.push(...Object.keys(services.get(next) ?? {}))
  }
  return seen
}

for (const file of DEV_FILES) {
  const services = dependencies(read(file))

  test(`${file}: every wave-2 service waits for a healthy backend`, () => {
    const present = [...services.keys()].filter((name) => WAVE_2.has(name))
    assert.ok(present.length > 0, 'no wave-2 service found — did the parser break?')
    for (const name of present) {
      assert.equal(services.get(name).backend, 'service_healthy', `${name} must depend on backend: service_healthy`)
    }
  })

  test(`${file}: no wave-1 service waits for wave 2`, () => {
    for (const name of [...services.keys()].filter((n) => WAVE_1.has(n))) {
      const waitsFor = [...reachable(services, name)].filter((dep) => WAVE_2.has(dep))
      assert.deepEqual(waitsFor, [], `${name} would wait for ${waitsFor.join(', ')}`)
    }
  })

  test(`${file}: every service is in a wave`, () => {
    const stubs = new Set(['freeapi-fake', 'telegram-stub', 'keycloak_setup'])
    const unknown = [...services.keys()].filter((n) => !WAVE_1.has(n) && !WAVE_2.has(n) && !stubs.has(n))
    assert.deepEqual(unknown, [], 'a new service needs a wave: add it to WAVE_1 or WAVE_2')
  })
}

for (const file of ALL_FILES) {
  test(`${file}: no dependency cycle`, () => {
    const services = dependencies(read(file))
    for (const name of services.keys()) {
      assert.ok(!reachable(services, name).has(name), `${name} depends on itself through its dependencies`)
    }
  })
}

test('deploy/ and the quickstart do not stagger', () => {
  for (const file of ['deploy/compose.yaml', 'deploy/quickstart/compose.yaml']) {
    const services = dependencies(read(file))
    for (const name of [...services.keys()].filter((n) => WAVE_2.has(n))) {
      assert.equal(services.get(name).backend, undefined, `${file}: ${name} must not wait for the backend`)
    }
  }
})
