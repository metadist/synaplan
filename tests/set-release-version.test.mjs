import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
  applyElestioVersion,
  applyEnvExampleVersion,
  applyUmbrelAppVersion,
  applyUmbrelComposeVersion,
  parseImageDigest,
  parseReleaseVersion,
  readElestioVersion,
  readEnvExampleVersion,
  readUmbrelAppVersion,
  readUmbrelComposePin,
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

const UMBREL_COMPOSE_SAMPLE = [
  'x-app-environment: &app-environment',
  '  SYNAPLAN_PLATFORM: umbrel',
  '  APP_VERSION: "4.0.12"',
  '  APP_URL: "http://example.local:8300"',
  '',
  'x-app-image: &app-image ghcr.io/metadist/synaplan:4.0.12@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  '',
  'services:',
  '  web:',
  '    image: *app-image',
].join('\n')

const DIGEST_A = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
const DIGEST_B = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'

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

test('accepts a published digest and rejects anything that is not one', () => {
  assert.equal(parseImageDigest(DIGEST_A), DIGEST_A)
  assert.equal(
    parseImageDigest('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    DIGEST_A
  )

  assert.equal(parseImageDigest('sha256:short'), null)
  assert.equal(parseImageDigest('latest'), null)
  assert.equal(parseImageDigest(''), null)
  assert.equal(parseImageDigest(undefined), null)
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

test('rewrites the Umbrel store version without touching manifestVersion', () => {
  const before = [
    'manifestVersion: 1',
    'id: synaplan',
    'version: "4.0.12"',
    'name: Synaplan',
  ].join('\n')

  const result = applyUmbrelAppVersion(before, '4.0.14')

  assert.ok(result.includes('manifestVersion: 1'))
  assert.ok(result.includes('version: "4.0.14"'))
  assert.ok(!result.includes('version: "4.0.12"'))
})

test('fails when the Umbrel store version field is missing or duplicated', () => {
  assert.throws(
    () => applyUmbrelAppVersion('manifestVersion: 1\nid: synaplan\n', '4.0.14'),
    /found 0/
  )

  assert.throws(
    () => applyUmbrelAppVersion('version: "1"\nversion: "2"\n', '4.0.14'),
    /found 2/
  )
})

test('rewrites Umbrel APP_VERSION and the image pin together', () => {
  const result = applyUmbrelComposeVersion(UMBREL_COMPOSE_SAMPLE, '4.0.14', DIGEST_B)

  assert.ok(result.includes('  APP_VERSION: "4.0.14"'))
  assert.ok(
    result.includes(
      `x-app-image: &app-image ghcr.io/metadist/synaplan:4.0.14@${DIGEST_B}`
    )
  )
  assert.ok(result.includes('SYNAPLAN_PLATFORM: umbrel'))
  assert.ok(!result.includes('4.0.12'))
})

test('fails when either Umbrel compose anchor is missing', () => {
  assert.throws(
    () =>
      applyUmbrelComposeVersion(
        'x-app-image: &app-image ghcr.io/metadist/synaplan:4.0.12@' + DIGEST_A,
        '4.0.14',
        DIGEST_B
      ),
    /APP_VERSION/
  )

  assert.throws(
    () => applyUmbrelComposeVersion('  APP_VERSION: "4.0.12"\n', '4.0.14', DIGEST_B),
    /x-app-image/
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
  const umbrelApp = readUmbrelAppVersion(
    readFileSync(join(ROOT, 'deploy', 'umbrel', 'synaplan', 'umbrel-app.yml'), 'utf8')
  )
  const umbrelCompose = readUmbrelComposePin(
    readFileSync(join(ROOT, 'deploy', 'umbrel', 'synaplan', 'docker-compose.yml'), 'utf8')
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
  assert.equal(
    parseReleaseVersion(umbrelApp),
    umbrelApp,
    `umbrel-app.yml must pin a released version, found ${JSON.stringify(umbrelApp)}`
  )
  assert.equal(
    parseReleaseVersion(umbrelCompose.appVersion),
    umbrelCompose.appVersion,
    `umbrel docker-compose APP_VERSION must be a released version, found ${JSON.stringify(umbrelCompose.appVersion)}`
  )
  assert.equal(
    parseReleaseVersion(umbrelCompose.version),
    umbrelCompose.version,
    `umbrel docker-compose image tag must be a released version, found ${JSON.stringify(umbrelCompose.version)}`
  )
  assert.equal(
    parseImageDigest(umbrelCompose.digest),
    umbrelCompose.digest,
    `umbrel docker-compose image must pin a digest, found ${JSON.stringify(umbrelCompose.digest)}`
  )

  assert.equal(elestio, example, 'Elestio and self-host must install the same version')
  assert.equal(elestio, umbrelApp, 'Umbrel store version must match Elestio')
  assert.equal(elestio, umbrelCompose.appVersion, 'Umbrel APP_VERSION must match Elestio')
  assert.equal(elestio, umbrelCompose.version, 'Umbrel image tag must match Elestio')
})

test('a comment mentioning the variable is not treated as an assignment', () => {
  const before = ['# SYNAPLAN_VERSION picks the release', 'SYNAPLAN_VERSION=4.0.12'].join('\n')

  const result = applyEnvExampleVersion(before, '4.0.13')

  assert.ok(result.includes('# SYNAPLAN_VERSION picks the release'))
  assert.ok(result.includes('SYNAPLAN_VERSION=4.0.13'))
})
