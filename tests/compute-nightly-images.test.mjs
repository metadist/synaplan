import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const pinScript = join(root, 'sidecars/synaplan-compute/scripts/pin-nightly-map.py')
const registryScript = join(root, 'sidecars/synaplan-compute/scripts/pin-nightly-registry.sh')
const mapGo = join(root, 'sidecars/synaplan-compute/internal/images/map.go')
const makefile = join(root, 'sidecars/synaplan-compute/Makefile')
const nightly = join(root, '.github/workflows/compute-nightly.yml')

const PY_DIGEST = `sha256:${'c'.repeat(64)}`
const NODE_DIGEST = `sha256:${'d'.repeat(64)}`

function runPin(args, mapText) {
  const dir = mkdtempSync(join(tmpdir(), 'compute-nightly-'))
  const mapPath = join(dir, 'map.go')
  writeFileSync(mapPath, mapText)
  const result = spawnSync('python3', [pinScript, '--map', mapPath, ...args], {
    encoding: 'utf8',
  })
  return { ...result, mapPath }
}

test('make images tags :local, not implicit :latest', () => {
  const text = readFileSync(makefile, 'utf8')
  assert.match(text, /docker build -t synaplan-compute-python:local /)
  assert.match(text, /docker build -t synaplan-compute-node:local /)
  assert.doesNotMatch(text, /docker build -t synaplan-compute-python(?:\s|\\)/)
  assert.doesNotMatch(text, /docker build -t synaplan-compute-node(?:\s|\\)/)
  assert.match(
    text,
    /pin-nightly-map\.py --map internal\/images\/map\.go --check/,
    'sidecar lint must fail if map.go is no longer nightly-pinnable'
  )
})

test('nightly retags :local onto the ephemeral registry via the pin script', () => {
  const workflow = readFileSync(nightly, 'utf8')
  const script = readFileSync(registryScript, 'utf8')
  assert.equal(
    (workflow.match(/run: \.\/scripts\/pin-nightly-registry\.sh/g) || []).length,
    2,
    'T1 and T2 must both call the same pin script'
  )
  assert.doesNotMatch(
    workflow,
    /synaplan-compute-\$img:latest/,
    'workflow must not tag a :latest source that make images no longer produces'
  )
  assert.match(script, /synaplan-compute-\$\{img\}:local/)
  assert.match(script, /pin-nightly-map\.py/)
})

test('current map.go is rewritable (catches another digest-form drift)', () => {
  const result = spawnSync('python3', [pinScript, '--map', mapGo, '--check'], {
    encoding: 'utf8',
  })
  assert.equal(result.status, 0, result.stderr)
  assert.match(result.stdout, /is rewritable/)
})

test('pin-nightly-map rewrites published digest pins', () => {
  const source = readFileSync(mapGo, 'utf8')
  const result = runPin(['--python', PY_DIGEST, '--node', NODE_DIGEST], source)
  assert.equal(result.status, 0, result.stderr)
  const updated = readFileSync(result.mapPath, 'utf8')
  assert.match(updated, new RegExp(`127\\.0\\.0\\.1:5000/compute-python@${PY_DIGEST}`))
  assert.match(updated, new RegExp(`127\\.0\\.0\\.1:5000/compute-node@${NODE_DIGEST}`))
  assert.doesNotMatch(updated, /ghcr\.io\/metadist\/synaplan-compute-python@sha256:/)
  assert.doesNotMatch(updated, /ghcr\.io\/metadist\/synaplan-compute-node@sha256:/)
})

test('pin-nightly-map still rewrites the pre-1.0.0 placeholder expression', () => {
  const source = `Ref: "ghcr.io/metadist/synaplan-compute-python@sha256:" + strings.Repeat("a", 64),
Ref: "ghcr.io/metadist/synaplan-compute-node@sha256:" + strings.Repeat("b", 64),
`
  const result = runPin(['--python', PY_DIGEST, '--node', NODE_DIGEST], source)
  assert.equal(result.status, 0, result.stderr)
  const updated = readFileSync(result.mapPath, 'utf8')
  assert.equal(
    updated,
    `Ref: "127.0.0.1:5000/compute-python@${PY_DIGEST}",
Ref: "127.0.0.1:5000/compute-node@${NODE_DIGEST}",
`
  )
})

test('pin-nightly-map fails closed when the map shape drifted', () => {
  const result = runPin(['--python', PY_DIGEST, '--node', NODE_DIGEST], 'package images\n')
  assert.equal(result.status, 1)
  assert.match(result.stderr, /image ref not found/)
})

test('pin-nightly-map rejects a non-digest', () => {
  const source = readFileSync(mapGo, 'utf8')
  const result = runPin(['--python', 'latest', '--node', NODE_DIGEST], source)
  assert.equal(result.status, 1)
  assert.match(result.stderr, /not sha256/)
})
