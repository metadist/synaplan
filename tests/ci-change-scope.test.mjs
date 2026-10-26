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
  decodeListedDiff,
  diffListTruncated,
  sidecarResultOk,
  sidecarsForChange,
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

test('a docs-only diff tests no sidecar', () => {
  assert.deepEqual(
    sidecarsForChange({
      eventName: 'pull_request',
      ref: 'refs/pull/2651/merge',
      files: ['_devextras/planning/AGENTS.md'],
      mode: 'heavy',
    }),
    { compute: false, watchdog: false, transcriber: false, voice: false }
  )
})

test('each sidecar runs only for its own paths', () => {
  assert.equal(
    sidecarsForChange({
      eventName: 'pull_request',
      ref: 'refs/pull/1/merge',
      files: ['sidecars/synaplan-compute/main.go'],
      mode: 'heavy',
    }).compute,
    true
  )
  assert.equal(
    sidecarsForChange({
      eventName: 'pull_request',
      ref: 'refs/pull/1/merge',
      files: ['sidecars/synaplan-transcriber/src/index.js'],
      mode: 'heavy',
    }).transcriber,
    true
  )
  assert.equal(
    sidecarsForChange({
      eventName: 'pull_request',
      ref: 'refs/pull/1/merge',
      files: ['frontend/src/App.vue', 'sidecars/synaplan-voice/src/index.js'],
      mode: 'heavy',
    }).voice,
    true
  )
  const watchdog = sidecarsForChange({
    eventName: 'pull_request',
    ref: 'refs/pull/1/merge',
    files: ['sidecars/synaplan-watchdog/cmd/synaplan-watchdog/main.go'],
    mode: 'heavy',
  })
  assert.equal(watchdog.watchdog, true)
  assert.equal(watchdog.compute, false)
})

test('an unknown diff or a CI rule change tests every sidecar', () => {
  for (const files of [[], ['__unknown__'], ['.github/workflows/ci.yml']]) {
    assert.deepEqual(
      sidecarsForChange({ eventName: 'pull_request', ref: 'refs/pull/1/merge', files, mode: 'heavy' }),
      { compute: true, watchdog: true, transcriber: true, voice: true },
      files.join(',') || '(empty)'
    )
  }
})

test('a listed diff is decoded from text, and a capped one is unknown', () => {
  const renamed = decodeListedDiff(
    'pull_request',
    'F\tarchive/transcriber-index.js\nP\tsidecars/synaplan-transcriber/src/index.js\n'
  )
  assert.equal(renamed.truncated, false)
  assert.deepEqual(renamed.paths, [
    'archive/transcriber-index.js',
    'sidecars/synaplan-transcriber/src/index.js',
  ])

  const capped = decodeListedDiff('push', Array.from({ length: 300 }, (_, i) => `F\tfile-${i}.txt`).join('\n'))
  assert.equal(capped.truncated, true)
  assert.deepEqual(capped.paths, ['__unknown__'])
})

test('a file list at the GitHub cap is incomplete', () => {
  assert.equal(diffListTruncated('pull_request', 2999), false)
  assert.equal(diffListTruncated('pull_request', 3000), true)
  assert.equal(diffListTruncated('push', 299), false)
  assert.equal(diffListTruncated('push', 300), true)
  assert.equal(diffListTruncated('workflow_dispatch', 1), true)
})

test('a rename out of a sidecar still selects it when the old path is listed', () => {
  const destinationOnly = sidecarsForChange({
    eventName: 'pull_request',
    ref: 'refs/pull/1/merge',
    files: ['archive/transcriber-index.js'],
    mode: 'heavy',
  })
  assert.equal(destinationOnly.transcriber, false)

  const withPreviousPath = sidecarsForChange({
    eventName: 'pull_request',
    ref: 'refs/pull/1/merge',
    files: ['archive/transcriber-index.js', 'sidecars/synaplan-transcriber/src/index.js'],
    mode: 'heavy',
  })
  assert.equal(withPreviousPath.transcriber, true)
  assert.equal(withPreviousPath.compute, false)
  assert.equal(withPreviousPath.voice, false)
})

test('a compose or socket-guard change does not pull in the Go suite', () => {
  const result = sidecarsForChange({
    eventName: 'pull_request',
    ref: 'refs/pull/1/merge',
    files: ['docker-compose.yml', 'deploy/compose.yaml'],
    mode: 'heavy',
  })

  assert.equal(result.compute, false)
})

test('a required sidecar must succeed; a skipped one is only ok when it was not required', () => {
  assert.equal(sidecarResultOk(true, 'success'), true)
  assert.equal(sidecarResultOk('true', 'skipped'), false)
  assert.equal(sidecarResultOk('true', 'cancelled'), false)
  assert.equal(sidecarResultOk('true', 'failure'), false)
  assert.equal(sidecarResultOk('false', 'skipped'), true)
  assert.equal(sidecarResultOk(false, 'success'), true)
  assert.equal(sidecarResultOk('', 'cancelled'), false)
})

test('thin and promote skip sidecars; a rebuild tag does not', () => {
  assert.deepEqual(
    sidecarsForChange({ eventName: 'push', ref: 'refs/heads/main', files: ['frontend/src/App.vue'], mode: 'thin' }),
    { compute: false, watchdog: false, transcriber: false, voice: false }
  )
  assert.equal(
    sidecarsForChange({ eventName: 'push', ref: 'refs/tags/v4.7.1', mode: 'promote' }).compute,
    false
  )
  assert.equal(
    sidecarsForChange({ eventName: 'push', ref: 'refs/tags/v4.7.1', mode: 'heavy' }).compute,
    true
  )
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
