import assert from 'node:assert/strict'
import test from 'node:test'

import {
  ARCHITECTURES,
  buildChangeSet,
  runCli,
  versionTitle,
} from '../scripts/marketplace-change-set.mjs'

const options = (overrides = {}) => ({
  product: 'prod-example',
  version: '4.4.3',
  architecture: 'x86_64',
  ami: 'ami-0123456789abcdef0',
  role: 'arn:aws:iam::123456789012:role/synaplan-marketplace-ingestion',
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
// the customer's version picker, and marketplace-versions.yml recognises an
// already submitted architecture by exactly this string.
test('titles a version by release and architecture', () => {
  assert.equal(versionTitle('4.4.3', 'arm64'), '4.4.3 (arm64)')
  assert.equal(details({ architecture: 'arm64' }).Version.VersionTitle, '4.4.3 (arm64)')
})

test('recommends a Graviton instance for arm64 and an Intel one otherwise', () => {
  const recommended = (architecture) =>
    details({ architecture }).DeliveryOptions[0].Details.AmiDeliveryOptionDetails
      .RecommendedInstanceType

  assert.equal(recommended('arm64'), 'm7g.xlarge')
  assert.equal(recommended('x86_64'), 'm7i.xlarge')
})

test('links the release notes, and stays valid without them', () => {
  assert.match(details().Version.ReleaseNotes, /releases\/tag\/v4\.4\.3$/)
  assert.equal(details({ releaseUrl: '' }).Version.ReleaseNotes, 'Synaplan 4.4.3.')
})

// A silently wrong architecture would offer an arm64 image with an Intel
// recommendation, which a customer only discovers after launching it.
test('refuses an architecture it has no recommendation for', () => {
  assert.throws(() => buildChangeSet(options({ architecture: 'riscv64' })), /unknown architecture/)
  assert.deepEqual(ARCHITECTURES.toSorted(), ['arm64', 'x86_64'])
})

test('refuses to build a change set with a missing field', () => {
  assert.throws(() => buildChangeSet(options({ ami: '' })), /missing: ami/)
  assert.throws(() => buildChangeSet(options({ product: '', role: '' })), /missing: product, role/)
})

test('prints the change set as one JSON document', () => {
  const output = runCli([
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
  ])

  const parsed = JSON.parse(output)
  assert.equal(parsed.length, 1)
  assert.equal(parsed[0].DetailsDocument.Version.VersionTitle, '4.4.3 (arm64)')
})
