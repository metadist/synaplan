#!/usr/bin/env node

// Builds the release manifest a running instance polls to learn that a newer
// release exists. CI publishes the result to the dedicated `release-manifest`
// branch after the image for the tag is pushed and verified — see the
// `release-manifest` job in .github/workflows/ci.yml for why that branch.
//
// Everything a human may have edited on that branch is carried over: the
// `yanked` list always, and `releasedAt`/`severity` when the same version is
// republished (a re-run of the same tag must not overwrite a `security` mark).

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

export const SCHEMA = 1

// Only plain releases are advertised. A pre-release (v5.0.0-rc.1) is a valid
// deployment pin but must never become `stable`, and next-release-tag.mjs keeps
// them out of the release series for the same reason.
const RELEASE_VERSION = /^(\d+)\.(\d+)\.(\d+)$/
const REPOSITORY = /^[^/\s]+\/[^/\s]+$/
const SEVERITIES = ['normal', 'security']

export const parseReleaseVersion = (version) => {
  const match = RELEASE_VERSION.exec(String(version).trim())
  if (!match) return null
  return { major: Number(match[1]), minor: Number(match[2]), patch: Number(match[3]) }
}

const compareVersions = (left, right) =>
  left.major - right.major || left.minor - right.minor || left.patch - right.patch

// The yanked list is maintained by hand on the branch, so it is carried over
// verbatim — entries may be a bare version or an object with a reason next to
// it. A list that cannot be read is an error rather than an empty list: losing a
// yank would re-advertise a release that was withdrawn on purpose.
export const yankedEntries = (existing) => {
  const yanked = existing?.yanked
  if (yanked === undefined || yanked === null) return []
  if (!Array.isArray(yanked)) {
    throw new Error('The existing manifest has a non-array `yanked` list; refusing to drop it.')
  }
  return yanked
}

const yankedVersion = (entry) => (typeof entry === 'string' ? entry : entry?.version)

export const buildManifest = ({
  version,
  repository,
  releasedAt,
  severity = 'normal',
  existing = null
}) => {
  const released = parseReleaseVersion(version)
  if (released === null) {
    throw new Error(
      `Not a plain release version: "${version}". Pre-releases and mutable tags are never advertised as stable.`
    )
  }
  if (!SEVERITIES.includes(severity)) {
    throw new Error(`Unknown severity "${severity}"; expected one of ${SEVERITIES.join(', ')}.`)
  }
  if (!REPOSITORY.test(String(repository))) {
    throw new Error(`Not an owner/name repository: "${repository}".`)
  }
  if (!releasedAt) {
    throw new Error('A release date is required.')
  }

  const yanked = yankedEntries(existing)
  if (yanked.some((entry) => yankedVersion(entry) === version)) {
    throw new Error(`${version} is on the yanked list; refusing to advertise it as stable.`)
  }

  const previous = existing?.stable ?? null
  const previousVersion = parseReleaseVersion(previous?.version ?? '')
  // An older maintenance tag must not move `stable` backwards: every instance
  // would then be told that a release older than the one it runs is the newest.
  if (previousVersion !== null && compareVersions(previousVersion, released) > 0) return existing
  const republished = previousVersion !== null && compareVersions(previousVersion, released) === 0

  return {
    schema: SCHEMA,
    stable: {
      version,
      releasedAt: (republished && previous.releasedAt) || releasedAt,
      notesUrl: `https://github.com/${repository}/releases/tag/v${version}`,
      severity: (republished && previous.severity) || severity
    },
    yanked
  }
}

const readOption = (arguments_, name) => {
  const index = arguments_.indexOf(`--${name}`)
  return index >= 0 ? arguments_[index + 1] : undefined
}

// Seconds, no milliseconds: the manifest is read by humans as often as by
// clients, and `2026-08-10T09:00:00Z` is still a valid RFC 3339 instant.
const utcSecond = (date) => `${date.toISOString().slice(0, 19)}Z`

// A manifest that is absent is the first release; a manifest that exists but
// cannot be read stops the publication, because writing over it would drop the
// yanked list.
const readExisting = (path) => {
  if (path === undefined) return null
  const file = resolve(path)
  let raw
  try {
    raw = readFileSync(file, 'utf8')
  } catch (error) {
    if (error.code === 'ENOENT') return null
    throw error
  }
  try {
    return JSON.parse(raw)
  } catch (error) {
    throw new Error(`${file} is not a readable manifest: ${error.message}`)
  }
}

export const runCli = (arguments_) => {
  const version = readOption(arguments_, 'version')
  const existing = readExisting(readOption(arguments_, 'existing'))
  const manifest = buildManifest({
    version,
    repository: readOption(arguments_, 'repository'),
    releasedAt: readOption(arguments_, 'released-at') ?? utcSecond(new Date()),
    severity: readOption(arguments_, 'severity') ?? 'normal',
    existing
  })
  if (manifest === existing) {
    process.stderr.write(
      `Keeping ${existing.stable.version} as stable: ${version} is an older release.\n`
    )
  }
  process.stdout.write(`${JSON.stringify(manifest, null, 2)}\n`)
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    runCli(process.argv.slice(2))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
