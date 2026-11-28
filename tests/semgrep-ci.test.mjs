import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { classifyFiles, loadPolicy } from '../scripts/mobile-impact.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const WORKFLOW = readFileSync(join(ROOT, '.github/workflows/ci.yml'), 'utf8')
const SCRIPT = readFileSync(join(ROOT, 'scripts/semgrep-scan.sh'), 'utf8')
const IGNORE = readFileSync(join(ROOT, '.semgrepignore'), 'utf8')

test('the Semgrep job is required by All Checks Passed', () => {
  const jobs = WORKFLOW.split('\njobs:\n')[1]
  assert.ok(jobs)
  const gate = jobs.split(/\n(?=  [a-z0-9-]+:\n)/).find((part) => part.startsWith('  all-checks-passed:\n'))
  assert.ok(gate, 'all-checks-passed job missing')
  assert.match(gate, /needs: \[.*\bsemgrep\b.*\]/)
  assert.match(gate, /SEMGREP: \$\{\{ needs\.semgrep\.result \}\}/)
  assert.match(gate, /require "Semgrep" "\$SEMGREP"/)
})

test('the scan fails the build on a PHP or secret finding and does not phone home', () => {
  assert.match(SCRIPT, /semgrep\/semgrep:1\.187\.0@sha256:[a-f0-9]{64}/)
  assert.match(SCRIPT, /--metrics=off/)
  assert.match(SCRIPT, /--error/)
  assert.match(SCRIPT, /--config p\/php/)
  assert.match(SCRIPT, /--config p\/secrets/)
  assert.match(SCRIPT, /--sarif-output/)
  assert.doesNotMatch(SCRIPT, /--config auto/)
  assert.match(SCRIPT, /scan log was omitted/)
  assert.doesNotMatch(SCRIPT, /tail /)
})

test('dev extras stay out of the product scan', () => {
  assert.match(IGNORE, /^_devextras\/$/m)
})

test('.semgrepignore does not force a store release', () => {
  const result = classifyFiles([{ path: '.semgrepignore', status: 'A' }], loadPolicy())
  assert.equal(result.classification, 'no-app-impact')
})
