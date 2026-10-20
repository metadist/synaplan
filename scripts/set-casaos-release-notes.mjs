#!/usr/bin/env node

// Fills the `x-casaos.release_notes.en_US` and `x-casaos.update_at` fields of
// the CasaOS / ZimaOS AppStore compose file with the text and date of a GitHub
// release, for the store package.

import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

import { formatUmbrelReleaseNotes } from './set-umbrel-release-notes.mjs'

export const applyCasaosReleaseNotes = (text, notes, updateAt) => {
  const lines = text.split('\n')
  let inReleaseNotes = false
  let notesReplaced = 0
  let dateReplaced = 0

  const result = lines.map((line) => {
    if (updateAt && /^ {2}update_at:\s*".*"\s*$/.test(line)) {
      dateReplaced += 1
      return `  update_at: ${JSON.stringify(updateAt)}`
    }

    if (/^ {2}release_notes:\s*$/.test(line)) {
      inReleaseNotes = true
      return line
    }

    if (inReleaseNotes && /^ {2}[a-z_]+:/.test(line)) {
      inReleaseNotes = false
    }

    if (inReleaseNotes && /^ {4}en_US:\s*.*$/.test(line)) {
      const value = line.replace(/^ {4}en_US:\s*/, '').trim()
      if (value.startsWith('|') || value.startsWith('>')) {
        throw new Error(
          'docker-compose.yml: `release_notes.en_US` is a block scalar; this script only rewrites an inline value'
        )
      }
      notesReplaced += 1
      return `    en_US: ${JSON.stringify(notes)}`
    }

    return line
  })

  if (notesReplaced !== 1) {
    throw new Error(
      `docker-compose.yml: expected exactly one release_notes.en_US field, found ${notesReplaced}`
    )
  }

  if (updateAt && dateReplaced !== 1) {
    throw new Error(
      `docker-compose.yml: expected exactly one update_at field, found ${dateReplaced}`
    )
  }

  return result.join('\n')
}

const readOption = (arguments_, name) => {
  const index = arguments_.indexOf(name)
  return index === -1 ? null : (arguments_[index + 1] ?? null)
}

export const runCli = (arguments_ = []) => {
  const manifest = readOption(arguments_, '--manifest')
  if (manifest === null) {
    throw new Error('--manifest must be the path of the CasaOS docker-compose.yml to rewrite')
  }

  const notes = formatUmbrelReleaseNotes(
    readOption(arguments_, '--body') ?? '',
    readOption(arguments_, '--url') ?? ''
  )
  const updateAt = readOption(arguments_, '--date')

  const path = resolve(manifest)
  writeFileSync(path, applyCasaosReleaseNotes(readFileSync(path, 'utf8'), notes, updateAt))

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
