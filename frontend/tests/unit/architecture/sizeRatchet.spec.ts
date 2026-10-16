// @vitest-environment node
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterAll, describe, expect, it } from 'vitest'
import {
  MAX_FILE_LINES,
  MAX_FUNCTION_LINES,
  scanOffenders,
  type SizeOffenders,
} from './sourceMetrics'

/**
 * The frontend size ratchet, twin of the backend's `SizeRatchetTest`: a file
 * under `src/` has at most 800 lines and a function at most 100. Every
 * offender that existed when the gate came in is frozen in
 * `sizeRatchetBaseline.json`; a listed entry may shrink or disappear, never
 * grow, and a new offender fails.
 *
 * Shrunk or split an offender? Regenerate the ledger in the same change and
 * review the diff — removals and smaller numbers only:
 * `docker compose exec -T -e UPDATE_SIZE_RATCHET=1 frontend npx vitest run tests/unit/architecture/sizeRatchet.spec.ts`
 */

type Kind = 'fileLines' | 'functionLines'
type Ledger = Pick<SizeOffenders, Kind>

const KINDS: Kind[] = ['fileLines', 'functionLines']
const FRONTEND_ROOT = fileURLToPath(new URL('../../..', import.meta.url))
const LEDGER = fileURLToPath(new URL('./sizeRatchetBaseline.json', import.meta.url))
/** Guard against a vacuous pass if the scan breaks (787 files on 2026-10-16). */
const MIN_EXPECTED_FILES = 500

const measured = scanOffenders(FRONTEND_ROOT)
if (process.env.UPDATE_SIZE_RATCHET === '1') {
  writeLedger(measured)
}
const ledger = readLedger()

describe('frontend size ratchet', () => {
  it('scans the whole source tree', () => {
    expect(measured.scanned).toBeGreaterThan(MIN_EXPECTED_FILES)
  })

  it('lists every offender on the ledger', () => {
    const unlisted = entries(measured).filter(([kind, key]) => !(key in ledger[kind]))

    expect(
      unlisted.map(([kind, key, value]) => `${kind}  ${key} = ${value}`),
      `New size offender(s); the limits are ${MAX_FILE_LINES} lines per file and ${MAX_FUNCTION_LINES} per function. Split it or move the new code out; the ledger only freezes what existed before the gate.`
    ).toEqual([])
  })

  it('lets a listed offender only shrink', () => {
    const grown = entries(ledger).filter(
      ([kind, key, frozen]) => (measured[kind][key] ?? 0) > frozen
    )

    expect(
      grown.map(([kind, key, frozen]) => `${kind}  ${key}: ${frozen} → ${measured[kind][key]}`),
      'A frozen offender grew. Put the new code into a module of its own instead of the one that is already too big.'
    ).toEqual([])
  })

  it('keeps no stale entry on the ledger', () => {
    const stale = entries(ledger).filter(([kind, key]) => !(key in measured[kind]))

    expect(
      stale.map(([kind, key, frozen]) => `${kind}  ${key} (frozen at ${frozen})`),
      'These entries no longer offend. Regenerate the ledger with UPDATE_SIZE_RATCHET=1 and review the diff.'
    ).toEqual([])
  })

  describe('on a scratch tree', () => {
    const root = mkdtempSync(join(tmpdir(), 'size-ratchet-'))
    afterAll(() => rmSync(root, { recursive: true, force: true }))

    it('flags a file of 801 lines and a function of 101 lines, not one of 800 and 100', () => {
      write(root, 'src/long.ts', lines(MAX_FILE_LINES + 1))
      write(root, 'src/fits.ts', lines(MAX_FILE_LINES))
      write(
        root,
        'src/functions.ts',
        [fn('tooLong', MAX_FUNCTION_LINES + 1), fn('fits', MAX_FUNCTION_LINES)].join('\n')
      )

      const offenders = scanOffenders(root)

      expect(offenders.fileLines).toEqual({ 'src/long.ts': MAX_FILE_LINES + 1 })
      expect(offenders.functionLines).toEqual({
        'src/functions.ts::tooLong': MAX_FUNCTION_LINES + 1,
      })
    })
  })
})

function entries(source: Ledger): [Kind, string, number][] {
  return KINDS.flatMap((kind) =>
    Object.entries(source[kind]).map(([key, value]): [Kind, string, number] => [kind, key, value])
  )
}

function readLedgerFile(): (Partial<Ledger> & { recorded?: string }) | null {
  return existsSync(LEDGER) ? JSON.parse(readFileSync(LEDGER, 'utf8')) : null
}

function readLedger(): Ledger {
  const file = readLedgerFile()
  if (file === null) {
    throw new Error(`Missing ${LEDGER}. Record it once with UPDATE_SIZE_RATCHET=1.`)
  }
  return { fileLines: file.fileLines ?? {}, functionLines: file.functionLines ?? {} }
}

/**
 * Shrink-only: the first recording freezes every offender; after that a
 * regeneration drops fixed entries and lowers shrunk ones, never adds or
 * raises one.
 */
function writeLedger(current: SizeOffenders): void {
  const previous = readLedgerFile()
  const today = new Date().toISOString().slice(0, 10)
  const freeze = (kind: Kind): Record<string, number> => {
    const base = previous?.[kind] ?? current[kind]
    const keys = Object.keys(base).filter((key) => key in current[kind])
    return Object.fromEntries(
      keys.sort().map((key) => [key, Math.min(current[kind][key], base[key])])
    )
  }
  const fileLines = freeze('fileLines')
  const functionLines = freeze('functionLines')
  const payload = {
    _comment:
      'Frontend size ratchet ledger (tests/unit/architecture/sizeRatchet.spec.ts). Frozen offenders only; entries may shrink or disappear, never grow or be added. Regenerate with UPDATE_SIZE_RATCHET=1 and review the diff.',
    recorded: previous?.recorded ?? today,
    updated: today,
    limits: { fileLines: MAX_FILE_LINES, functionLines: MAX_FUNCTION_LINES },
    counts: {
      fileLines: Object.keys(fileLines).length,
      functionLines: Object.keys(functionLines).length,
    },
    fileLines,
    functionLines,
  }
  writeFileSync(LEDGER, `${JSON.stringify(payload, null, 2)}\n`)
}

function write(root: string, file: string, contents: string): void {
  const path = join(root, file)
  mkdirSync(dirname(path), { recursive: true })
  writeFileSync(path, contents)
}

function lines(count: number): string {
  return Array.from({ length: count }, (_, index) => `export const line${index} = ${index}`).join(
    '\n'
  )
}

function fn(name: string, length: number): string {
  const body = Array.from({ length: length - 2 }, (_, index) => `  const value${index} = ${index}`)
  return [`export function ${name}() {`, ...body, '}'].join('\n')
}
