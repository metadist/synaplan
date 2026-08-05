import assert from 'node:assert/strict'
import test from 'node:test'

import { resolveAppVersion } from '../scripts/resolve-app-version.mjs'

test('keeps a plain SemVer from image metadata', () => {
  assert.equal(
    resolveAppVersion({ metaVersion: '4.0.13', refName: 'main', releaseTags: ['v4.0.12'] }),
    '4.0.13'
  )
})

test('never returns the mutable latest tag as the application version', () => {
  assert.equal(
    resolveAppVersion({
      metaVersion: 'latest',
      refName: 'main',
      releaseTags: ['v4.0.10', 'v4.0.13', 'v4.0.2'],
    }),
    '4.0.13'
  )
})

test('falls back to the release ref when metadata is missing', () => {
  assert.equal(
    resolveAppVersion({ metaVersion: '', refName: 'v4.1.0', releaseTags: [] }),
    '4.1.0'
  )
})

test('ignores branch names and PR refs', () => {
  assert.equal(
    resolveAppVersion({
      metaVersion: 'pr-1441',
      refName: 'ci/automatic-release-version',
      releaseTags: ['v4.0.13'],
    }),
    '4.0.13'
  )
})

test('reports dev when no release exists yet', () => {
  assert.equal(
    resolveAppVersion({ metaVersion: 'latest', refName: 'main', releaseTags: ['nightly'] }),
    'dev'
  )
})
