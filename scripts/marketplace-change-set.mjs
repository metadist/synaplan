#!/usr/bin/env node

// The AWS Marketplace version contract: what a version of the listing is
// called, and which change set offers one built AMI as that version.
//
// It lives here rather than inline in marketplace-versions.yml because it is
// what has to stay consistent across runs. One release is two versions, one per
// architecture, and they are submitted hours apart — AWS refuses a second
// `AddDeliveryOptions` while the first is in flight, and ingesting an AMI takes
// hours. The two halves of a release must still be titled and described the
// same way.
//
// Deciding which architecture is still missing lives here for a second reason:
// it was a two-line jq expression in the workflow, and it was wrong in a way no
// eye caught. Inside `index(...)` a `.` refers to that filter's own input, so
// the interpolated title never matched, every architecture always counted as
// missing, and the same version was offered again on every run until AWS
// answered DUPLICATE_VERSION_TITLE. Here it is covered by tests.

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
  x86_64: 'm7i.xlarge',
  arm64: 'm7g.xlarge',
}

// In submission order, and x86_64 leads deliberately: it is what most customers
// launch, and the one the release's smoke test launched itself.
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

// Which architectures of a release the listing has not been offered yet, in the
// order they should be submitted.
//
// `known` is every version title the listing shows plus every one that has been
// sent to it, including the titles of submissions that failed. A failed version
// counts as offered on purpose: AWS fails one because it found something in the
// image, so the same image fails again, and retrying it hourly would only repeat
// a rejection somebody has already read.
export const missingArchitectures = (version, known = []) => {
  if (!version) {
    throw new Error('missing: version')
  }
  if (!Array.isArray(known)) {
    throw new Error('known must be an array of version titles')
  }

  return ARCHITECTURES.filter(
    (architecture) => !known.includes(versionTitle(version, architecture))
  )
}

const readOption = (arguments_, name) => {
  const index = arguments_.indexOf(name)
  return index === -1 ? null : (arguments_[index + 1] ?? null)
}

export const runCli = (arguments_ = []) => {
  const [command, ...options] = arguments_

  if (command === 'missing') {
    const known = JSON.parse(readOption(options, '--known') ?? '[]')
    // Space separated, because the caller is a shell that walks it word by word.
    return `${missingArchitectures(readOption(options, '--version'), known).join(' ')}\n`
  }

  if (command !== 'change-set') {
    throw new Error(`unknown command ${JSON.stringify(command ?? '')}, expected change-set or missing`)
  }

  const changeSet = buildChangeSet({
    product: readOption(options, '--product'),
    version: readOption(options, '--version'),
    architecture: readOption(options, '--architecture'),
    ami: readOption(options, '--ami'),
    role: readOption(options, '--role'),
    releaseUrl: readOption(options, '--release-url') ?? '',
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
