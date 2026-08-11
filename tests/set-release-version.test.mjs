import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
  applyElestioVersion,
  applyEnvExampleVersion,
  applyPackerVersion,
  applyUmbrelAppVersion,
  applyUmbrelComposeVersion,
  parseImageDigest,
  parseReleaseVersion,
  readElestioVersion,
  readEnvExampleVersion,
  readPackerVersion,
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

// Two variable blocks, both with a `default`, because that is the shape of the
// real file: an anchor on `default` alone would rewrite whichever comes first.
const PACKER_SAMPLE = [
  'variable "synaplan_version" {',
  '  type = string',
  '  # Raised by the release automation.',
  '  default     = "4.0.12"',
  '  description = "Released SemVer version to bake in."',
  '}',
  '',
  'variable "region" {',
  '  type        = string',
  '  default     = "us-east-1"',
  '  description = "Build region."',
  '}',
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

test('rewrites the Packer release default and no other variable default', () => {
  const result = applyPackerVersion(PACKER_SAMPLE, '4.0.14')

  assert.ok(result.includes('  default     = "4.0.14"'))
  assert.ok(result.includes('  default     = "us-east-1"'))
  assert.ok(result.includes('  # Raised by the release automation.'))
  assert.ok(!result.includes('4.0.12'))
  assert.equal(result.split('\n').length, PACKER_SAMPLE.split('\n').length)
})

test('fails when the Packer release default is missing or duplicated', () => {
  assert.throws(
    () => applyPackerVersion('variable "synaplan_version" {\n  type = string\n}\n', '4.0.14'),
    /found 0/
  )

  assert.throws(
    () =>
      applyPackerVersion(
        'variable "synaplan_version" {\n  default = "1.0.0"\n  default = "2.0.0"\n}\n',
        '4.0.14'
      ),
    /found 2/
  )
})

// A default outside the block must not be picked up, in either direction: the
// writer would rewrite the wrong variable, the reader would report its value as
// the release.
test('the Packer anchors ignore a default that belongs to another variable', () => {
  const regionFirst = [
    'variable "region" {',
    '  default = "us-east-1"',
    '}',
    '',
    'variable "synaplan_version" {',
    '  default = "4.0.12"',
    '}',
  ].join('\n')

  assert.equal(readPackerVersion(regionFirst), '4.0.12')
  assert.ok(applyPackerVersion(regionFirst, '4.0.14').includes('  default = "us-east-1"'))
  assert.equal(readPackerVersion('variable "region" {\n  default = "us-east-1"\n}'), null)
})

// readUmbrelComposePin builds a regex from the repository name. A partial
// escape (dots only) would still work for `ghcr.io/metadist/synaplan` today,
// but would misparse — or throw on — a line whose image differs by even one
// regex metacharacter, silently or loudly breaking the release automation.
test('the image-pin regex is not confused by a similar but different repository', () => {
  const before = UMBREL_COMPOSE_SAMPLE.replace(
    'ghcr.io/metadist/synaplan',
    'ghcr.io/metadistXsynaplan'
  )

  const result = readUmbrelComposePin(before)

  assert.equal(result.version, null)
  assert.equal(result.digest, null)
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
  const packer = readPackerVersion(
    readFileSync(join(ROOT, 'deploy', 'aws', 'packer', 'synaplan.pkr.hcl'), 'utf8')
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

  assert.equal(
    parseReleaseVersion(packer),
    packer,
    `the AWS Packer build must bake a released version, found ${JSON.stringify(packer)}`
  )

  assert.equal(elestio, example, 'Elestio and self-host must install the same version')
  assert.equal(elestio, umbrelApp, 'Umbrel store version must match Elestio')
  assert.equal(elestio, umbrelCompose.appVersion, 'Umbrel APP_VERSION must match Elestio')
  assert.equal(elestio, umbrelCompose.version, 'Umbrel image tag must match Elestio')
  assert.equal(elestio, packer, 'The AWS AMI must bake the same version as Elestio')
})

test('a comment mentioning the variable is not treated as an assignment', () => {
  const before = ['# SYNAPLAN_VERSION picks the release', 'SYNAPLAN_VERSION=4.0.12'].join('\n')

  const result = applyEnvExampleVersion(before, '4.0.13')

  assert.ok(result.includes('# SYNAPLAN_VERSION picks the release'))
  assert.ok(result.includes('SYNAPLAN_VERSION=4.0.13'))
})
