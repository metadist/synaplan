import { describe, expect, it } from 'vitest'
import { loadAllMessages } from '@/i18n/loadAllMessages'

/**
 * Wording guard for primary navigation copy (UX overhaul glossary,
 * `_devextras/planning/20261009-ux-overhaul/00_master_plan.md` §4).
 *
 * Menu labels, page titles and page intros are the first words a person
 * reads. Implementation words there made whole sections unreadable, so they
 * are banned in English; translations follow the English term.
 */
const BANNED = [
  /\binbound\b/i,
  /\bhandler\b/i,
  /\bBYO\b/,
  /\bcoding clients?\b/i,
  /\bplatform instances?\b/i,
  /\bchunks?\b/i,
  /\boperate\b/i,
]

const GUARDED_PREFIXES = ['nav.', 'pageTitles.', 'apps.', 'tours.']

function flatten(obj: unknown, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {}
  if (obj !== null && typeof obj === 'object' && !Array.isArray(obj)) {
    for (const [key, value] of Object.entries(obj as Record<string, unknown>)) {
      Object.assign(out, flatten(value, prefix ? `${prefix}.${key}` : key))
    }
    return out
  }
  out[prefix] = String(obj ?? '')
  return out
}

describe('wording guard', () => {
  const en = flatten(loadAllMessages('en'))

  it('keeps implementation words out of menu labels, page titles, apps and tours', () => {
    const hits: string[] = []
    for (const [key, value] of Object.entries(en)) {
      if (!GUARDED_PREFIXES.some((prefix) => key.startsWith(prefix))) continue
      for (const pattern of BANNED) {
        if (pattern.test(value)) hits.push(`${key} = "${value}" matches ${pattern}`)
      }
    }
    expect(hits).toEqual([])
  })

  it('detects a banned word (guard contract)', () => {
    expect(BANNED.some((pattern) => pattern.test('Inbound'))).toBe(true)
    expect(BANNED.some((pattern) => pattern.test('Apps'))).toBe(false)
  })
})
