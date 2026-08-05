#!/usr/bin/env node

// Resolves the version string baked into the application image as APP_VERSION.
//
// docker/metadata-action's `version` output follows the first image tag. On the
// default branch that tag is deliberately `latest`, so using the metadata value
// verbatim made every user-facing surface — the sidebar above the profile, the
// admin update panel, the OpenAPI info block — display the word "latest"
// instead of a release number.
//
// Rules, in order:
//   1. A plain MAJOR.MINOR.PATCH from metadata (a release-tag build) wins.
//   2. A release git ref (vX.Y.Z) wins when metadata is missing or mutable.
//   3. Otherwise the newest release tag in the checkout — never the word
//      "latest", never a branch name, never a PR ref.

import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

import { latestReleaseTag } from './next-release-tag.mjs'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const DEFAULT_ROOT = resolve(SCRIPT_DIR, '..')

const PLAIN_RELEASE = /^\d+\.\d+\.\d+$/
const TAGGED_RELEASE = /^v(\d+\.\d+\.\d+)$/

export const resolveAppVersion = ({ metaVersion, refName, releaseTags }) => {
  const meta = String(metaVersion ?? '').trim()
  if (PLAIN_RELEASE.test(meta)) {
    return meta
  }

  const ref = String(refName ?? '').trim()
  const fromRef = TAGGED_RELEASE.exec(ref)
  if (fromRef) {
    return fromRef[1]
  }

  const latest = latestReleaseTag(releaseTags ?? [])
  if (latest) {
    return latest.tag.replace(/^v/, '')
  }

  return 'dev'
}

const readTags = (root) =>
  execFileSync('git', ['tag', '--list', 'v*'], { cwd: root, encoding: 'utf8' })
    .split(/\r?\n/)
    .filter(Boolean)

export const runCli = (arguments_) => {
  const readOption = (name) => {
    const index = arguments_.indexOf(name)
    return index >= 0 ? arguments_[index + 1] : undefined
  }

  const rootOption = readOption('--root')
  const root = rootOption ? resolve(rootOption) : DEFAULT_ROOT
  const version = resolveAppVersion({
    metaVersion: readOption('--meta'),
    refName: readOption('--ref'),
    releaseTags: readTags(root),
  })

  process.stdout.write(`${version}\n`)
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    runCli(process.argv.slice(2))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
