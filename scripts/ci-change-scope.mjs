#!/usr/bin/env node

// Decides how much of CI a run actually needs.
//
// Three modes, fail-closed to `heavy` whenever the diff is empty, unreadable
// or contains anything outside the deployment catalog:
//
//   thin     — only catalog pins changed. Lint the templates, skip the image
//              and E2E path. Used for the bot's "install X in new deployments"
//              pull request and the merge that follows it.
//   promote  — a plain release tag whose commit already published
//              `ci-<sha>-amd64` / `ci-<sha>-arm64` from main. Retag those
//              images; do not rebuild or retest the same tree.
//   heavy    — everything else. Full tests, and on main/tags the publish path.
//
// Sidecar jobs (compute, transcriber, voice) are not part of "full tests".
// Each runs only when the diff touches that sidecar, or when the diff is
// unknown, or when this file or ci.yml changed. A thin or promote run skips
// them: those trees were already tested. The Go suite itself is not reduced.
// A sidecar this diff asked for must succeed. A skip is accepted only when
// it was not asked for. The socket guard stays in the lint job, because it
// scans every file.
//
// The pin path list is the catalog reader plus the Umbrel package prefix the
// rollout guard already allows, so a sixth pin file cannot be forgotten here
// without also being forgotten there.

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

import { CATALOG_PATHS } from './read-release-version.mjs'

const PLAIN_RELEASE_TAG_REF = /^refs\/tags\/v\d+\.\d+\.\d+$/
const UNKNOWN_CHANGE = '__unknown__'

export const PIN_PATH_PREFIXES = ['deploy/umbrel/synaplan/', 'deploy/casaos/Synaplan/']

export const isPinPath = (path) => {
  const normalized = String(path ?? '').replace(/^\.\//, '')
  if (normalized === '' || normalized === UNKNOWN_CHANGE) {
    return false
  }
  if (CATALOG_PATHS.includes(normalized)) {
    return true
  }
  return PIN_PATH_PREFIXES.some((prefix) => normalized.startsWith(prefix))
}

/**
 * Paths that exercise one sidecar's own CI job. A prefix matches a directory.
 * The socket guard is not here: it scans every file, so the lint job
 * runs it on every pull request.
 */
export const SIDECAR_PATHS = {
  compute: ['sidecars/synaplan-compute/'],
  transcriber: ['sidecars/synaplan-transcriber/'],
  voice: ['sidecars/synaplan-voice/'],
}

/** Changing the rule itself retests every sidecar. */
const SIDECAR_RULE_PATHS = ['.github/workflows/ci.yml', 'scripts/ci-change-scope.mjs']

const normalizeFiles = (files) =>
  [...new Set((files ?? []).map((file) => String(file).trim()).filter(Boolean))]

const matchesSidecar = (path, prefixes) =>
  prefixes.some((prefix) => (prefix.endsWith('/') ? path.startsWith(prefix) : path === prefix))

/**
 * Which sidecar jobs this diff needs. Fail closed: an empty or unreadable
 * diff, a release tag that must rebuild, or a change to the CI rule tests
 * every sidecar. Thin and promote skip them.
 *
 * @returns {{ compute: boolean, transcriber: boolean, voice: boolean }}
 */
export const sidecarsForChange = ({ eventName, ref, files = [], mode }) => {
  const none = { compute: false, transcriber: false, voice: false }
  const all = { compute: true, transcriber: true, voice: true }
  if (mode === 'thin' || mode === 'promote') {
    return none
  }
  if (eventName === 'push' && String(ref ?? '').startsWith('refs/tags/')) {
    return all
  }

  const paths = normalizeFiles(files)
  if (paths.length === 0 || paths.includes(UNKNOWN_CHANGE)) {
    return all
  }
  if (paths.some((path) => SIDECAR_RULE_PATHS.includes(path))) {
    return all
  }

  return {
    compute: paths.some((path) => matchesSidecar(path, SIDECAR_PATHS.compute)),
    transcriber: paths.some((path) => matchesSidecar(path, SIDECAR_PATHS.transcriber)),
    voice: paths.some((path) => matchesSidecar(path, SIDECAR_PATHS.voice)),
  }
}

/**
 * A sidecar this diff asked for must succeed. A skipped job is acceptable
 * only when it was not asked for. Cancelled and failed never are.
 */
export const sidecarResultOk = (needed, result) => {
  const required = needed === true || needed === 'true'
  if (required) {
    return result === 'success'
  }

  return result === 'success' || result === 'skipped'
}

export const isPinOnlyChange = (files) => {
  const paths = [...new Set((files ?? []).map((file) => String(file).trim()).filter(Boolean))]
  return paths.length > 0 && paths.every((path) => isPinPath(path))
}

export const classifyCiChangeScope = ({ eventName, ref, files = [], canPromote = false }) => {
  const refName = String(ref ?? '')
  if (eventName === 'push' && PLAIN_RELEASE_TAG_REF.test(refName)) {
    if (canPromote) {
      return {
        mode: 'promote',
        reason: 'plain release tag with a green main CI run and published ci-<sha> images',
      }
    }
    return {
      mode: 'heavy',
      reason: 'plain release tag without a reusable main image; building and testing here',
    }
  }

  if (eventName === 'push' && refName.startsWith('refs/tags/')) {
    return {
      mode: 'heavy',
      reason: 'non-plain tag; full publish path',
    }
  }

  if (isPinOnlyChange(files)) {
    return {
      mode: 'thin',
      reason: 'only deployment catalog pins changed',
    }
  }

  return {
    mode: 'heavy',
    reason: 'application or unlisted paths changed',
  }
}

const readOption = (arguments_, name) => {
  const index = arguments_.indexOf(name)
  return index >= 0 ? arguments_[index + 1] : undefined
}

const readFiles = (arguments_) => {
  const fromPath = readOption(arguments_, '--files-from')
  if (fromPath === undefined) {
    return []
  }
  const source = fromPath === '-' ? 0 : fromPath
  return readFileSync(source, 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
}

export const runCli = (arguments_ = []) => {
  const eventName = readOption(arguments_, '--event')
  const ref = readOption(arguments_, '--ref')
  const files = readFiles(arguments_)
  const result = classifyCiChangeScope({
    eventName,
    ref,
    files,
    canPromote: readOption(arguments_, '--can-promote') === 'true',
  })
  const sidecars = sidecarsForChange({ eventName, ref, files, mode: result.mode })
  return [
    `mode=${result.mode}`,
    `reason=${result.reason}`,
    `compute=${sidecars.compute}`,
    `transcriber=${sidecars.transcriber}`,
    `voice=${sidecars.voice}`,
    '',
  ].join('\n')
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    process.stdout.write(runCli(process.argv.slice(2)))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
