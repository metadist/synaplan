#!/usr/bin/env node

// Reads back the version that the deployment catalog currently pins, using the
// same anchors set-release-version.mjs writes with. Two automations need this:
// the rollout workflow, which may only merge a pin that matches the published
// release manifest, and the Umbrel store sync, which has to name the version it
// carries into the store package.
//
// Reading all five pins rather than one is the point. Any disagreement between
// them means the catalog is half-bumped, and a half-bumped catalog must never be
// merged or submitted anywhere — it would install one version and advertise
// another.

import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

import {
  parseImageDigest,
  parseReleaseVersion,
  readElestioVersion,
  readEnvExampleVersion,
  readPackerVersion,
  readUmbrelAppVersion,
  readUmbrelComposePin,
} from './set-release-version.mjs'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const DEFAULT_ROOT = resolve(SCRIPT_DIR, '..')

// The files this reader needs, published so a caller can collect them from
// somewhere other than a checkout. The rollout workflow does exactly that: it
// judges a pull request without checking that pull request out, so it fetches
// these paths as data and never runs anything from the branch it is judging.
export const CATALOG_PATHS = [
  'elestio.yml',
  'deploy/selfhost.env.example',
  'deploy/umbrel/synaplan/umbrel-app.yml',
  'deploy/umbrel/synaplan/docker-compose.yml',
  'deploy/aws/packer/synaplan.pkr.hcl',
]

export const readPinnedRelease = (root = DEFAULT_ROOT) => {
  const read = (path) => readFileSync(join(root, path), 'utf8')

  const umbrelCompose = readUmbrelComposePin(read('deploy/umbrel/synaplan/docker-compose.yml'))

  // Checked before the versions: an image pinned without a digest leaves both
  // the tag and the digest unreadable, and reporting that as a missing version
  // would send the reader looking for the wrong thing.
  const digest = parseImageDigest(umbrelCompose.digest)
  if (digest === null) {
    throw new Error(
      `deploy/umbrel/synaplan/docker-compose.yml does not pin a published image digest, found ${JSON.stringify(umbrelCompose.image)}`
    )
  }

  const pins = {
    'elestio.yml': readElestioVersion(read('elestio.yml')),
    'deploy/selfhost.env.example': readEnvExampleVersion(read('deploy/selfhost.env.example')),
    'deploy/umbrel/synaplan/umbrel-app.yml': readUmbrelAppVersion(
      read('deploy/umbrel/synaplan/umbrel-app.yml')
    ),
    'deploy/umbrel/synaplan/docker-compose.yml (APP_VERSION)': umbrelCompose.appVersion,
    'deploy/umbrel/synaplan/docker-compose.yml (image tag)': umbrelCompose.version,
    'deploy/aws/packer/synaplan.pkr.hcl': readPackerVersion(
      read('deploy/aws/packer/synaplan.pkr.hcl')
    ),
  }

  const missing = Object.entries(pins)
    .filter(([, version]) => version === null)
    .map(([source]) => source)
  if (missing.length > 0) {
    throw new Error(`no version pin found in: ${missing.join(', ')}`)
  }

  const distinct = [...new Set(Object.values(pins))]
  if (distinct.length !== 1) {
    const detail = Object.entries(pins)
      .map(([source, version]) => `${source}=${version}`)
      .join(', ')
    throw new Error(`the deployment catalog pins more than one version: ${detail}`)
  }

  const [version] = distinct
  if (parseReleaseVersion(version) !== version) {
    throw new Error(`the pinned version ${JSON.stringify(version)} is not a plain release`)
  }

  return { version, digest }
}

export const runCli = (arguments_ = []) => {
  if (arguments_.includes('--paths')) {
    return `${CATALOG_PATHS.join('\n')}\n`
  }

  const [root] = arguments_.filter((argument) => !argument.startsWith('-'))
  return `${JSON.stringify(readPinnedRelease(root ? resolve(root) : DEFAULT_ROOT))}\n`
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    process.stdout.write(runCli(process.argv.slice(2)))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
