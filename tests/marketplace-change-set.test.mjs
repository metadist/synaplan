import assert from 'node:assert/strict'
import test from 'node:test'

import {
  ARCHITECTURES,
  buildChangeSet,
  missingArchitectures,
  runCli,
  versionTitle,
} from '../scripts/marketplace-change-set.mjs'

const options = (overrides = {}) => ({
  product: 'prod-example',
  version: '4.4.3',
  architecture: 'x86_64',
  ami: 'ami-0123456789abcdef0',
  role: 'arn:aws:iam::123456789012:role/synaplan-marketplace-ingestion',
  instanceType: 'c7i.xlarge',
  releaseUrl: 'https://github.com/metadist/synaplan/releases/tag/v4.4.3',
  ...overrides,
})

const details = (overrides) => buildChangeSet(options(overrides))[0].DetailsDocument

test('offers the AMI as one version of the listing', () => {
  const [change] = buildChangeSet(options())

  assert.equal(change.ChangeType, 'AddDeliveryOptions')
  assert.deepEqual(change.Entity, { Identifier: 'prod-example', Type: 'AmiProduct@1.0' })

  const source = change.DetailsDocument.DeliveryOptions[0].Details.AmiDeliveryOptionDetails.AmiSource
  assert.equal(source.AmiId, 'ami-0123456789abcdef0')
  assert.equal(source.AccessRoleArn, options().role)
  assert.equal(source.UserName, 'ec2-user')
  assert.equal(source.OperatingSystemName, 'AMAZONLINUX')
})

// The title is the only thing distinguishing the two versions of one release in
// the customer's version picker, so it names the hardware rather than only the
// architecture — and keeps the architecture last, where missingArchitectures
// looks for it.
test('titles a version by release and hardware', () => {
  assert.equal(versionTitle('4.4.3', 'arm64'), '4.4.3 - Graviton (arm64)')
  assert.equal(versionTitle('4.4.3', 'x86_64'), '4.4.3 - Intel or AMD (x86_64)')
  assert.equal(details({ architecture: 'arm64' }).Version.VersionTitle, '4.4.3 - Graviton (arm64)')
})

// The listing decides which instance types it offers, so the recommendation has
// to come from there. Inventing one here cost a rejected submission:
// RECOMMENDED_INSTANCE_TYPE_NOT_AVAILABLE, hours after the fact.
test('recommends the instance type it was given', () => {
  const recommended = (overrides) =>
    details(overrides).DeliveryOptions[0].Details.AmiDeliveryOptionDetails.RecommendedInstanceType

  assert.equal(recommended({ architecture: 'arm64', instanceType: 'c7g.xlarge' }), 'c7g.xlarge')
  assert.equal(recommended({ instanceType: 'm7i.2xlarge' }), 'm7i.2xlarge')
  assert.throws(() => buildChangeSet(options({ instanceType: '' })), /missing: instanceType/)
})

test('links the release notes, and stays valid without them', () => {
  assert.match(details().Version.ReleaseNotes, /releases\/tag\/v4\.4\.3$/)
  assert.equal(details({ releaseUrl: '' }).Version.ReleaseNotes, 'Synaplan 4.4.3.')
})

// An architecture nothing here knows about would be offered under a title that
// says nothing about the hardware, which a customer only discovers by launching
// it on the wrong instance.
test('refuses an architecture it cannot describe', () => {
  assert.throws(() => buildChangeSet(options({ architecture: 'riscv64' })), /unknown architecture/)
  assert.deepEqual(ARCHITECTURES.toSorted(), ['arm64', 'x86_64'])
})

test('refuses to build a change set with a missing field', () => {
  assert.throws(() => buildChangeSet(options({ ami: '' })), /missing: ami/)
  assert.throws(() => buildChangeSet(options({ product: '', role: '' })), /missing: product, role/)
})

// This is what a jq expression in the workflow got wrong: it compared against
// nothing at all, so it always answered "both", and the release run kept
// offering a version the listing already had.
test('recognises the architectures a release has already been offered', () => {
  assert.deepEqual(missingArchitectures('4.4.3', []), ['x86_64', 'arm64'])
  assert.deepEqual(missingArchitectures('4.4.3', ['4.2.4', versionTitle('4.4.3', 'x86_64')]), [
    'arm64',
  ])
  assert.deepEqual(
    missingArchitectures('4.4.3', ARCHITECTURES.map((a) => versionTitle('4.4.3', a))),
    []
  )
})

// The titles submitted before the hardware names existed are in the listing for
// good, and a version that stops being recognised is offered a second time.
test('still recognises the titles submitted under the old scheme', () => {
  assert.deepEqual(missingArchitectures('4.4.3', ['4.2.4', '4.4.3 (x86_64)']), ['arm64'])
  assert.deepEqual(missingArchitectures('4.4.3', ['4.4.3 (x86_64)', '4.4.3 (arm64)']), [])
})

// A release is not the one before it, and a title that merely contains the
// version is not that version.
test('does not confuse one release with another', () => {
  assert.deepEqual(missingArchitectures('4.4.3', ['4.4.2 (x86_64)', '4.4.2 (arm64)']), [
    'x86_64',
    'arm64',
  ])
  assert.deepEqual(missingArchitectures('4.4.3', ['14.4.3 (x86_64)', '4.4.30 (arm64)']), [
    'x86_64',
    'arm64',
  ])
})

test('refuses to decide without a release', () => {
  assert.throws(() => missingArchitectures('', ['4.4.3 (arm64)']), /missing: version/)
  assert.throws(() => missingArchitectures('4.4.3', 'not an array'), /must be an array/)
})

test('prints the missing architectures for a shell to walk', () => {
  assert.equal(runCli(['missing', '--version', '4.4.3', '--known', '["4.4.3 (arm64)"]']), 'x86_64\n')
  assert.equal(runCli(['missing', '--version', '4.4.3']), 'x86_64 arm64\n')
})

test('refuses to title a version for an architecture it has no name for', () => {
  assert.throws(() => versionTitle('4.4.3', 'riscv64'), /unknown architecture/)
})

test('refuses a command it does not know', () => {
  assert.throws(() => runCli([]), /expected change-set or missing/)
  assert.throws(() => runCli(['submit']), /expected change-set or missing/)
})

test('prints the change set as one JSON document', () => {
  const output = runCli([
    'change-set',
    '--product',
    'prod-example',
    '--version',
    '4.4.3',
    '--architecture',
    'arm64',
    '--ami',
    'ami-0123456789abcdef0',
    '--role',
    'arn:aws:iam::123456789012:role/ingestion',
    '--instance-type',
    'c7g.xlarge',
  ])

  const parsed = JSON.parse(output)
  assert.equal(parsed.length, 1)
  assert.equal(parsed[0].DetailsDocument.Version.VersionTitle, '4.4.3 - Graviton (arm64)')
})
