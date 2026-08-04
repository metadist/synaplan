import assert from 'node:assert/strict'
import test from 'node:test'

import { latestReleaseTag, nextPatchTag, parseReleaseTag } from '../scripts/next-release-tag.mjs'

test('parses two- and three-component release tags', () => {
  assert.deepEqual(parseReleaseTag('v4.0.6'), { major: 4, minor: 0, patch: 6 })
  assert.deepEqual(parseReleaseTag('v4.1'), { major: 4, minor: 1, patch: 0 })
  assert.equal(parseReleaseTag('v4.0.6-rc.1'), null)
  assert.equal(parseReleaseTag('release-4'), null)
  assert.equal(parseReleaseTag('v4'), null)
})

test('orders release tags numerically rather than lexically', () => {
  const tags = ['v3.9.6', 'v4.0.0', 'v4.0.10', 'v4.0.2', 'v2.3.2']

  assert.equal(latestReleaseTag(tags).tag, 'v4.0.10')
  assert.equal(nextPatchTag(tags), 'v4.0.11')
})

test('ignores pre-release tags and unrelated refs', () => {
  assert.equal(nextPatchTag(['v4.0.6', 'v4.1.0-rc.1', 'nightly']), 'v4.0.7')
})

test('starts a series when no release tag exists yet', () => {
  assert.equal(nextPatchTag([]), 'v0.0.1')
  assert.equal(nextPatchTag(['nightly', 'bootstrap']), 'v0.0.1')
})
