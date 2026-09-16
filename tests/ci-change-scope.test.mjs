import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

import { CATALOG_PATHS } from '../scripts/read-release-version.mjs'
import {
  classifyCiChangeScope,
  isPinOnlyChange,
  isPinPath,
  runCli,
} from '../scripts/ci-change-scope.mjs'

const catalogFiles = [...CATALOG_PATHS]

test('every catalog pin path is a thin-CI path', () => {
  for (const path of catalogFiles) {
    assert.equal(isPinPath(path), true, path)
  }
})

test('the Umbrel package prefix is a thin-CI path', () => {
  assert.equal(isPinPath('deploy/umbrel/synaplan/exports.sh'), true)
  assert.equal(isPinPath('deploy/umbrel/synaplan/data/config.json'), true)
})

test('application paths are not thin-CI paths', () => {
  assert.equal(isPinPath('backend/src/Kernel.php'), false)
  assert.equal(isPinPath('frontend/src/App.vue'), false)
  assert.equal(isPinPath('deploy/aws/cloudformation/synaplan-new-vpc.yaml'), false)
  assert.equal(isPinPath(''), false)
  assert.equal(isPinPath('__unknown__'), false)
})

test('an empty or unreadable diff is not pin-only (fail closed)', () => {
  assert.equal(isPinOnlyChange([]), false)
  assert.equal(isPinOnlyChange(['__unknown__']), false)
  assert.equal(isPinOnlyChange(['', '  ']), false)
})

test('the bot install commit is pin-only', () => {
  assert.equal(isPinOnlyChange(catalogFiles), true)
  assert.equal(
    isPinOnlyChange([...catalogFiles, 'deploy/umbrel/synaplan/exports.sh']),
    true
  )
})

test('a pin commit that also touches application code is not pin-only', () => {
  assert.equal(isPinOnlyChange([...catalogFiles, 'backend/src/Kernel.php']), false)
})

test('classifies a pin-only pull request as thin', () => {
  const result = classifyCiChangeScope({
    eventName: 'pull_request',
    ref: 'refs/pull/1753/merge',
    files: catalogFiles,
  })

  assert.deepEqual(result, {
    mode: 'thin',
    reason: 'only deployment catalog pins changed',
  })
})

test('classifies a pin-only main push as thin', () => {
  const result = classifyCiChangeScope({
    eventName: 'push',
    ref: 'refs/heads/main',
    files: catalogFiles,
  })

  assert.equal(result.mode, 'thin')
})

test('classifies a code push as heavy', () => {
  const result = classifyCiChangeScope({
    eventName: 'push',
    ref: 'refs/heads/main',
    files: ['frontend/src/App.vue'],
  })

  assert.deepEqual(result, {
    mode: 'heavy',
    reason: 'application or unlisted paths changed',
  })
})

test('promotes a plain release tag when the main image is reusable', () => {
  const result = classifyCiChangeScope({
    eventName: 'push',
    ref: 'refs/tags/v4.7.1',
    canPromote: true,
  })

  assert.equal(result.mode, 'promote')
})

test('rebuilds a plain release tag when the main image is missing', () => {
  const result = classifyCiChangeScope({
    eventName: 'push',
    ref: 'refs/tags/v4.7.1',
    canPromote: false,
    files: catalogFiles,
  })

  assert.equal(result.mode, 'heavy')
  assert.match(result.reason, /without a reusable main image/)
})

test('keeps a pre-release tag on the full publish path', () => {
  const result = classifyCiChangeScope({
    eventName: 'push',
    ref: 'refs/tags/v5.0.0-rc.1',
    canPromote: true,
  })

  assert.deepEqual(result, {
    mode: 'heavy',
    reason: 'non-plain tag; full publish path',
  })
})

test('the CLI writes mode and reason for a pin-only diff', () => {
  const list = join(mkdtempSync(join(tmpdir(), 'ci-scope-')), 'files.txt')
  writeFileSync(list, `${catalogFiles.join('\n')}\n`)

  const output = runCli([
    '--event',
    'push',
    '--ref',
    'refs/heads/main',
    '--can-promote',
    'false',
    '--files-from',
    list,
  ])
  assert.match(output, /^mode=thin\n/)
  assert.match(output, /reason=only deployment catalog pins changed\n/)

  const promoted = runCli([
    '--event',
    'push',
    '--ref',
    'refs/tags/v4.7.1',
    '--can-promote',
    'true',
  ])
  assert.match(promoted, /^mode=promote\n/)
})
