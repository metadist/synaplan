import { describe, expect, it } from 'vitest'
import de from '@/i18n/de.json'
import en from '@/i18n/en.json'
import es from '@/i18n/es.json'
import fr from '@/i18n/fr.json'
import tr from '@/i18n/tr.json'
import baseline from './localeParityBaseline.json'

/**
 * Locale-parity gate.
 *
 * Full-file key parity IS gated, against a frozen baseline of the drift that
 * already existed when the gate landed (es/tr were maintained as a pair and
 * skipped in roughly half the feature PRs; de lags on the newest namespaces).
 * `fallbackLocale: 'en'` renders a missing key as English, so drift is
 * invisible at runtime and needs a test to stay honest.
 *
 * The baseline is compared EXACTLY, not as an upper bound: a new gap fails,
 * and closing a gap also fails until the key is removed from the baseline.
 * The ledger can therefore only ever shrink, and it shrinks deliberately.
 *
 * Opt-out for genuine loanwords that stay identical across locales.
 */
const GATED_NAMESPACES = ['config.savedTasks', 'config.connections'] as const

const LOANWORD_OPT_OUT = new Set<string>([
  // Add dotted keys here when a DE/ES/TR string is intentionally identical
  // to English (e.g. "API", "IMAP", "WebDAV").
  // Microsoft spells the product the same way in all four markets.
  'config.connections.providers.m365.name',
  'config.connections.types.m365',
  // Product names: "Nextcloud / ownCloud" is the same brand pair everywhere.
  'config.connections.providers.dav.kind.nextcloud',
  // "Dropbox" is the same brand name in all four markets.
  'config.connections.providers.dropbox.name',
  'config.connections.types.dropbox',
])

const BANNED_JARGON = [
  /\bDAG\b/i,
  /\bcron\b/i,
  /\bnode\b/i,
  /\bpayload\b/i,
  /\bworkflow\b/i,
  /\borchestration\b/i,
  /\bn8n\b/i,
  /\bcapability\b/i,
  /\brunner\b/i,
  /\bexecutor\b/i,
  /\btopic id\b/i,
]

const LOCALES = { en, de, es, fr, tr } as const
type Locale = keyof typeof LOCALES

function flatten(obj: unknown, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {}
  if (obj !== null && typeof obj === 'object' && !Array.isArray(obj)) {
    for (const [key, value] of Object.entries(obj as Record<string, unknown>)) {
      const path = prefix ? `${prefix}.${key}` : key
      Object.assign(out, flatten(value, path))
    }
    return out
  }
  out[prefix] = String(obj ?? '')
  return out
}

function placeholders(value: string): string[] {
  return [...value.matchAll(/\{([a-zA-Z0-9_]+)\}/g)].map((m) => m[1]).sort()
}

/**
 * Distinct placeholder names, ignoring how often each one occurs.
 *
 * The plural-branch count legitimately differs between languages: Turkish does
 * not inflect a noun after a numeral ("3 dosya", never "3 dosyalar"), so one
 * branch is correct there where English needs two. Comparing multisets would
 * flag that correct translation, so only the NAMES have to match.
 */
function placeholderSet(value: string): string[] {
  return [...new Set(placeholders(value))].sort()
}

function keysInNamespaces(flat: Record<string, string>): string[] {
  return Object.keys(flat)
    .filter((key) => GATED_NAMESPACES.some((ns) => key === ns || key.startsWith(`${ns}.`)))
    .sort()
}

describe('i18n locale parity (Saved Tasks / Connections)', () => {
  const flats = Object.fromEntries(
    (Object.keys(LOCALES) as Locale[]).map((locale) => [locale, flatten(LOCALES[locale])])
  ) as Record<Locale, Record<string, string>>

  const unionKeys = [
    ...new Set(
      (Object.keys(LOCALES) as Locale[]).flatMap((locale) => keysInNamespaces(flats[locale]))
    ),
  ].sort()

  it('has the same keys in en/de/es/fr/tr inside gated namespaces', () => {
    for (const locale of Object.keys(LOCALES) as Locale[]) {
      const missing = unionKeys.filter((key) => !(key in flats[locale]))
      expect(missing, `${locale}.json missing gated keys`).toEqual([])
    }
  })

  it('uses the same interpolation placeholders across locales', () => {
    const mismatches: string[] = []
    for (const key of unionKeys) {
      const expected = placeholderSet(flats.en[key] ?? '')
      for (const locale of ['de', 'es', 'fr', 'tr'] as const) {
        const actual = placeholderSet(flats[locale][key] ?? '')
        if (expected.join(',') !== actual.join(',')) {
          mismatches.push(`${key}: en={${expected.join(',')}} ${locale}={${actual.join(',')}}`)
        }
      }
    }
    expect(mismatches).toEqual([])
  })

  it('does not leave DE/ES/FR/TR identical to English (except loanword opt-out)', () => {
    const identical: string[] = []
    for (const key of unionKeys) {
      if (LOANWORD_OPT_OUT.has(key)) {
        continue
      }
      const english = flats.en[key]
      if (!english) {
        continue
      }
      for (const locale of ['de', 'es', 'fr', 'tr'] as const) {
        if (flats[locale][key] === english) {
          identical.push(`${locale}:${key}`)
        }
      }
    }
    expect(identical).toEqual([])
  })

  it('rejects banned jargon in primary Saved Task / Connection copy', () => {
    const hits: string[] = []
    for (const key of unionKeys) {
      for (const locale of Object.keys(LOCALES) as Locale[]) {
        const value = flats[locale][key] ?? ''
        for (const pattern of BANNED_JARGON) {
          if (pattern.test(value)) {
            hits.push(`${locale}:${key} matches ${pattern}`)
          }
        }
      }
    }
    expect(hits).toEqual([])
  })

  it('fails when a gated DE key is removed (parity helper contract)', () => {
    const broken = { ...flats.de }
    const probe = unionKeys[0] ?? 'config.savedTasks.__parity_probe__'
    delete broken[probe]
    const missing = unionKeys.filter((key) => !(key in broken))
    if (unionKeys.length === 0) {
      // Namespaces are empty until the first UI strings land — the live
      // key-parity test above is then a no-op, so assert the helper itself.
      const fixtureEn = { 'config.savedTasks.saveAsTask': 'Save as task' }
      const fixtureDe: Record<string, string> = {}
      expect(Object.keys(fixtureEn).filter((key) => !(key in fixtureDe))).toEqual([
        'config.savedTasks.saveAsTask',
      ])
      return
    }
    expect(missing.length).toBeGreaterThan(0)
  })
})

describe('i18n full-file locale parity', () => {
  const flats = Object.fromEntries(
    (Object.keys(LOCALES) as Locale[]).map((locale) => [locale, flatten(LOCALES[locale])])
  ) as Record<Locale, Record<string, string>>

  const TRANSLATED = ['de', 'es', 'fr', 'tr'] as const
  const englishKeys = Object.keys(flats.en)

  // fr's ledger entries are empty, so a bare index of the raw JSON import
  // narrows to never[] and rejects every string lookup.
  const ledger = baseline as Record<
    (typeof TRANSLATED)[number],
    { missingFromLocale: string[]; notInEnglish: string[] }
  >

  it.each(TRANSLATED)('%s.json has no untracked missing keys', (locale) => {
    const missing = englishKeys.filter((key) => !(key in flats[locale])).sort()
    const tracked = ledger[locale].missingFromLocale
    const added = missing.filter((key) => !tracked.includes(key))
    const closed = tracked.filter((key) => !missing.includes(key))

    expect(
      added,
      `New untranslated keys in ${locale}.json. Translate them, or (only for a ` +
        `genuine exception) add them to tests/unit/i18n/localeParityBaseline.json.`
    ).toEqual([])
    expect(
      closed,
      `${locale}.json now translates keys still listed as missing. Remove them ` +
        `from tests/unit/i18n/localeParityBaseline.json to lock the progress in.`
    ).toEqual([])
  })

  it.each(TRANSLATED)('%s.json has no untracked keys absent from English', (locale) => {
    const orphans = Object.keys(flats[locale])
      .filter((key) => !(key in flats.en))
      .sort()
    const tracked = ledger[locale].notInEnglish
    const added = orphans.filter((key) => !tracked.includes(key))
    const removed = tracked.filter((key) => !orphans.includes(key))

    expect(
      added,
      `${locale}.json defines keys that en.json does not. These are unreachable ` +
        `and usually leftovers from a renamed namespace — delete them.`
    ).toEqual([])
    expect(
      removed,
      `${locale}.json no longer has these orphans. Remove them from ` +
        `tests/unit/i18n/localeParityBaseline.json.`
    ).toEqual([])
  })

  it('keeps interpolation placeholders identical across every locale', () => {
    const mismatches: string[] = []
    for (const key of englishKeys) {
      const expected = placeholderSet(flats.en[key])
      for (const locale of TRANSLATED) {
        if (!(key in flats[locale])) {
          continue
        }
        const actual = placeholderSet(flats[locale][key])
        if (expected.join(',') !== actual.join(',')) {
          mismatches.push(`${locale}:${key} en={${expected.join(',')}} got={${actual.join(',')}}`)
        }
      }
    }
    expect(mismatches, 'A dropped or renamed placeholder renders as literal text').toEqual([])
  })

  it('has a fully translated fr.json, with no drift allowance', () => {
    expect(baseline.fr.missingFromLocale).toEqual([])
    expect(baseline.fr.notInEnglish).toEqual([])
  })
})
