#!/usr/bin/env node

// Writes a published release version into the two files that decide which
// version a NEW deployment installs: the Elestio manifest and the self-hosting
// example configuration.
//
// Existing deployments are untouched by design. They keep the version their
// operator pinned, and change it only by following docs/UPDATE_ELESTIO.md or
// docs/UPDATE_SELFHOST.md — a redeploy never moves a running installation to a
// different version.
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

export const parseReleaseVersion = (value) => {
  const version = String(value ?? '').trim().replace(/^v/, '')
  return RELEASE_VERSION.test(version) ? version : null
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

const TARGETS = [
  { path: 'elestio.yml', apply: applyElestioVersion },
  { path: join('deploy', 'selfhost.env.example'), apply: applyEnvExampleVersion },
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

  const rootOption = readOption('--root')
  const root = rootOption ? resolve(rootOption) : DEFAULT_ROOT

  const changed = []
  for (const target of TARGETS) {
    const file = join(root, target.path)
    const before = readFileSync(file, 'utf8')
    const after = target.apply(before, version)
    if (after !== before) {
      writeFileSync(file, after)
      changed.push(target.path)
    }
  }

  process.stdout.write(
    changed.length > 0
      ? `Set ${version} in ${changed.join(', ')}\n`
      : `Already at ${version}\n`
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
