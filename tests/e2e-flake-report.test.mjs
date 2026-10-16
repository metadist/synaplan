import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

import { parseReport, summariseReports, toMarkdown, discordPayload, runCli } from '../scripts/e2e-flake-report.mjs'

function reportWith(status, title = 'highlight query') {
  return {
    suites: [
      {
        title: 'chromium',
        suites: [
          {
            title: 'memories.spec.ts',
            file: 'memories.spec.ts',
            specs: [
              {
                title,
                tests: [
                  {
                    projectName: 'chromium',
                    status,
                    results: [
                      {
                        status: status === 'flaky' ? 'failed' : 'failed',
                        error: { message: 'Error: expect(locator).toBeVisible() failed\n  at foo' },
                      },
                      { status: status === 'flaky' ? 'passed' : 'failed' },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    ],
  }
}

test('parseReport reads nested Playwright JSON and keeps the first-attempt error', () => {
  const rows = parseReport(reportWith('flaky'))
  assert.equal(rows.length, 1)
  assert.equal(rows[0].status, 'flaky')
  assert.equal(rows[0].file, 'memories.spec.ts')
  assert.match(rows[0].error, /toBeVisible/)
  assert.deepEqual(rows[0].attempts, ['failed', 'passed'])
})

test('summariseReports splits flaky from unexpected and de-duplicates', () => {
  const dir = mkdtempSync(join(tmpdir(), 'flake-'))
  writeFileSync(join(dir, 'results.json'), JSON.stringify(reportWith('flaky')))
  const nested = join(dir, 'shard-2')
  mkdirSync(nested)
  writeFileSync(join(nested, 'results.json'), JSON.stringify(reportWith('flaky')))
  writeFileSync(
    join(dir, 'hard.json'),
    JSON.stringify(reportWith('unexpected', 'a multi-node request'))
  )

  const summary = summariseReports(
    [join(dir, 'results.json'), join(nested, 'results.json'), join(dir, 'hard.json')],
    { e2eResult: 'failure' }
  )
  assert.equal(summary.kind, 'mixed')
  assert.equal(summary.flaky.length, 1)
  assert.equal(summary.unexpected.length, 1)
})

test('missing reports after an E2E failure are infra, not flakes', () => {
  const summary = summariseReports([], { e2eResult: 'failure' })
  assert.equal(summary.kind, 'infra')
  const md = toMarkdown(summary)
  assert.match(md, /infrastructure/)
  const payload = discordPayload(summary, {
    branch: 'main',
    runUrl: 'https://example.test/run',
    timestamp: '2026-09-18T00:00:00Z',
  })
  assert.equal(payload.embeds[0].title, 'E2E infra: no Playwright reports')
})

test('a green run with reports and no flakes does not ping Discord', () => {
  const summary = summariseReports([], { e2eResult: 'success' })
  assert.equal(summary.kind, 'none')
  assert.equal(
    discordPayload(summary, { branch: 'main', runUrl: 'u', timestamp: 't' }),
    null
  )
})

test('runCli writes JSON, markdown and a Discord payload', () => {
  const dir = mkdtempSync(join(tmpdir(), 'flake-cli-'))
  mkdirSync(join(dir, 'artifacts'))
  writeFileSync(join(dir, 'artifacts', 'results.json'), JSON.stringify(reportWith('flaky')))
  const json = join(dir, 'summary.json')
  const md = join(dir, 'summary.md')
  const payloadPath = join(dir, 'payload.json')
  runCli(['--dir', join(dir, 'artifacts'), '--json', json, '--markdown', md, '--payload', payloadPath], {
    BRANCH: 'main',
    RUN_URL: 'https://example.test/run',
    TS: '2026-09-18T00:00:00Z',
  })
  const summary = JSON.parse(readFileSync(json, 'utf8'))
  assert.equal(summary.kind, 'flaky')
  assert.match(readFileSync(md, 'utf8'), /Flaky/)
  const payload = JSON.parse(readFileSync(payloadPath, 'utf8'))
  assert.match(payload.embeds[0].title, /Flaky E2E/)
})
