#!/usr/bin/env node

// Fills the `releaseNotes` field of the Umbrel App Store manifest with the text
// of a GitHub release, for the store package only.
//
// Deliberately not applied to the copy in this repository. Umbrel's linter
// requires `releaseNotes` to be EMPTY for a new app submission and only expects
// it to be filled once the app is already in the store, so the committed
// manifest keeps the empty string and umbrel-store-sync.yml fills it in the fork
// when — and only when — the store already carries the app.
//
// The value is written as a JSON-encoded scalar. YAML's double-quoted style
// shares JSON's escaping rules, so release notes containing colons, quotes, `#`
// or newlines cannot break the manifest.

import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

// Long enough for a real changelog, short enough for an update dialog on a
// phone. Umbrel renders this to the user, not to a maintainer.
const DEFAULT_LIMIT = 1500

const RELEASE_NOTES = /^releaseNotes:[ \t]*(.*)$/

export const formatUmbrelReleaseNotes = (body, url, limit = DEFAULT_LIMIT) => {
  const suffix = url ? `\n\nFull release notes: ${url}` : ''
  const text = String(body ?? '')
    .replace(/\r\n?/g, '\n')
    .trim()

  if (text === '') {
    return suffix.trim()
  }

  const room = limit - suffix.length
  if (text.length <= room) {
    return `${text}${suffix}`
  }

  // Cut on a boundary the reader can see, so the notes never end mid-word.
  const head = text.slice(0, Math.max(room - 1, 0))
  const boundary = Math.max(head.lastIndexOf('\n'), head.lastIndexOf(' '))
  const trimmed = (boundary > 0 ? head.slice(0, boundary) : head).trimEnd()

  return `${trimmed}…${suffix}`
}

export const applyUmbrelReleaseNotes = (text, notes) => {
  let replaced = 0

  const result = text
    .split('\n')
    .map((line) => {
      const match = RELEASE_NOTES.exec(line)
      if (!match) return line

      // A block scalar carries its value on the following lines, which a
      // line-for-line replacement would leave behind as stray YAML.
      const existing = match[1].trim()
      if (existing.startsWith('|') || existing.startsWith('>')) {
        throw new Error(
          'umbrel-app.yml: `releaseNotes` is a block scalar; this script only rewrites an inline value'
        )
      }

      replaced += 1
      return `releaseNotes: ${JSON.stringify(notes)}`
    })
    .join('\n')

  if (replaced !== 1) {
    throw new Error(
      `umbrel-app.yml: expected exactly one top-level releaseNotes field, found ${replaced}`
    )
  }

  return result
}

const readOption = (arguments_, name) => {
  const index = arguments_.indexOf(name)
  return index === -1 ? null : (arguments_[index + 1] ?? null)
}

export const runCli = (arguments_ = []) => {
  const manifest = readOption(arguments_, '--manifest')
  if (manifest === null) {
    throw new Error('--manifest must be the path of the umbrel-app.yml to rewrite')
  }

  const notes = formatUmbrelReleaseNotes(
    readOption(arguments_, '--body') ?? '',
    readOption(arguments_, '--url') ?? ''
  )

  const path = resolve(manifest)
  writeFileSync(path, applyUmbrelReleaseNotes(readFileSync(path, 'utf8'), notes))

  return notes
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  try {
    process.stdout.write(`${runCli(process.argv.slice(2))}\n`)
  } catch (error) {
    process.stderr.write(`${error.message}\n`)
    process.exitCode = 1
  }
}
