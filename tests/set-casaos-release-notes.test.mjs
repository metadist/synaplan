import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { applyCasaosReleaseNotes } from '../scripts/set-casaos-release-notes.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const COMPOSE_SAMPLE = [
  'x-casaos:',
  '  id: com.synaplan.synaplan',
  '  version: "5.5.0"',
  '  update_at: "2026-10-18"',
  '  release_notes:',
  '    en_US: "Synaplan 5.5.0"',
  '  website: "https://synaplan.com"',
].join('\n')

test('writes the notes and update_at into x-casaos', () => {
  const result = applyCasaosReleaseNotes(
    COMPOSE_SAMPLE,
    'Fixes the login loop.\n\nFull release notes: https://example.test/v5.5.1',
    '2026-10-20'
  )

  assert.ok(
    result.includes(
      '    en_US: "Fixes the login loop.\\n\\nFull release notes: https://example.test/v5.5.1"'
    )
  )
  assert.ok(result.includes('  update_at: "2026-10-20"'))
  assert.ok(result.includes('  version: "5.5.0"'))
})

test('refuses a block scalar in release_notes.en_US', () => {
  const blockSample = [
    'x-casaos:',
    '  update_at: "2026-10-18"',
    '  release_notes:',
    '    en_US: |-',
    '      line 1',
  ].join('\n')

  assert.throws(() => applyCasaosReleaseNotes(blockSample, 'new', '2026-10-20'), /block scalar/)
})

test('the shipped CasaOS compose carries an inline release_notes.en_US and update_at', () => {
  const compose = readFileSync(
    join(ROOT, 'deploy', 'casaos', 'Synaplan', 'docker-compose.yml'),
    'utf8'
  )
  const result = applyCasaosReleaseNotes(compose, 'Test notes', '2026-10-20')

  assert.ok(result.includes('    en_US: "Test notes"'))
  assert.ok(result.includes('  update_at: "2026-10-20"'))
})
