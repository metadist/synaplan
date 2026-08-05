import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
  applyElestioVersion,
  applyEnvExampleVersion,
  parseReleaseVersion,
  readElestioVersion,
  readEnvExampleVersion,
} from '../scripts/set-release-version.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const ELESTIO_SAMPLE = [
  'environments:',
  '  - key: "COMPOSE_PROJECT_NAME"',
  '    value: "synaplan"',
  '  # A comment about the release pin.',
  '  - key: "SYNAPLAN_VERSION"',
  '    value: "4.0.12"',
  '  - key: "SYNAPLAN_PLATFORM"',
  '    value: "elestio"',
].join('\n')

test('accepts a released version and rejects anything mutable', () => {
  assert.equal(parseReleaseVersion('4.0.13'), '4.0.13')
  assert.equal(parseReleaseVersion('v4.0.13'), '4.0.13')
  assert.equal(parseReleaseVersion(' 4.0.13 '), '4.0.13')

  // A default that new deployments install must never be one of these.
  assert.equal(parseReleaseVersion('latest'), null)
  assert.equal(parseReleaseVersion('main'), null)
  assert.equal(parseReleaseVersion('4.0'), null)
  assert.equal(parseReleaseVersion('4'), null)
  assert.equal(parseReleaseVersion('5.0.0-rc.1'), null)
  assert.equal(parseReleaseVersion('REPLACE_WITH_PUBLISHED_COMPATIBLE_VERSION'), null)
  assert.equal(parseReleaseVersion(''), null)
  assert.equal(parseReleaseVersion(undefined), null)
})

test('rewrites only the release pin, not a neighbouring variable', () => {
  const result = applyElestioVersion(ELESTIO_SAMPLE, '4.0.13')

  assert.match(result, /- key: "SYNAPLAN_VERSION"\n {4}value: "4\.0\.13"/)
  assert.match(result, /- key: "COMPOSE_PROJECT_NAME"\n {4}value: "synaplan"/)
  assert.match(result, /- key: "SYNAPLAN_PLATFORM"\n {4}value: "elestio"/)
  assert.equal(result.split('\n').length, ELESTIO_SAMPLE.split('\n').length)
})

test('keeps the surrounding comments and indentation of the manifest', () => {
  const result = applyElestioVersion(ELESTIO_SAMPLE, '5.1.0')

  assert.ok(result.includes('  # A comment about the release pin.'))
  assert.ok(result.includes('    value: "5.1.0"'))
})

// A bump that quietly changes nothing would advertise a release while new
// deployments keep installing the previous version.
test('fails loudly when the manifest anchor is gone', () => {
  assert.throws(
    () => applyElestioVersion('environments:\n  - key: "OTHER"\n    value: "x"', '4.0.13'),
    /expected exactly one SYNAPLAN_VERSION entry, found 0/
  )

  assert.throws(
    () => applyElestioVersion('  - key: "SYNAPLAN_VERSION"\n  - key: "NEXT"', '4.0.13'),
    /not a value line/
  )
})

// Elestio keeps the last value for a duplicated key, so a second entry would
// decide the installed version while both lines looked correct here.
test('fails when the manifest carries the release pin twice', () => {
  const duplicated = [
    '  - key: "SYNAPLAN_VERSION"',
    '    value: "4.0.12"',
    '  - key: "SYNAPLAN_VERSION"',
    '    value: "4.0.11"',
  ].join('\n')

  assert.throws(
    () => applyElestioVersion(duplicated, '4.0.13'),
    /expected exactly one SYNAPLAN_VERSION entry, found 2/
  )
})

test('rewrites the single assignment in the example configuration', () => {
  const before = [
    '# Release and public endpoint',
    'COMPOSE_PROJECT_NAME=synaplan-production',
    'SYNAPLAN_VERSION=4.0.12',
    'SYNAPLAN_PULL_POLICY=always',
  ].join('\n')

  const result = applyEnvExampleVersion(before, '4.0.13')

  assert.ok(result.includes('SYNAPLAN_VERSION=4.0.13'))
  assert.ok(result.includes('SYNAPLAN_PULL_POLICY=always'))
  assert.ok(!result.includes('4.0.12'))
})

test('fails when the example configuration has no or several assignments', () => {
  assert.throws(
    () => applyEnvExampleVersion('APP_URL=https://example.test', '4.0.13'),
    /found 0/
  )

  assert.throws(
    () => applyEnvExampleVersion('SYNAPLAN_VERSION=1.0.0\nSYNAPLAN_VERSION=2.0.0', '4.0.13'),
    /found 2/
  )
})

// The guard against the failure that prompted this automation: a deployment
// once aborted because the shipped manifest still carried a placeholder instead
// of a published version.
test('the shipped files name one and the same released version', () => {
  const elestio = readElestioVersion(readFileSync(join(ROOT, 'elestio.yml'), 'utf8'))
  const example = readEnvExampleVersion(
    readFileSync(join(ROOT, 'deploy', 'selfhost.env.example'), 'utf8')
  )

  assert.equal(
    parseReleaseVersion(elestio),
    elestio,
    `elestio.yml must pin a released version, found ${JSON.stringify(elestio)}`
  )
  assert.equal(
    parseReleaseVersion(example),
    example,
    `deploy/selfhost.env.example must pin a released version, found ${JSON.stringify(example)}`
  )
  assert.equal(elestio, example, 'both files must install the same version')
})

test('a comment mentioning the variable is not treated as an assignment', () => {
  const before = ['# SYNAPLAN_VERSION picks the release', 'SYNAPLAN_VERSION=4.0.12'].join('\n')

  const result = applyEnvExampleVersion(before, '4.0.13')

  assert.ok(result.includes('# SYNAPLAN_VERSION picks the release'))
  assert.ok(result.includes('SYNAPLAN_VERSION=4.0.13'))
})
