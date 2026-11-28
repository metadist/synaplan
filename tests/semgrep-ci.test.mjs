import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
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

function allowlistValues() {
  return readFileSync(join(ROOT, '.github/dummy-secrets.allow'), 'utf8')
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '' && !line.startsWith('#'))
}

test('every listed dummy still exists in the tree, and the timing hashes are on the list', () => {
  const values = allowlistValues()
  assert.ok(values.length >= 10)
  const hashes = readFileSync(join(ROOT, 'backend/src/Security/TimingDummyHash.php'), 'utf8')
  for (const value of values) {
    const found = spawnSync(
      'git',
      ['grep', '-F', '-l', '--untracked', '-e', value, '--', ':!.github/dummy-secrets.allow'],
      { cwd: ROOT, encoding: 'utf8' }
    )
    assert.equal(found.status, 0, `listed dummy of length ${value.length} is not in the tree`)
  }
  assert.match(hashes, /TimingDummyHash::LOGIN|const LOGIN/)
  for (const value of values.filter((value) => value.startsWith('$2'))) {
    assert.ok(hashes.includes(value), 'a timing hash left the class without leaving the list')
  }
})

test('secret scanning ignores only the dummy-secret paths', () => {
  const config = readFileSync(join(ROOT, '.github/secret_scanning.yml'), 'utf8')
  for (const path of [
    '.github/dummy-secrets.allow',
    'backend/src/Security/TimingDummyHash.php',
    'backend/tests/**',
    'frontend/tests/**',
    'backend/.env.test',
    'backend/phpunit.xml.dist',
  ]) {
    assert.ok(config.includes(`"${path}"`), path)
  }
  assert.doesNotMatch(config, /backend\/src\/\*\*/)
  assert.match(SCRIPT, /semgrep-filter-allowlist\.py/)
  assert.match(SCRIPT, /dummy-secrets\.allow/)
})

test('the allowlist drops a listed fake and keeps an unlisted one', () => {
  const dir = mkdtempSync(join(tmpdir(), 'semgrep-allow-'))
  const sarif = join(dir, 'semgrep.sarif')
  const allow = join(dir, 'allow')
  writeFileSync(allow, 'ya29.a0ARrdaM-abc_def\n')
  writeFileSync(
    sarif,
    JSON.stringify({
      runs: [
        {
          results: [
            {
              ruleId: 'generic.secrets.security.detected-google-oauth-access-token',
              locations: [
                {
                  physicalLocation: {
                    artifactLocation: { uri: 'backend/tests/Example.php' },
                    region: { startLine: 4, snippet: { text: "yield ['ya29.a0ARrdaM-abc_def'];" } },
                  },
                },
              ],
            },
            {
              ruleId: 'php.lang.security.injection.echoed-request',
              locations: [
                {
                  physicalLocation: {
                    artifactLocation: { uri: 'backend/src/Example.php' },
                    region: {
                      startLine: 2,
                      snippet: { text: "echo whsec_your_webhook_secret_here;" },
                    },
                  },
                },
              ],
            },
            {
              ruleId: 'unlisted',
              locations: [
                {
                  physicalLocation: {
                    artifactLocation: { uri: 'backend/src/Example.php' },
                    region: { startLine: 9, snippet: { text: "key = 'sk_live_notonthelist0000';" } },
                  },
                },
              ],
            },
          ],
        },
      ],
    })
  )
  const filtered = spawnSync('python3', [join(ROOT, 'scripts/semgrep-filter-allowlist.py'), sarif, allow], {
    encoding: 'utf8',
  })
  assert.equal(filtered.status, 1, filtered.stderr)
  assert.match(filtered.stderr, /backend\/src\/Example\.php:9: unlisted/)
  assert.doesNotMatch(filtered.stderr, /ya29/)
  const left = JSON.parse(readFileSync(sarif, 'utf8'))
  assert.deepEqual(
    left.runs[0].results.map((result) => result.ruleId),
    ['php.lang.security.injection.echoed-request', 'unlisted']
  )
  assert.ok(
    !left.runs[0].results.some((result) => String(result.ruleId).includes('secrets')),
    'a listed secret finding was kept'
  )
})

test('a missing SARIF report fails the scan even when Semgrep itself exited 0', () => {
  assert.match(SCRIPT, /if \[ "\$status" -eq 0 \]; then\n    exit 1/m)
  assert.doesNotMatch(SCRIPT, /status:-\d/)
})
