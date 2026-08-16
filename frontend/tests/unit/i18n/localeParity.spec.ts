import { describe, expect, it } from 'vitest'
import de from '@/i18n/de.json'
import en from '@/i18n/en.json'
import es from '@/i18n/es.json'
import tr from '@/i18n/tr.json'

/**
 * Locale-parity gate for Saved Tasks / Connections (work-breakdown step F0).
 *
 * Full-file key parity is NOT gated: en/de/es/tr already drift (hundreds of
 * keys missing in es/tr as of 2026-08). This test only watches the new
 * namespaces so a missing translation cannot land with this epic.
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

const LOCALES = { en, de, es, tr } as const
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

  it('has the same keys in en/de/es/tr inside gated namespaces', () => {
    for (const locale of Object.keys(LOCALES) as Locale[]) {
      const missing = unionKeys.filter((key) => !(key in flats[locale]))
      expect(missing, `${locale}.json missing gated keys`).toEqual([])
    }
  })

  it('uses the same interpolation placeholders across locales', () => {
    const mismatches: string[] = []
    for (const key of unionKeys) {
      const expected = placeholders(flats.en[key] ?? '')
      for (const locale of ['de', 'es', 'tr'] as const) {
        const actual = placeholders(flats[locale][key] ?? '')
        if (expected.join(',') !== actual.join(',')) {
          mismatches.push(`${key}: en={${expected.join(',')}} ${locale}={${actual.join(',')}}`)
        }
      }
    }
    expect(mismatches).toEqual([])
  })

  it('does not leave DE/ES/TR identical to English (except loanword opt-out)', () => {
    const identical: string[] = []
    for (const key of unionKeys) {
      if (LOANWORD_OPT_OUT.has(key)) {
        continue
      }
      const english = flats.en[key]
      if (!english) {
        continue
      }
      for (const locale of ['de', 'es', 'tr'] as const) {
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
