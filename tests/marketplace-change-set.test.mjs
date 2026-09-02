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

// The architecture stays in the title even though there is only ever one: it
// already shipped that way in the first published version, and dropping it now
// would make the version picker inconsistent for buyers, not simpler.
test('titles a version by release and architecture', () => {
  assert.equal(versionTitle('4.4.3', 'x86_64'), '4.4.3 (x86_64)')
  assert.equal(details().Version.VersionTitle, '4.4.3 (x86_64)')
})

// The listing decides which instance types it offers, so the recommendation has
// to come from there. Inventing one here cost a rejected submission:
// RECOMMENDED_INSTANCE_TYPE_NOT_AVAILABLE, hours after the fact.
test('recommends the instance type it was given', () => {
  const recommended = (overrides) =>
    details(overrides).DeliveryOptions[0].Details.AmiDeliveryOptionDetails.RecommendedInstanceType

  assert.equal(recommended({ instanceType: 'm7i.2xlarge' }), 'm7i.2xlarge')
  assert.equal(recommended({ instanceType: 'c7i.xlarge' }), 'c7i.xlarge')
  assert.throws(() => buildChangeSet(options({ instanceType: '' })), /missing: instanceType/)
})

test('links the release notes, and stays valid without them', () => {
  assert.match(details().Version.ReleaseNotes, /releases\/tag\/v4\.4\.3$/)
  assert.equal(details({ releaseUrl: '' }).Version.ReleaseNotes, 'Synaplan 4.4.3.')
})

// An AWS Marketplace AMI product is tied to one CPU architecture, checked
// against the architecture of the versions it has already published — arm64
// cannot become a second version of this listing. Offering it would need a
// separate listing, with its own product id, not a second architecture here.
test('offers only the architecture the listing was built for', () => {
  assert.throws(() => buildChangeSet(options({ architecture: 'arm64' })), /unknown architecture/)
  assert.deepEqual(ARCHITECTURES, ['x86_64'])
})

test('refuses to build a change set with a missing field', () => {
  assert.throws(() => buildChangeSet(options({ ami: '' })), /missing: ami/)
  assert.throws(() => buildChangeSet(options({ product: '', role: '' })), /missing: product, role/)
})

// This is what a jq expression in the workflow got wrong: it compared against
// nothing at all, so it always answered "missing", and the release run kept
// offering a version the listing already had.
test('recognises a release the listing has already been offered', () => {
  assert.deepEqual(missingArchitectures('4.4.3', []), ['x86_64'])
  assert.deepEqual(missingArchitectures('4.4.3', ['4.2.4', versionTitle('4.4.3', 'x86_64')]), [])
})

// A title edited in the Management Portal must not be offered a second time.
// Only the release at the front and the architecture at the back are matched,
// so anything between them is prose.
test('recognises a title that carries prose between the two', () => {
  assert.deepEqual(missingArchitectures('4.4.3', ['4.4.3 - Intel or AMD (x86_64)']), [])
  assert.deepEqual(missingArchitectures('4.4.3', ['4.4.3 LTS (x86_64)']), [])
})

// A release is not the one before it, and a title that merely contains the
// version is not that version.
test('does not confuse one release with another', () => {
  assert.deepEqual(missingArchitectures('4.4.3', ['4.4.2 (x86_64)']), ['x86_64'])
  assert.deepEqual(missingArchitectures('4.4.3', ['14.4.3 (x86_64)', '4.4.30 (x86_64)']), [
    'x86_64',
  ])
})

test('refuses to decide without a release', () => {
  assert.throws(() => missingArchitectures('', ['4.4.3 (x86_64)']), /missing: version/)
  assert.throws(() => missingArchitectures('4.4.3', 'not an array'), /must be an array/)
})

test('prints the missing architecture for a shell to walk', () => {
  assert.equal(runCli(['missing', '--version', '4.4.3', '--known', '["4.4.3 (x86_64)"]']), '\n')
  assert.equal(runCli(['missing', '--version', '4.4.3']), 'x86_64\n')
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
    'x86_64',
    '--ami',
    'ami-0123456789abcdef0',
    '--role',
    'arn:aws:iam::123456789012:role/ingestion',
    '--instance-type',
    'c7i.xlarge',
  ])

  const parsed = JSON.parse(output)
  assert.equal(parsed.length, 1)
  assert.equal(parsed[0].DetailsDocument.Version.VersionTitle, '4.4.3 (x86_64)')
})
