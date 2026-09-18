/**
 * Summarise Playwright JSON reports for the CI flake-notify job.
 *
 * Distinguishes retry-pass flakes from hard failures and from missing reports
 * (almost always CI infra: apt 403, registry timeout, stack startup, no artifact).
 */
import { readdirSync, readFileSync, statSync, writeFileSync, appendFileSync } from 'node:fs'
import { join } from 'node:path'

function walkResultsJson(dir, acc = []) {
  let entries
  try {
    entries = readdirSync(dir)
  } catch {
    return acc
  }
  for (const name of entries) {
    const path = join(dir, name)
    let st
    try {
      st = statSync(path)
    } catch {
      continue
    }
    if (st.isDirectory()) {
      walkResultsJson(path, acc)
    } else if (name === 'results.json') {
      acc.push(path)
    }
  }
  return acc
}

function stripAnsi(text) {
  return String(text).replace(/\u001b\[[0-9;]*m/g, '')
}

function firstError(test) {
  const msg = test.results?.[0]?.error?.message ?? ''
  return stripAnsi(msg).split('\n')[0].slice(0, 180)
}

function collectFromSuite(suite, fileHint, out) {
  const file = suite.file || fileHint || suite.title || '?'
  for (const spec of suite.specs ?? []) {
    for (const test of spec.tests ?? []) {
      if (test.status !== 'flaky' && test.status !== 'unexpected') continue
      out.push({
        status: test.status,
        project: test.projectName ?? '?',
        file,
        title: spec.title ?? '?',
        error: firstError(test),
        attempts: (test.results ?? []).map((r) => r.status),
      })
    }
  }
  for (const child of suite.suites ?? []) {
    collectFromSuite(child, file, out)
  }
}

export function parseReport(raw) {
  const out = []
  if (!raw || typeof raw !== 'object') return out
  if (Array.isArray(raw.suites)) {
    for (const suite of raw.suites) {
      collectFromSuite(suite, raw.file, out)
    }
    return out
  }
  collectFromSuite(raw, raw.file, out)
  return out
}

function keyOf(row) {
  return `${row.project} · ${row.file} · ${row.title}`
}

export function summariseReports(reportPaths, { e2eResult = 'success' } = {}) {
  const flaky = []
  const unexpected = []
  const seenFlaky = new Set()
  const seenUnexpected = new Set()

  for (const path of reportPaths) {
    let parsed
    try {
      parsed = JSON.parse(readFileSync(path, 'utf8'))
    } catch {
      continue
    }
    for (const row of parseReport(parsed)) {
      const key = keyOf(row)
      if (row.status === 'flaky') {
        if (seenFlaky.has(key)) continue
        seenFlaky.add(key)
        flaky.push(row)
      } else {
        if (seenUnexpected.has(key)) continue
        seenUnexpected.add(key)
        unexpected.push(row)
      }
    }
  }

  flaky.sort((a, b) => keyOf(a).localeCompare(keyOf(b)))
  unexpected.sort((a, b) => keyOf(a).localeCompare(keyOf(b)))

  const infra = reportPaths.length === 0 && e2eResult === 'failure'
  let kind = 'none'
  if (infra) kind = 'infra'
  else if (flaky.length > 0 && unexpected.length > 0) kind = 'mixed'
  else if (flaky.length > 0) kind = 'flaky'
  else if (unexpected.length > 0) kind = 'unexpected'

  return {
    kind,
    e2eResult,
    reportCount: reportPaths.length,
    flaky,
    unexpected,
  }
}

function bullet(row) {
  const error = row.error ? ` — ${row.error}` : ''
  return `• ${keyOf(row)}${error}`
}

export function toMarkdown(summary) {
  const lines = ['## E2E flake report', '']
  if (summary.kind === 'infra') {
    lines.push(
      'No Playwright `results.json` after an E2E job failure. That is CI infrastructure',
      '(browser install, registry, stack startup, missing artifact), not a test flake.',
      ''
    )
    return `${lines.join('\n')}\n`
  }
  if (summary.kind === 'none') {
    lines.push('No flaky or unexpected tests in the Playwright reports.', '')
    return `${lines.join('\n')}\n`
  }
  if (summary.flaky.length > 0) {
    lines.push(`### Flaky (retry passed): ${summary.flaky.length}`, '')
    for (const row of summary.flaky) lines.push(bullet(row))
    lines.push('')
  }
  if (summary.unexpected.length > 0) {
    lines.push(`### Unexpected (both attempts failed): ${summary.unexpected.length}`, '')
    for (const row of summary.unexpected) lines.push(bullet(row))
    lines.push('')
  }
  return `${lines.join('\n')}\n`
}

export function discordPayload(summary, { branch, runUrl, timestamp }) {
  if (summary.kind === 'none') return null

  const flakeLines = summary.flaky.map((row) => bullet(row))
  const hardLines = summary.unexpected.map((row) => bullet(row))
  const shown = (lines) => {
    const head = lines.slice(0, 15)
    const extra = lines.length > 15 ? `\n… and ${lines.length - 15} more` : ''
    return `${head.join('\n')}${extra}`.slice(0, 1000) || '—'
  }

  const titles = {
    infra: 'E2E infra: no Playwright reports',
    flaky: `Flaky E2E: ${summary.flaky.length} test(s)`,
    unexpected: `Failed E2E: ${summary.unexpected.length} test(s)`,
    mixed: `E2E: ${summary.flaky.length} flaky, ${summary.unexpected.length} failed`,
  }
  const colors = {
    infra: 10197915,
    flaky: 16776960,
    unexpected: 15158332,
    mixed: 16753920,
  }

  const fields = [
    { name: 'Branch', value: `\`${branch}\``, inline: true },
    { name: 'Kind', value: summary.kind, inline: true },
  ]
  if (summary.kind === 'infra') {
    fields.push({
      name: 'What this is',
      value:
        'E2E jobs failed before Playwright wrote `results.json` (install, registry, stack startup, or missing artifact). Not a test flake.',
      inline: false,
    })
  } else {
    if (summary.flaky.length > 0) {
      fields.push({ name: 'Flaky tests', value: shown(flakeLines), inline: false })
    }
    if (summary.unexpected.length > 0) {
      fields.push({ name: 'Hard failures', value: shown(hardLines), inline: false })
    }
  }
  fields.push({ name: 'CI run', value: `[Open CI run](${runUrl})`, inline: false })

  return {
    embeds: [
      {
        title: titles[summary.kind],
        color: colors[summary.kind],
        fields,
        footer: { text: 'Synaplan CI' },
        timestamp,
      },
    ],
  }
}

function parseArgs(argv) {
  const out = { dir: 'artifacts', e2eResult: 'success', json: '', markdown: '', payload: '' }
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i]
    const next = argv[i + 1]
    if (a === '--dir' && next) {
      out.dir = next
      i++
    } else if (a === '--e2e-result' && next) {
      out.e2eResult = next
      i++
    } else if (a === '--json' && next) {
      out.json = next
      i++
    } else if (a === '--markdown' && next) {
      out.markdown = next
      i++
    } else if (a === '--payload' && next) {
      out.payload = next
      i++
    }
  }
  return out
}

export function runCli(argv, env = process.env) {
  const args = parseArgs(argv)
  const reports = walkResultsJson(args.dir)
  const summary = summariseReports(reports, { e2eResult: args.e2eResult })
  if (args.json) {
    writeFileSync(args.json, `${JSON.stringify(summary, null, 2)}\n`)
  }
  const md = toMarkdown(summary)
  if (args.markdown) {
    appendFileSync(args.markdown, md)
  }
  const payload = discordPayload(summary, {
    branch: env.BRANCH ?? '',
    runUrl: env.RUN_URL ?? '',
    timestamp: env.TS ?? new Date().toISOString(),
  })
  if (args.payload) {
    writeFileSync(args.payload, payload ? `${JSON.stringify(payload)}\n` : '')
  }
  return { summary, payload }
}

const isDirect = process.argv[1] && process.argv[1].endsWith('e2e-flake-report.mjs')
if (isDirect) {
  runCli(process.argv.slice(2))
}
