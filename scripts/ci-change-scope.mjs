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
// The pin path list is the catalog reader plus the Umbrel package prefix the
// rollout guard already allows, so a sixth pin file cannot be forgotten here
// without also being forgotten there.

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

import { CATALOG_PATHS } from './read-release-version.mjs'

const PLAIN_RELEASE_TAG_REF = /^refs\/tags\/v\d+\.\d+\.\d+$/
const UNKNOWN_CHANGE = '__unknown__'

export const PIN_PATH_PREFIXES = ['deploy/umbrel/synaplan/']

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
  const result = classifyCiChangeScope({
    eventName: readOption(arguments_, '--event'),
    ref: readOption(arguments_, '--ref'),
    files: readFiles(arguments_),
    canPromote: readOption(arguments_, '--can-promote') === 'true',
  })
  return `mode=${result.mode}\nreason=${result.reason}\n`
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    process.stdout.write(runCli(process.argv.slice(2)))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
