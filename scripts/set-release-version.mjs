#!/usr/bin/env node

// Writes a published release version into the files that decide which version a
// NEW deployment installs: the Elestio manifest, the self-hosting example
// configuration, the Umbrel App Store package, and the AWS and Azure Packer
// builds.
//
// Existing deployments are untouched by design. They keep the version their
// operator pinned, and change it only by following docs/UPDATE_ELESTIO.md,
// docs/UPDATE_SELFHOST.md, or the Umbrel App Store update flow — a redeploy never
// moves a running installation to a different version.
//
// Umbrel also pins the image digest. Umbrel's linter and store policy require
// `tag@sha256:…`, so the digest has to move with the version. The release
// workflow passes the digest of the multi-arch manifest it just published and
// verified; inventing or guessing one here would pin a digest that does not
// exist, or the wrong one.
//
// Every replacement is anchored on the exact line it owns and fails loudly when
// that anchor is missing. A silent no-op would be the worst outcome here: the
// release would look bumped while new deployments kept installing the old
// version, which is precisely the failure this automation exists to prevent.

import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const DEFAULT_ROOT = resolve(SCRIPT_DIR, '..')

// Deliberately the plain patch series only. A pre-release is a legitimate pin
// for someone who types it in, but it must never become the default a new
// deployment silently installs.
const RELEASE_VERSION = /^\d+\.\d+\.\d+$/

// Manifest-list digests as published by docker-push. Anything shorter, longer,
// or without the sha256: prefix is not a digest this script may pin.
const IMAGE_DIGEST = /^sha256:[a-f0-9]{64}$/

const UMBREL_IMAGE_REPOSITORY = 'ghcr.io/metadist/synaplan'

// Full regex-metacharacter escape, not just the dots this one constant happens
// to contain today — a partial escape would silently stop being safe the day
// the repository name changes to include any other special character.
const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

export const parseReleaseVersion = (value) => {
  const version = String(value ?? '').trim().replace(/^v/, '')
  return RELEASE_VERSION.test(version) ? version : null
}

export const parseImageDigest = (value) => {
  const digest = String(value ?? '').trim()
  if (IMAGE_DIGEST.test(digest)) {
    return digest
  }

  // Allow callers to pass the bare hex; normalize to the form Compose pins use.
  if (/^[a-f0-9]{64}$/.test(digest)) {
    return `sha256:${digest}`
  }

  return null
}

// The manifest lists environment variables as `- key:` / `value:` pairs, so the
// value belongs to the key that was seen last. Matching `value:` alone would
// rewrite whichever variable happens to come first in the file.
export const applyElestioVersion = (text, version) => {
  const lines = text.split('\n')
  let keySeen = false
  let replaced = 0

  const result = lines.map((line) => {
    if (/^\s*-\s*key:\s*"?SYNAPLAN_VERSION"?\s*$/.test(line)) {
      keySeen = true
      return line
    }

    if (!keySeen) return line

    const match = /^(\s*)value:\s*.*$/.exec(line)
    if (!match) {
      throw new Error(
        'elestio.yml: the line after the SYNAPLAN_VERSION key is not a value line'
      )
    }

    keySeen = false
    replaced += 1
    return `${match[1]}value: "${version}"`
  })

  // Exactly one, like the example configuration below. A second entry is a
  // mistake worth reporting rather than rewriting: Elestio keeps the last value
  // for a duplicated key, so the deployment would install whichever entry came
  // last while both looked correct here.
  if (replaced !== 1) {
    throw new Error(
      `elestio.yml: expected exactly one SYNAPLAN_VERSION entry, found ${replaced}`
    )
  }

  return result.join('\n')
}

export const readElestioVersion = (text) => {
  const match = /^[ \t]*-[ \t]*key:[ \t]*"?SYNAPLAN_VERSION"?[ \t]*$\n[ \t]*value:[ \t]*"?([^"\n]*)"?[ \t]*$/m.exec(
    text
  )
  return match ? match[1].trim() : null
}

export const readEnvExampleVersion = (text) => {
  const match = /^SYNAPLAN_VERSION=(.*)$/m.exec(text)
  return match ? match[1].trim() : null
}

export const applyEnvExampleVersion = (text, version) => {
  let replaced = 0
  const result = text
    .split('\n')
    .map((line) => {
      if (!/^SYNAPLAN_VERSION=/.test(line)) return line
      replaced += 1
      return `SYNAPLAN_VERSION=${version}`
    })
    .join('\n')

  if (replaced !== 1) {
    throw new Error(
      `deploy/selfhost.env.example: expected exactly one SYNAPLAN_VERSION assignment, found ${replaced}`
    )
  }

  return result
}

// Only the top-level `version:` field. `manifestVersion:` must stay untouched —
// Umbrel's store rejects an unexpected manifestVersion.
export const applyUmbrelAppVersion = (text, version) => {
  let replaced = 0
  const result = text
    .split('\n')
    .map((line) => {
      if (!/^version:\s*.*$/.test(line)) return line
      replaced += 1
      return `version: "${version}"`
    })
    .join('\n')

  if (replaced !== 1) {
    throw new Error(
      `deploy/umbrel/synaplan/umbrel-app.yml: expected exactly one top-level version field, found ${replaced}`
    )
  }

  return result
}

export const readUmbrelAppVersion = (text) => {
  const match = /^version:\s*"?([^"\n]*)"?\s*$/m.exec(text)
  return match ? match[1].trim() : null
}

export const applyUmbrelComposeVersion = (text, version, digest) => {
  let appVersionReplaced = 0
  let imageReplaced = 0
  const imageLine = `x-app-image: &app-image ${UMBREL_IMAGE_REPOSITORY}:${version}@${digest}`

  const result = text
    .split('\n')
    .map((line) => {
      if (/^(\s*)APP_VERSION:\s*.*$/.test(line)) {
        appVersionReplaced += 1
        const indent = /^(\s*)/.exec(line)[1]
        return `${indent}APP_VERSION: "${version}"`
      }

      if (/^x-app-image:\s*&app-image\s+ghcr\.io\/metadist\/synaplan:.+$/.test(line)) {
        imageReplaced += 1
        return imageLine
      }

      return line
    })
    .join('\n')

  if (appVersionReplaced !== 1) {
    throw new Error(
      `deploy/umbrel/synaplan/docker-compose.yml: expected exactly one APP_VERSION assignment, found ${appVersionReplaced}`
    )
  }

  if (imageReplaced !== 1) {
    throw new Error(
      `deploy/umbrel/synaplan/docker-compose.yml: expected exactly one x-app-image pin, found ${imageReplaced}`
    )
  }

  return result
}

export const readUmbrelComposePin = (text) => {
  const appVersion = /^\s*APP_VERSION:\s*"?([^"\n]*)"?\s*$/m.exec(text)?.[1]?.trim() ?? null
  const image = /^x-app-image:\s*&app-image\s+(ghcr\.io\/metadist\/synaplan:[^\s]+)\s*$/m.exec(
    text
  )?.[1] ?? null

  if (!image) {
    return { appVersion, version: null, digest: null, image: null }
  }

  const match = new RegExp(
    `^${escapeRegExp(UMBREL_IMAGE_REPOSITORY)}:([^@]+)@(sha256:[a-f0-9]{64})$`
  ).exec(image)

  return {
    appVersion,
    image,
    version: match?.[1] ?? null,
    digest: match?.[2] ?? null,
  }
}

// A marketplace image carries its release in the Packer default, which is what a
// build with no arguments bakes in — and what firstboot.sh then pins deploy/.env
// to. Only the `synaplan_version` variable block: `default` appears in every
// other variable in the file, so matching it alone would rewrite whichever one
// comes first.
//
// `label` only names the file in the error, so AWS and Azure can share this.
export const applyPackerVersion = (
  text,
  version,
  label = 'deploy/aws/packer/synaplan.pkr.hcl'
) => {
  const lines = text.split('\n')
  let inVersionBlock = false
  let replaced = 0

  const result = lines.map((line) => {
    if (/^variable\s+"synaplan_version"\s*\{\s*$/.test(line)) {
      inVersionBlock = true
      return line
    }

    if (!inVersionBlock) return line

    if (/^\}\s*$/.test(line)) {
      inVersionBlock = false
      return line
    }

    const match = /^(\s*)default(\s*)=\s*.*$/.exec(line)
    if (!match) return line

    replaced += 1
    return `${match[1]}default${match[2]}= "${version}"`
  })

  if (replaced !== 1) {
    throw new Error(
      `${label}: expected exactly one synaplan_version default, found ${replaced}`
    )
  }

  return result.join('\n')
}

export const readPackerVersion = (text) => {
  const block = /^variable\s+"synaplan_version"\s*\{$([\s\S]*?)^\}$/m.exec(text)
  if (!block) return null
  const match = /^\s*default\s*=\s*"([^"\n]*)"\s*$/m.exec(block[1])
  return match ? match[1].trim() : null
}

const TARGETS = [
  { path: 'elestio.yml', apply: (text, version) => applyElestioVersion(text, version) },
  {
    path: join('deploy', 'selfhost.env.example'),
    apply: (text, version) => applyEnvExampleVersion(text, version),
  },
  {
    path: join('deploy', 'umbrel', 'synaplan', 'umbrel-app.yml'),
    apply: (text, version) => applyUmbrelAppVersion(text, version),
  },
  {
    path: join('deploy', 'umbrel', 'synaplan', 'docker-compose.yml'),
    apply: (text, version, digest) => applyUmbrelComposeVersion(text, version, digest),
    needsDigest: true,
  },
  {
    path: join('deploy', 'aws', 'packer', 'synaplan.pkr.hcl'),
    apply: (text, version) =>
      applyPackerVersion(text, version, 'deploy/aws/packer/synaplan.pkr.hcl'),
  },
  {
    path: join('deploy', 'azure', 'packer', 'synaplan.pkr.hcl'),
    apply: (text, version) =>
      applyPackerVersion(text, version, 'deploy/azure/packer/synaplan.pkr.hcl'),
  },
]

export const runCli = (arguments_) => {
  const readOption = (name) => {
    const index = arguments_.indexOf(name)
    return index >= 0 ? arguments_[index + 1] : undefined
  }

  const version = parseReleaseVersion(readOption('--version'))
  if (!version) {
    throw new Error('--version must be a released MAJOR.MINOR.PATCH version')
  }

  const digest = parseImageDigest(readOption('--digest'))
  if (!digest) {
    throw new Error(
      '--digest must be the published multi-arch manifest digest (sha256:…); Umbrel pins tag@digest'
    )
  }

  const rootOption = readOption('--root')
  const root = rootOption ? resolve(rootOption) : DEFAULT_ROOT

  const changed = []
  for (const target of TARGETS) {
    const file = join(root, target.path)
    const before = readFileSync(file, 'utf8')
    const after = target.needsDigest
      ? target.apply(before, version, digest)
      : target.apply(before, version)
    if (after !== before) {
      writeFileSync(file, after)
      changed.push(target.path)
    }
  }

  process.stdout.write(
    changed.length > 0
      ? `Set ${version} (${digest}) in ${changed.join(', ')}\n`
      : `Already at ${version} (${digest})\n`
  )
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    runCli(process.argv.slice(2))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
