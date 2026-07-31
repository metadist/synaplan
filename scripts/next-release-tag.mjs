#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const DEFAULT_ROOT = resolve(SCRIPT_DIR, '..')

const RELEASE_TAG = /^v(\d+)\.(\d+)(?:\.(\d+))?$/

export const parseReleaseTag = (tag) => {
  const match = RELEASE_TAG.exec(String(tag).trim())
  if (!match) return null
  return {
    major: Number(match[1]),
    minor: Number(match[2]),
    patch: match[3] === undefined ? 0 : Number(match[3])
  }
}

const compareVersions = (left, right) =>
  left.major - right.major || left.minor - right.minor || left.patch - right.patch

export const latestReleaseTag = (tags) =>
  tags
    .map((tag) => ({ tag: String(tag).trim(), version: parseReleaseTag(tag) }))
    .filter((entry) => entry.version !== null)
    .sort((left, right) => compareVersions(left.version, right.version))
    .at(-1) ?? null

// Pre-release and build-metadata tags (v4.0.6-rc.1) are deliberately ignored: an
// automated release always continues the plain patch series so the resulting tag
// stays sortable and unambiguous for the mobile release chain.
export const nextPatchTag = (tags) => {
  const latest = latestReleaseTag(tags)
  if (!latest) return 'v0.0.1'
  const { major, minor, patch } = latest.version
  return `v${major}.${minor}.${patch + 1}`
}

const readTags = (root) =>
  execFileSync('git', ['tag', '--list', 'v*'], { cwd: root, encoding: 'utf8' })
    .split(/\r?\n/)
    .filter(Boolean)

export const runCli = (arguments_) => {
  const rootIndex = arguments_.indexOf('--root')
  const root = rootIndex >= 0 ? resolve(arguments_[rootIndex + 1]) : DEFAULT_ROOT
  process.stdout.write(`${nextPatchTag(readTags(root))}\n`)
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    runCli(process.argv.slice(2))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
