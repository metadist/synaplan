import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

import { readPinnedRelease } from '../scripts/read-release-version.mjs'

const DIGEST = `sha256:${'a'.repeat(64)}`

// A catalog whose five pins all name the same version, as the release
// automation leaves it. Individual tests bend one file at a time.
const catalog = (overrides = {}) => ({
  'elestio.yml': ['environments:', '  - key: "SYNAPLAN_VERSION"', '    value: "4.4.1"'].join('\n'),
  'deploy/selfhost.env.example': 'SYNAPLAN_VERSION=4.4.1\n',
  'deploy/umbrel/synaplan/umbrel-app.yml': 'manifestVersion: 1\nversion: "4.4.1"\n',
  'deploy/umbrel/synaplan/docker-compose.yml': [
    'x-app-environment: &app-environment',
    '  APP_VERSION: "4.4.1"',
    '',
    `x-app-image: &app-image ghcr.io/metadist/synaplan:4.4.1@${DIGEST}`,
  ].join('\n'),
  'deploy/aws/packer/synaplan.pkr.hcl': [
    'variable "synaplan_version" {',
    '  type        = string',
    '  default     = "4.4.1"',
    '}',
  ].join('\n'),
  ...overrides,
})

const writeCatalog = (files) => {
  const root = mkdtempSync(join(tmpdir(), 'synaplan-pins-'))
  for (const [path, contents] of Object.entries(files)) {
    const absolute = join(root, path)
    mkdirSync(join(absolute, '..'), { recursive: true })
    writeFileSync(absolute, contents)
  }
  return root
}

test('reads the version and digest a consistent catalog pins', () => {
  const result = readPinnedRelease(writeCatalog(catalog()))

  assert.deepEqual(result, { version: '4.4.1', digest: DIGEST })
})

// The failure this reader exists to catch: a bump that reached some files but
// not all of them would otherwise be rolled out as if it were complete.
test('refuses a catalog whose files disagree', () => {
  const root = writeCatalog(
    catalog({ 'deploy/selfhost.env.example': 'SYNAPLAN_VERSION=4.4.0\n' })
  )

  assert.throws(() => readPinnedRelease(root), /pins more than one version/)
})

test('refuses a catalog whose Umbrel package lags behind its own image tag', () => {
  const root = writeCatalog(
    catalog({
      'deploy/umbrel/synaplan/docker-compose.yml': [
        '  APP_VERSION: "4.4.0"',
        `x-app-image: &app-image ghcr.io/metadist/synaplan:4.4.1@${DIGEST}`,
      ].join('\n'),
    })
  )

  assert.throws(() => readPinnedRelease(root), /pins more than one version/)
})

test('refuses a pin that is not a plain release', () => {
  const root = writeCatalog({
    ...catalog(),
    'elestio.yml': ['  - key: "SYNAPLAN_VERSION"', '    value: "4.4.2-rc.1"'].join('\n'),
    'deploy/selfhost.env.example': 'SYNAPLAN_VERSION=4.4.2-rc.1\n',
    'deploy/umbrel/synaplan/umbrel-app.yml': 'version: "4.4.2-rc.1"\n',
    'deploy/umbrel/synaplan/docker-compose.yml': [
      '  APP_VERSION: "4.4.2-rc.1"',
      `x-app-image: &app-image ghcr.io/metadist/synaplan:4.4.2-rc.1@${DIGEST}`,
    ].join('\n'),
    'deploy/aws/packer/synaplan.pkr.hcl': [
      'variable "synaplan_version" {',
      '  default     = "4.4.2-rc.1"',
      '}',
    ].join('\n'),
  })

  assert.throws(() => readPinnedRelease(root), /is not a plain release/)
})

test('names the file that carries no pin at all', () => {
  const root = writeCatalog(catalog({ 'deploy/selfhost.env.example': '# nothing here\n' }))

  assert.throws(() => readPinnedRelease(root), /deploy\/selfhost\.env\.example/)
})

test('refuses an Umbrel package without a published image digest', () => {
  const root = writeCatalog(
    catalog({
      'deploy/umbrel/synaplan/docker-compose.yml': [
        '  APP_VERSION: "4.4.1"',
        'x-app-image: &app-image ghcr.io/metadist/synaplan:4.4.1',
      ].join('\n'),
    })
  )

  assert.throws(() => readPinnedRelease(root), /image digest/)
})

// The real files have to satisfy the reader, or the rollout guard would hold
// back every release for a reason that has nothing to do with the release.
test('the shipped catalog is readable', () => {
  const result = readPinnedRelease()

  assert.match(result.version, /^\d+\.\d+\.\d+$/)
  assert.match(result.digest, /^sha256:[a-f0-9]{64}$/)
})
