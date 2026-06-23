import { describe, it, expect, afterAll } from 'vitest'
import { i18n } from '@/i18n'

// Regression coverage for the #1137 follow-up: the `searchSummary` keys used
// ICU MessageFormat plural syntax (`{count, plural, one {…} other {…}}`) that
// vue-i18n's default compiler cannot parse. With the resilient compiler they
// stopped crashing but rendered as raw "{count, plural, …}" text. They now use
// vue-i18n's native pipe pluralization and must resolve to clean output.
describe('taskPlan.searchSummary pluralization (#1137 follow-up)', () => {
  const t = i18n.global.t
  const originalLocale = i18n.global.locale.value

  afterAll(() => {
    i18n.global.locale.value = originalLocale
  })

  const cases: ReadonlyArray<['de' | 'en' | 'es' | 'tr', number, string]> = [
    ['en', 1, 'Searched the web · 1 source'],
    ['en', 3, 'Searched the web · 3 sources'],
    ['de', 1, 'Web durchsucht · 1 Quelle'],
    ['de', 3, 'Web durchsucht · 3 Quellen'],
    ['es', 1, 'Búsqueda web · 1 fuente'],
    ['es', 3, 'Búsqueda web · 3 fuentes'],
    // Turkish has a single nominal form; the call still works without a pipe.
    ['tr', 1, 'Web arandı · 1 kaynak'],
    ['tr', 3, 'Web arandı · 3 kaynak'],
  ]

  it.each(cases)('renders locale %s with count %i correctly', (locale, count, expected) => {
    i18n.global.locale.value = locale
    expect(t('taskPlan.searchSummary', { count }, count)).toBe(expected)
  })

  it('never leaks raw ICU plural syntax', () => {
    for (const locale of ['de', 'en', 'es', 'tr'] as const) {
      i18n.global.locale.value = locale
      const rendered = t('taskPlan.searchSummary', { count: 2 }, 2)
      expect(rendered).not.toContain('plural')
      expect(rendered).not.toContain('{count,')
    }
  })
})
