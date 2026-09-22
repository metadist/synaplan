import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const pinScript = join(root, 'sidecars/synaplan-compute/scripts/pin-nightly-map.py')
const nightlyUp = join(root, 'sidecars/synaplan-compute/scripts/nightly-up.sh')
const mapGo = join(root, 'sidecars/synaplan-compute/internal/images/map.go')
const makefile = join(root, 'sidecars/synaplan-compute/Makefile')
const nightly = join(root, '.github/workflows/compute-nightly.yml')
const compose = join(root, 'docker-compose.yml')
const readme = join(root, 'README.md')

const DEMO_TOKEN = 'synaplan-dev-compute-token-change-me-32b'

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

test('nightly uses the fixed demo token and :local images, not a fresh pin', () => {
  const workflow = readFileSync(nightly, 'utf8')
  const script = readFileSync(nightlyUp, 'utf8')
  const composeText = readFileSync(compose, 'utf8')
  assert.equal(
    (workflow.match(/run: \.\/scripts\/nightly-up\.sh/g) || []).length,
    2,
    'T1 and T2 must both start the sidecar through nightly-up.sh'
  )
  assert.doesNotMatch(workflow, /openssl rand/)
  assert.doesNotMatch(workflow, /pin-nightly-registry\.sh/)
  assert.doesNotMatch(script, /openssl rand/)
  assert.doesNotMatch(script, /pin-nightly-map\.py/)
  assert.match(script, new RegExp(`DEMO_TOKEN="${DEMO_TOKEN}"`))
  assert.match(script, /COMPUTE_SCRATCH_DIR=\/scratch/)
  assert.match(script, /COMPUTE_WORKSPACES_DIR=\/workspaces/)
  assert.match(script, /COMPUTE_ALLOW_LOCAL_IMAGES=1/)
  assert.match(script, /COMPUTE_IMAGE_PYTHON=synaplan-compute-python:local/)
  assert.match(script, /COMPUTE_IMAGE_NODE=synaplan-compute-node:local/)
  assert.match(script, /--user 0:0/)
  assert.equal(
    (composeText.match(new RegExp(`COMPUTE_TOKEN:-${DEMO_TOKEN}`, 'g')) || []).length,
    4,
    'app, worker, and sidecar must share the same demo token default'
  )
  assert.match(
    readFileSync(readme, 'utf8'),
    new RegExp(DEMO_TOKEN),
    'README must name the demo token and tell production to replace it'
  )
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
