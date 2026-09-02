import assert from 'node:assert/strict'
import test from 'node:test'

import {
  classifyCommits,
  latestReleaseTag,
  nextReleaseTag,
  parseReleaseTag,
  resolveBump
} from '../scripts/next-release-tag.mjs'

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
  assert.equal(nextReleaseTag(tags), 'v4.0.11')
})

test('ignores pre-release tags and unrelated refs', () => {
  assert.equal(nextReleaseTag(['v4.0.6', 'v4.1.0-rc.1', 'nightly']), 'v4.0.7')
})

test('starts a series when no release tag exists yet', () => {
  assert.equal(nextReleaseTag([]), 'v0.0.1')
  assert.equal(nextReleaseTag(['nightly', 'bootstrap']), 'v0.0.1')
})

test('nextReleaseTag bumps the requested component and resets the ones below it', () => {
  const tags = ['v4.2.6']

  assert.equal(nextReleaseTag(tags, 'patch'), 'v4.2.7')
  assert.equal(nextReleaseTag(tags, 'minor'), 'v4.3.0')
  assert.equal(nextReleaseTag(tags, 'major'), 'v5.0.0')
})

test('nextReleaseTag starts each series at the right point with no release tag yet', () => {
  assert.equal(nextReleaseTag([], 'patch'), 'v0.0.1')
  assert.equal(nextReleaseTag([], 'minor'), 'v0.1.0')
  assert.equal(nextReleaseTag([], 'major'), 'v1.0.0')
})

test('nextReleaseTag defaults to a patch bump', () => {
  assert.equal(nextReleaseTag(['v1.2.3']), 'v1.2.4')
})

test('classifyCommits raises the minor version when a feat: commit is present', () => {
  const result = classifyCommits([
    'fix(auth): revoke admin token on logout',
    'feat(chat): resume a still-generating turn after reload'
  ])

  assert.equal(result.level, 'minor')
  assert.deepEqual(result.features, ['feat(chat): resume a still-generating turn after reload'])
  assert.deepEqual(result.breaking, [])
  assert.deepEqual(result.unconventional, [])
})

test('classifyCommits stays on patch when only fix/chore commits are present', () => {
  const result = classifyCommits([
    'fix(pricing): apply OpenAI GPT-5.6 Sol price cut',
    'chore(deps): update node.js to be23f54'
  ])

  assert.equal(result.level, 'patch')
  assert.deepEqual(result.features, [])
})

test('classifyCommits treats a feat/ squash-merge title as a feature, but not fix/ or chore/', () => {
  const result = classifyCommits([
    'Feat/desktop client (#1669)',
    'Chore/gitignore updates (#1653)',
    'fix/unrelated-branch-name'
  ])

  assert.equal(result.level, 'minor')
  assert.deepEqual(result.features, ['Feat/desktop client (#1669)'])
  assert.deepEqual(result.unconventional, ['Chore/gitignore updates (#1653)', 'fix/unrelated-branch-name'])
})

test('classifyCommits records breaking markers without ever raising the level to major', () => {
  const bySubject = classifyCommits(['feat(api)!: drop the legacy /v1 endpoints'])
  assert.equal(bySubject.level, 'minor')
  assert.deepEqual(bySubject.breaking, ['feat(api)!: drop the legacy /v1 endpoints'])

  const byFooter = classifyCommits([
    'fix(auth): rotate session tokens\n\nBREAKING CHANGE: old tokens are rejected immediately'
  ])
  assert.equal(byFooter.level, 'patch')
  assert.deepEqual(byFooter.breaking, ['fix(auth): rotate session tokens'])
})

test('classifyCommits collects commits that follow neither convention', () => {
  const result = classifyCommits(['Update README', 'wip', 'feat(chat): add voice input'])

  assert.equal(result.level, 'minor')
  assert.deepEqual(result.unconventional, ['Update README', 'wip'])
})

test('classifyCommits ignores blank messages and defaults to patch with no commits', () => {
  assert.deepEqual(classifyCommits([]), {
    level: 'patch',
    features: [],
    breaking: [],
    unconventional: []
  })
  assert.deepEqual(classifyCommits(['  ', '']), {
    level: 'patch',
    features: [],
    breaking: [],
    unconventional: []
  })
})

test('resolveBump defers to the commit classification when auto is requested', () => {
  const messages = ['feat(widget): add dark mode toggle']

  assert.equal(resolveBump({ requested: 'auto', messages }).level, 'minor')
  assert.equal(resolveBump({ requested: undefined, messages }).level, 'minor')
  assert.equal(resolveBump({ requested: 'auto', messages: ['fix: typo'] }).level, 'patch')
})

test('resolveBump lets an explicit level override the classification, including major', () => {
  const messages = ['fix: typo']

  assert.equal(resolveBump({ requested: 'patch', messages }).level, 'patch')
  assert.equal(resolveBump({ requested: 'minor', messages }).level, 'minor')
  assert.equal(resolveBump({ requested: 'major', messages }).level, 'major')
})

test('resolveBump never resolves auto to major, even with a breaking-change marker', () => {
  const messages = ['feat(api)!: drop the legacy /v1 endpoints']

  assert.equal(resolveBump({ requested: 'auto', messages }).level, 'minor')
  assert.deepEqual(resolveBump({ requested: 'auto', messages }).breaking, [
    'feat(api)!: drop the legacy /v1 endpoints'
  ])
})

test('resolveBump rejects an unknown bump level', () => {
  assert.throws(() => resolveBump({ requested: 'super-major', messages: [] }), /--bump must be one of/)
})

test('resolveBump is case-insensitive and defaults an empty string to auto', () => {
  assert.equal(resolveBump({ requested: 'MINOR', messages: [] }).level, 'minor')
  assert.equal(resolveBump({ requested: '', messages: ['feat: x'] }).level, 'minor')
})
