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
// automated release always continues the plain release series so the
// resulting tag stays sortable and unambiguous for the mobile release chain.
//
// 'minor' resets patch to 0; 'major' resets minor and patch to 0 — the usual
// SemVer rule that a higher component starts the lower ones over. With no
// release tag yet, the series starts at v0.0.1 / v0.1.0 / v1.0.0.
export const nextReleaseTag = (tags, level = 'patch') => {
  const latest = latestReleaseTag(tags)
  const base = latest ? latest.version : { major: 0, minor: 0, patch: 0 }

  if (level === 'major') return `v${base.major + 1}.0.0`
  if (level === 'minor') return `v${base.major}.${base.minor + 1}.0`
  return `v${base.major}.${base.minor}.${base.patch + 1}`
}

// Kept as a thin wrapper: it is the one entry point resolve-app-version.mjs
// still relies on, and existing callers should not have to know the level
// exists.
export const nextPatchTag = (tags) => nextReleaseTag(tags, 'patch')

// A Conventional Commits type, optional scope, optional breaking marker (`!`),
// then the colon. Matched case-insensitively because a squash-merge title
// case follows GitHub's own casing, not the commit convention's.
const CONVENTIONAL_TYPE = /^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([^)]*\))?(!)?:\s*/i

// `feat/branch-name` squash-merge titles (`Feat/desktop client (#1669)`) carry
// the same "this adds a feature" signal as `feat:`, even though the slash form
// is not a full Conventional Commits type on its own — `fix/…`, `chore/…` and
// so on do NOT raise the version, only `feat/…` does.
const FEATURE_SLASH = /^feat\//i

// The Conventional Commits breaking-change footer, on its own line in the
// commit body.
const BREAKING_FOOTER = /^BREAKING[ -]CHANGE:/im

// Classifies a set of commit messages for an automatic version bump. Never
// returns 'major': a breaking-change marker (`feat!:`, `BREAKING CHANGE:`) is
// surfaced in `breaking` so the caller can warn about it, but raising the
// major version stays a deliberate human choice, never an automatic one.
export const classifyCommits = (messages) => {
  const features = []
  const breaking = []
  const unconventional = []

  for (const raw of messages ?? []) {
    const message = String(raw ?? '')
    const subject = message.split('\n', 1)[0].trim()
    if (!subject) continue

    const typeMatch = CONVENTIONAL_TYPE.exec(subject)
    const isFeatureSlash = FEATURE_SLASH.test(subject)
    const isBreaking = Boolean(typeMatch?.[3]) || BREAKING_FOOTER.test(message)

    if (isBreaking) {
      breaking.push(subject)
    }

    if (typeMatch) {
      if (typeMatch[1].toLowerCase() === 'feat') {
        features.push(subject)
      }
      continue
    }

    if (isFeatureSlash) {
      features.push(subject)
      continue
    }

    unconventional.push(subject)
  }

  return {
    level: features.length > 0 ? 'minor' : 'patch',
    features,
    breaking,
    unconventional
  }
}

const BUMP_LEVELS = ['auto', 'patch', 'minor', 'major']

// Combines the explicit `--bump` choice with the automatic classification.
// `auto` defers to `classifyCommits`; any explicit level overrides it outright
// — including 'major', which `classifyCommits` alone can never produce.
export const resolveBump = ({ requested, messages }) => {
  const normalized = String(requested ?? '').trim().toLowerCase() || 'auto'
  if (!BUMP_LEVELS.includes(normalized)) {
    throw new Error(`--bump must be one of ${BUMP_LEVELS.join(', ')}, got ${JSON.stringify(requested)}`)
  }

  const classification = classifyCommits(messages)

  if (normalized === 'auto') {
    return { ...classification, requested: normalized }
  }

  return { ...classification, level: normalized, requested: normalized }
}

const readTags = (root) =>
  execFileSync('git', ['tag', '--list', 'v*'], { cwd: root, encoding: 'utf8' })
    .split(/\r?\n/)
    .filter(Boolean)

// Commit messages (subject + body) in `base..head`, NUL-separated so a body
// containing a blank line is never mistaken for the boundary between two
// commits. Empty when either end is missing, so a caller that only wants a
// tag — the historical, argument-less invocation — never triggers `git log`.
const readCommitMessages = (root, base, head) => {
  if (!base || !head) return []
  const output = execFileSync(
    'git',
    ['log', '--format=%B%x00', `${base}..${head}`],
    { cwd: root, encoding: 'utf8' }
  )
  return output
    .split('\x00')
    .map((message) => message.trim())
    .filter(Boolean)
}

export const runCli = (arguments_) => {
  const readOption = (name) => {
    const index = arguments_.indexOf(name)
    return index >= 0 ? arguments_[index + 1] : undefined
  }

  const rootIndex = arguments_.indexOf('--root')
  const root = rootIndex >= 0 ? resolve(arguments_[rootIndex + 1]) : DEFAULT_ROOT

  const tags = readTags(root)
  const messages = readCommitMessages(root, readOption('--base'), readOption('--head'))
  const resolved = resolveBump({ requested: readOption('--bump'), messages })
  const tag = nextReleaseTag(tags, resolved.level)

  if (arguments_.includes('--json')) {
    process.stdout.write(`${JSON.stringify({ tag, ...resolved, commits: messages.length })}\n`)
    return
  }

  // The historical, argument-less shape: one tag, nothing else. Kept so a
  // caller that never asked for a bump level or JSON sees no change at all.
  process.stdout.write(`${tag}\n`)
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    runCli(process.argv.slice(2))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
