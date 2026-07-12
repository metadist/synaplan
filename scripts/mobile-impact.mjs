#!/usr/bin/env node

import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { basename, dirname, isAbsolute, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url))
const DEFAULT_ROOT = resolve(SCRIPT_DIR, '..')
const DEFAULT_POLICY_PATH = resolve(DEFAULT_ROOT, '.github/mobile-impact-policy.json')

const escapeRegexCharacter = (character) =>
  /[\\^$+?.()|[\]{}]/.test(character) ? `\\${character}` : character

export const globToRegex = (glob) => {
  let expression = '^'

  for (let index = 0; index < glob.length; index += 1) {
    const character = glob[index]
    if (character !== '*') {
      expression += escapeRegexCharacter(character)
      continue
    }

    if (glob[index + 1] !== '*') {
      expression += '[^/]*'
      continue
    }

    index += 1
    if (glob[index + 1] === '/') {
      expression += '(?:.*/)?'
      index += 1
    } else {
      expression += '.*'
    }
  }

  return new RegExp(`${expression}$`)
}

const matchesAny = (path, patterns) =>
  patterns.some((pattern) => globToRegex(pattern).test(path))

const compareText = (left, right) => left < right ? -1 : left > right ? 1 : 0

const normalizedEntry = (entry) => {
  const path = entry.path.replaceAll('\\', '/').replace(/^\.\//, '')
  const status = (entry.status || 'M').slice(0, 1).toUpperCase()

  if (!path || isAbsolute(path) || path.split('/').includes('..')) {
    return { path, status, invalid: true }
  }

  return { path, status, ...(entry.previousPath ? { previousPath: entry.previousPath } : {}) }
}

export const loadPolicy = (policyPath = DEFAULT_POLICY_PATH) =>
  JSON.parse(readFileSync(policyPath, 'utf8'))

export const classifyFiles = (entries, policy = loadPolicy()) => {
  if (entries.length === 0) {
    return {
      classification: 'no-app-impact',
      reasons: [
        {
          classification: 'no-app-impact',
          reason: 'No changed files were supplied.',
          files: []
        }
      ],
      files: []
    }
  }

  const rank = new Map(policy.classifications.map((classification, index) => [classification, index]))
  const files = entries.map(normalizedEntry).map((entry) => {
    let classification
    let reason

    if (entry.invalid) {
      classification = policy.fallback.classification
      reason = policy.fallback.reason
    } else if (matchesAny(entry.path, policy.storeRequired.patterns)) {
      classification = 'store-required'
      reason = policy.storeRequired.reason
    } else if (matchesAny(entry.path, policy.otaCandidate.patterns)) {
      classification = 'ota-candidate'
      reason = policy.otaCandidate.reason
    } else if (
      matchesAny(entry.path, policy.backendOnly.patterns) &&
      !matchesAny(entry.path, policy.backendOnly.excludedPatterns)
    ) {
      classification = 'backend-only'
      reason = policy.backendOnly.reason
    } else if (matchesAny(entry.path, policy.noAppImpact.patterns)) {
      classification = 'no-app-impact'
      reason = policy.noAppImpact.reason
    } else {
      classification = policy.fallback.classification
      reason = policy.fallback.reason
    }

    return { ...entry, classification, reason }
  }).sort((left, right) => compareText(left.path, right.path) || compareText(left.status, right.status))

  const classification = files.reduce(
    (highest, file) => rank.get(file.classification) > rank.get(highest)
      ? file.classification
      : highest,
    policy.classifications[0]
  )

  const reasonsByKey = new Map()
  for (const file of files) {
    const key = `${file.classification}\0${file.reason}`
    const existing = reasonsByKey.get(key) ?? {
      classification: file.classification,
      reason: file.reason,
      files: []
    }
    existing.files.push(file.path)
    reasonsByKey.set(key, existing)
  }

  return {
    classification,
    reasons: [...reasonsByKey.values()].sort((left, right) =>
      rank.get(right.classification) - rank.get(left.classification) ||
      compareText(left.reason, right.reason)
    ),
    files: files.map(({ reason: _reason, invalid: _invalid, ...file }) => file)
  }
}

export const escalateWithLabels = (classification, labels, policy = loadPolicy()) => {
  const rank = new Map(policy.classifications.map((value, index) => [value, index]))
  const labelToClassification = new Map(
    policy.classifications.map((value) => [policy.labels[value], value])
  )

  return labels.reduce((highest, label) => {
    const candidate = labelToClassification.get(label)
    if (!candidate || rank.get(candidate) <= rank.get(highest)) {
      return highest
    }
    return candidate
  }, classification)
}

export const createManifest = ({
  repository,
  baseSha,
  headSha,
  tag = null,
  apiContractHash = null,
  createdAt,
  entries,
  labels = [],
  policy = loadPolicy()
}) => {
  const result = classifyFiles(entries, policy)
  const classification = escalateWithLabels(result.classification, labels, policy)
  const reasons = [...result.reasons]

  if (classification !== result.classification) {
    reasons.unshift({
      classification,
      reason: 'An existing mobile-impact label escalated the calculated classification.',
      files: []
    })
  }

  return {
    schemaVersion: policy.schemaVersion,
    repository,
    baseSha,
    headSha,
    tag,
    apiContractHash,
    classification,
    reasons,
    files: result.files,
    createdAt: new Date(createdAt).toISOString()
  }
}

export const serializeManifest = (manifest) => `${JSON.stringify(manifest, null, 2)}\n`

export const checksumManifest = (serializedManifest) =>
  createHash('sha256').update(serializedManifest).digest('hex')

export const parseExplicitFiles = (content) =>
  content.split(/\r?\n/).filter(Boolean).map((line) => {
    const fields = line.split('\t')
    if (/^[A-Z][0-9]*$/.test(fields[0]) && fields.length >= 2) {
      return fields[0].startsWith('R') || fields[0].startsWith('C')
        ? { status: fields[0], previousPath: fields[1], path: fields[2] }
        : { status: fields[0], path: fields[1] }
    }

    const colonMatch = line.match(/^([AMDR]):(.+)$/)
    return colonMatch
      ? { status: colonMatch[1], path: colonMatch[2] }
      : { status: 'M', path: line }
  })

const git = (root, args) =>
  execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim()

const parseGitDiff = (output) => {
  const fields = output.split('\0')
  const entries = []

  for (let index = 0; index < fields.length && fields[index]; index += 1) {
    const status = fields[index]
    const path = fields[index + 1]
    index += 1

    if (status.startsWith('R') || status.startsWith('C')) {
      const newPath = fields[index + 1]
      index += 1
      entries.push({ status, path: newPath, previousPath: path })
    } else {
      entries.push({ status, path })
    }
  }

  return entries
}

const parseArguments = (arguments_) => {
  const options = { root: DEFAULT_ROOT, labels: [], files: [] }
  const valueOptions = new Set([
    '--root', '--policy', '--base', '--head', '--output', '--repository',
    '--created-at', '--files', '--file', '--label', '--tag', '--api-contract'
  ])

  for (let index = 0; index < arguments_.length; index += 1) {
    const option = arguments_[index]
    if (option === '--help') {
      options.help = true
      continue
    }
    if (!valueOptions.has(option) || arguments_[index + 1] === undefined) {
      throw new Error(`Unknown or incomplete option: ${option}`)
    }

    const value = arguments_[index + 1]
    index += 1
    if (option === '--file') options.files.push(...parseExplicitFiles(value))
    else if (option === '--files') options.files_file = value
    else if (option === '--label') options.labels.push(value)
    else options[option.slice(2).replaceAll('-', '_')] = value
  }

  return options
}

const repositoryName = (root) => {
  try {
    const remote = git(root, ['remote', 'get-url', 'origin'])
    const match = remote.match(/[:/]([^/:]+\/[^/]+?)(?:\.git)?$/)
    return match ? match[1] : basename(root)
  } catch {
    return basename(root)
  }
}

const usage = `Usage:
  node scripts/mobile-impact.mjs --base <git-ref> [--head <git-ref>] [options]
  node scripts/mobile-impact.mjs --files <name-status-file> [--file A:path] [options]

Options:
  --root <path>          Repository root
  --policy <path>        JSON policy path
  --base <git-ref>       Base revision for git diff
  --head <git-ref>       Head revision (default: HEAD)
  --files <path>         Explicit newline or git name-status file list
  --file <status:path>   Explicit changed file; may be repeated
  --label <label>        Existing label; may be repeated
  --output <path>        Manifest path (default: mobile-release-manifest.json)
  --repository <name>    Repository identifier override
  --tag <tag>            Coordinated source release tag
  --api-contract <path>  OpenAPI document to hash into the manifest
  --created-at <date>    UTC timestamp override
`

export const runCli = (arguments_) => {
  const options = parseArguments(arguments_)
  if (options.help) {
    process.stdout.write(usage)
    return
  }

  const root = resolve(options.root)
  const policy = loadPolicy(options.policy ? resolve(options.policy) : resolve(root, '.github/mobile-impact-policy.json'))
  const headRef = options.head ?? 'HEAD'
  const headSha = git(root, ['rev-parse', headRef])
  const explicitEntries = options.files
  const hasExplicitInput = explicitEntries.length > 0 || Boolean(options.files_file)
  if (options.files_file) {
    explicitEntries.push(...parseExplicitFiles(readFileSync(resolve(options.files_file), 'utf8')))
  }

  if (!hasExplicitInput && !options.base) {
    throw new Error('--base is required when no explicit file list is supplied.')
  }

  const baseRef = options.base ?? headRef
  const baseSha = git(root, ['rev-parse', baseRef])
  const entries = hasExplicitInput
    ? explicitEntries
    : parseGitDiff(execFileSync(
      'git',
      ['diff', '--name-status', '-z', '--find-renames', baseRef, headRef],
      { cwd: root, encoding: 'utf8' }
    ))
  const createdAt = options.created_at ?? git(root, ['show', '-s', '--format=%cI', headSha])
  const apiContractHash = options.api_contract
    ? checksumManifest(readFileSync(resolve(root, options.api_contract)))
    : null
  const manifest = createManifest({
    repository: options.repository ?? repositoryName(root),
    baseSha,
    headSha,
    tag: options.tag ?? null,
    apiContractHash,
    createdAt,
    entries,
    labels: options.labels,
    policy
  })
  const serialized = serializeManifest(manifest)
  const outputPath = resolve(root, options.output ?? 'mobile-release-manifest.json')

  writeFileSync(outputPath, serialized)
  process.stdout.write(`sha256:${checksumManifest(serialized)}\n`)
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    runCli(process.argv.slice(2))
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
