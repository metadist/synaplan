import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
  applyUmbrelReleaseNotes,
  formatUmbrelReleaseNotes,
} from '../scripts/set-umbrel-release-notes.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const MANIFEST_SAMPLE = [
  'manifestVersion: 1',
  'id: synaplan',
  'version: "4.4.1"',
  'releaseNotes: ""',
  'developer: Metadist',
].join('\n')

test('writes the notes into the single releaseNotes field', () => {
  const result = applyUmbrelReleaseNotes(MANIFEST_SAMPLE, 'Fixes the login loop.')

  assert.ok(result.includes('releaseNotes: "Fixes the login loop."'))
  assert.ok(result.includes('version: "4.4.1"'), 'the version pin must survive untouched')
})

// The manifest is parsed by Umbrel, so a value that breaks YAML breaks the store
// package. Colons, quotes, hashes and newlines all appear in real release notes.
test('escapes characters that would otherwise break the manifest', () => {
  const notes = 'Fixed: the "widget" bug #1649\nAlso: a second line'

  const result = applyUmbrelReleaseNotes(MANIFEST_SAMPLE, notes)
  const line = result.split('\n').find((candidate) => candidate.startsWith('releaseNotes:'))

  assert.equal(line, `releaseNotes: ${JSON.stringify(notes)}`)
  assert.equal(JSON.parse(line.slice('releaseNotes: '.length)), notes)
})

test('refuses a block scalar rather than corrupting it', () => {
  const manifest = ['releaseNotes: |', '  Some notes', '  over two lines', 'developer: Metadist'].join(
    '\n'
  )

  assert.throws(() => applyUmbrelReleaseNotes(manifest, 'new'), /block scalar/)
})

test('refuses a manifest without exactly one releaseNotes field', () => {
  assert.throws(() => applyUmbrelReleaseNotes('id: synaplan\n', 'new'), /found 0/)
  assert.throws(
    () => applyUmbrelReleaseNotes('releaseNotes: ""\nreleaseNotes: ""\n', 'new'),
    /found 2/
  )
})

test('appends the release link to the notes', () => {
  const result = formatUmbrelReleaseNotes('Adds memories.', 'https://example.test/v4.4.1')

  assert.equal(result, 'Adds memories.\n\nFull release notes: https://example.test/v4.4.1')
})

test('falls back to the link alone when a release has no body', () => {
  const result = formatUmbrelReleaseNotes('   ', 'https://example.test/v4.4.1')

  assert.equal(result, 'Full release notes: https://example.test/v4.4.1')
})

test('truncates long notes on a word boundary and keeps the link', () => {
  const body = `${'word '.repeat(500)}end`

  const result = formatUmbrelReleaseNotes(body, 'https://example.test/v4.4.1', 200)

  assert.ok(result.length <= 200, `expected at most 200 characters, got ${result.length}`)
  assert.ok(result.includes('…'))
  assert.ok(result.endsWith('Full release notes: https://example.test/v4.4.1'))
  assert.doesNotMatch(result, /wor…/, 'must not cut in the middle of a word')
})

test('normalizes Windows line endings', () => {
  const result = formatUmbrelReleaseNotes('First line\r\nSecond line', '')

  assert.equal(result, 'First line\nSecond line')
})

// The committed manifest must stay empty: Umbrel's linter rejects release notes
// on a new app submission, and Synaplan is still an open submission.
test('the shipped Umbrel manifest still carries an empty releaseNotes', () => {
  const manifest = readFileSync(
    join(ROOT, 'deploy', 'umbrel', 'synaplan', 'umbrel-app.yml'),
    'utf8'
  )

  assert.match(manifest, /^releaseNotes:\s*""\s*$/m)
})
