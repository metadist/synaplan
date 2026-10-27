import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const WORKFLOW = readFileSync(join(ROOT, '.github/workflows/ci.yml'), 'utf8')

test('default release version push falls back to GITHUB_TOKEN when the App token fails', () => {
  const job = WORKFLOW.match(/  release-version-pin:\n([\s\S]*?)\n  cloudflare-deploy:/)?.[1]

  assert.ok(job, 'release-version-pin job should exist')
  assert.match(
    job,
    /if \[ -n "\$\{APP_TOKEN:-\}" \]; then\s+if ! push_branch "\$APP_TOKEN"; then[\s\S]*?push_branch "\$GH_TOKEN"/
  )
})
