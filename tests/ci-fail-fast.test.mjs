import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { chmodSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const WORKFLOW = readFileSync(join(ROOT, '.github/workflows/ci.yml'), 'utf8')
const SCRIPT = join(ROOT, 'scripts/ci-cancel-on-failure.sh')

// Jobs that publish, deploy, or only report a result. Cancelling the run from
// one of these would cut off an image push or the required gate. A new test
// job is fail-closed: it must cancel, or be added here with a reason.
const EXEMPT_JOBS = new Set([
  'e2e-flaky-notify',
  'docker-push-amd64',
  'docker-push-arm64',
  'docker-manifest',
  'release-manifest',
  'release-version-pin',
  'cloudflare-deploy',
  'openapi-promote',
  'all-checks-passed',
  'transcriber-push-amd64',
  'transcriber-push-arm64',
  'transcriber-manifest',
])

function jobs() {
  const start = WORKFLOW.indexOf('\njobs:\n')
  assert.notEqual(start, -1)
  const body = WORKFLOW.slice(start + '\njobs:\n'.length)
  const parts = body.split(/\n(?=  [a-z0-9-]+:\n)/)
  return parts
    .filter((part) => /^  [a-z0-9-]+:\n/.test(part))
    .map((part) => {
      const id = part.match(/^  ([a-z0-9-]+):/)[1]
      return { id, body: part }
    })
}

test('every test job cancels the rest of the run on failure', () => {
  const found = jobs()
  assert.ok(found.length > 20, `expected the CI jobs, found ${found.length}`)
  for (const job of found) {
    const stops = job.body.includes('name: Stop the rest of this run')
    if (EXEMPT_JOBS.has(job.id)) {
      assert.equal(stops, false, `${job.id} must not cancel the run`)
    } else {
      assert.equal(stops, true, `${job.id} must stop the rest of the run on failure`)
      assert.match(job.body, /run: bash scripts\/ci-cancel-on-failure\.sh/)
    }
  }
})

test('E2E shards stop when one shard fails', () => {
  const e2e = jobs().find((job) => job.id === 'e2e')
  assert.ok(e2e)
  assert.match(e2e.body, /fail-fast: true/)
  assert.doesNotMatch(e2e.body, /fail-fast: false/)
})

test('the workflow token can cancel a run, and the gate job still runs', () => {
  assert.match(WORKFLOW, /^permissions:\n  contents: read\n(?:  #.*\n)*  actions: write\n/m)
  const gate = jobs().find((job) => job.id === 'all-checks-passed')
  assert.ok(gate)
  assert.match(gate.body, /if: always\(\)/)
})

function runCancel(env, ghExit = 0) {
  const dir = mkdtempSync(join(tmpdir(), 'ci-cancel-'))
  const log = join(dir, 'gh.log')
  writeFileSync(join(dir, 'gh'), `#!/bin/sh\nprintf '%s\\n' "$*" >> '${log}'\nexit ${ghExit}\n`)
  chmodSync(join(dir, 'gh'), 0o755)
  const result = spawnSync('/bin/bash', [SCRIPT], {
    env: {
      PATH: `${dir}:/usr/bin:/bin`,
      GH_TOKEN: 'fake-token',
      GITHUB_TOKEN: '',
      GITHUB_RUN_ID: '999001',
      ...env,
    },
    encoding: 'utf8',
  })
  let called = ''
  try {
    called = readFileSync(log, 'utf8')
  } catch {
    called = ''
  }
  return { status: result.status, stdout: result.stdout, stderr: result.stderr, called }
}

test('a pull request failure cancels the run', () => {
  const result = runCancel({ GITHUB_EVENT_NAME: 'pull_request', GITHUB_REF: 'refs/pull/1/merge' })
  assert.equal(result.status, 0, result.stderr)
  assert.equal(result.called, 'run cancel 999001\n')
})

test('a develop push failure cancels the run', () => {
  const result = runCancel({ GITHUB_EVENT_NAME: 'push', GITHUB_REF: 'refs/heads/develop' })
  assert.equal(result.status, 0, result.stderr)
  assert.equal(result.called, 'run cancel 999001\n')
})

test('main and release tags are not cancelled', () => {
  for (const ref of ['refs/heads/main', 'refs/tags/v1.2.3']) {
    const result = runCancel({ GITHUB_EVENT_NAME: 'push', GITHUB_REF: ref })
    assert.equal(result.status, 0, result.stderr)
    assert.equal(result.called, '', ref)
  }
})

test('a cancel API failure does not hide the original test failure', () => {
  const result = runCancel(
    { GITHUB_EVENT_NAME: 'pull_request', GITHUB_REF: 'refs/pull/1/merge' },
    1
  )
  assert.equal(result.status, 0)
  assert.match(result.stdout, /Could not cancel/)
})
