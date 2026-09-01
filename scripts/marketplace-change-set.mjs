#!/usr/bin/env node

// Builds the AWS Marketplace change set that offers one built AMI to the
// listing as a new version.
//
// It lives here rather than inline in a workflow because two of them need the
// identical document: aws-ami.yml submits the first architecture right after a
// release is built, and marketplace-versions.yml submits the remaining one
// later. AWS refuses a second `AddDeliveryOptions` while the first is still in
// flight, and an AMI version takes hours to ingest, so those two submissions
// cannot happen in the same run — but they must produce the same version shape,
// or the listing would carry two differently described halves of one release.

import { pathToFileURL } from 'node:url'
import { resolve } from 'node:path'

// Every delivery option of one version shares a single AmiSource, so one
// version can carry exactly one AMI. Two architectures are therefore two
// versions, and the title is what tells them apart in the customer's version
// picker.
export const versionTitle = (version, architecture) => `${version} (${architecture})`

// Graviton for arm64, the comparable Intel size otherwise. Only a
// recommendation shown in the launch form; the customer may pick anything.
const INSTANCE_TYPES = {
  arm64: 'm7g.xlarge',
  x86_64: 'm7i.xlarge',
}

export const ARCHITECTURES = Object.keys(INSTANCE_TYPES)

// Mirrors deploy/aws/packer/synaplan.pkr.hcl's source_ami_filter and
// deploy/aws/scripts/harden.sh: Amazon Linux 2023, ec2-user, sshd listening but
// password and root login refused.
const AMI_SOURCE = {
  UserName: 'ec2-user',
  ScanningPort: 22,
  OperatingSystemName: 'AMAZONLINUX',
  OperatingSystemVersion: '2023',
}

const USAGE_INSTRUCTIONS = [
  'Launch the instance, then open https://<public IP or your domain>.',
  'Sign in with the administrator address shown in the CloudFormation outputs;',
  'the single-use password is stored in SSM Parameter Store under',
  '/synaplan/<instance-id>/admin-password and must be replaced at first sign-in.',
  'Add an AI provider key under Admin, AI Providers.',
  'Full guide: https://github.com/metadist/synaplan/blob/main/deploy/aws/README.md',
].join(' ')

// The listing is reachable over both, because the instance redirects plain HTTP
// to HTTPS and issues its own certificate on first boot.
const SECURITY_GROUPS = [
  { IpProtocol: 'tcp', FromPort: 443, ToPort: 443, IpRanges: ['0.0.0.0/0'] },
  { IpProtocol: 'tcp', FromPort: 80, ToPort: 80, IpRanges: ['0.0.0.0/0'] },
]

export const buildChangeSet = ({ product, version, architecture, ami, role, releaseUrl }) => {
  const missing = Object.entries({ product, version, architecture, ami, role })
    .filter(([, value]) => !value)
    .map(([name]) => name)
  if (missing.length > 0) {
    throw new Error(`missing: ${missing.join(', ')}`)
  }

  const instanceType = INSTANCE_TYPES[architecture]
  if (instanceType === undefined) {
    throw new Error(
      `unknown architecture ${JSON.stringify(architecture)}, expected one of ${ARCHITECTURES.join(', ')}`
    )
  }

  const notes = releaseUrl
    ? `Synaplan ${version}. Release notes: ${releaseUrl}`
    : `Synaplan ${version}.`

  return [
    {
      ChangeType: 'AddDeliveryOptions',
      Entity: { Identifier: product, Type: 'AmiProduct@1.0' },
      DetailsDocument: {
        Version: { VersionTitle: versionTitle(version, architecture), ReleaseNotes: notes },
        DeliveryOptions: [
          {
            Details: {
              AmiDeliveryOptionDetails: {
                AmiSource: { AmiId: ami, AccessRoleArn: role, ...AMI_SOURCE },
                UsageInstructions: USAGE_INSTRUCTIONS,
                RecommendedInstanceType: instanceType,
                SecurityGroups: SECURITY_GROUPS,
              },
            },
          },
        ],
      },
    },
  ]
}

const readOption = (arguments_, name) => {
  const index = arguments_.indexOf(name)
  return index === -1 ? null : (arguments_[index + 1] ?? null)
}

export const runCli = (arguments_ = []) => {
  const changeSet = buildChangeSet({
    product: readOption(arguments_, '--product'),
    version: readOption(arguments_, '--version'),
    architecture: readOption(arguments_, '--architecture'),
    ami: readOption(arguments_, '--ami'),
    role: readOption(arguments_, '--role'),
    releaseUrl: readOption(arguments_, '--release-url') ?? '',
  })

  return `${JSON.stringify(changeSet)}\n`
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    process.stdout.write(runCli(process.argv.slice(2)))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
