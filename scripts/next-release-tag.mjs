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

// The commit types that carry a version signal. Only the START of the subject
// is inspected: whatever separates the type from the rest — `feat: x`,
// `feat(chat): x`, `feat!: x`, `Feat/desktop client (#1669)`, even `Feature
// flag for …` — is not looked at, and the casing is not either, because a
// squash-merge title follows GitHub's casing rather than the convention's.
//
// Deliberately lenient: a commit that meant to be a feature should not ship as
// a patch release over a missing colon. The cost is that a subject merely
// STARTING with a type word is read as that type, so `feature-flag cleanup`
// raises the minor version. Naming a commit after what it does keeps that
// honest.
const COMMIT_TYPES = [
  'feat',
  'fix',
  'docs',
  'style',
  'refactor',
  'perf',
  'test',
  'build',
  'ci',
  'chore',
  'revert'
]

const TYPE_PREFIX = new RegExp(`^(${COMMIT_TYPES.join('|')})`, 'i')

// The Conventional Commits breaking marker: a `!` directly before the colon,
// with or without a scope (`feat!:`, `feat(api)!:`). Only the full convention
// carries it, so unlike the type itself this is not matched loosely.
const BREAKING_SUBJECT = /^[a-z]+(\([^)]*\))?!:/i

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

    if (BREAKING_SUBJECT.test(subject) || BREAKING_FOOTER.test(message)) {
      breaking.push(subject)
    }

    const typeMatch = TYPE_PREFIX.exec(subject)
    if (!typeMatch) {
      unconventional.push(subject)
      continue
    }

    if (typeMatch[1].toLowerCase() === 'feat') {
      features.push(subject)
    }
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
//
// The default 1 MiB buffer is far too small for the range release-tag.yml
// falls back to when no release tag exists yet: the root commit, and therefore
// the whole history — already a few megabytes here at roughly a kilobyte of
// message per commit.
const MESSAGE_BUFFER_BYTES = 64 * 1024 * 1024

const readCommitMessages = (root, base, head) => {
  if (!base || !head) return []
  const output = execFileSync(
    'git',
    ['log', '--format=%B%x00', `${base}..${head}`],
    { cwd: root, encoding: 'utf8', maxBuffer: MESSAGE_BUFFER_BYTES }
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
