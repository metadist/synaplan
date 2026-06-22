import { describe, it, expect, beforeAll } from 'vitest'
import { i18n } from '@/i18n'

// Regression coverage for issue #1137: a message containing literal curly
// braces (code snippets, JSON, or `{placeholder}` text from web-search
// results) used to throw a vue-i18n "Message compilation error" and brick the
// whole view via the ErrorBoundary. The resilient compiler in `@/i18n` must
// keep the app alive by falling back to the raw string, while leaving valid
// interpolation, plurals and linked messages untouched.
describe('i18n resilient message compiler (#1137)', () => {
  const t = i18n.global.t

  beforeAll(() => {
    i18n.global.locale.value = 'en'
    i18n.global.mergeLocaleMessage('en', {
      __test_braces: 'Lokaler {stormName}-Tracker function x() { return count; }',
      __test_named: 'Hello {name}',
      __test_plural: 'no items | one item | {count} items',
      __test_linked: '@:__test_named world',
    })
  })

  it('renders a registered message with malformed braces as raw text instead of throwing', () => {
    expect(() => t('__test_braces')).not.toThrow()
    expect(t('__test_braces')).toBe('Lokaler {stormName}-Tracker function x() { return count; }')
  })

  it('does not throw when malformed content is passed as a default/fallback message', () => {
    expect(() => t('totally.missing.key', 'code { return count; } end')).not.toThrow()
    expect(t('totally.missing.key', 'code { return count; } end')).toBe(
      'code { return count; } end'
    )
  })

  it('still resolves valid named interpolation', () => {
    expect(t('__test_named', { name: 'Sam' })).toBe('Hello Sam')
  })

  it('still resolves plurals', () => {
    expect(t('__test_plural', 0)).toBe('no items')
    expect(t('__test_plural', 1)).toBe('one item')
    expect(t('__test_plural', 5)).toBe('5 items')
  })

  it('still resolves linked messages', () => {
    expect(t('__test_linked', { name: 'Sam' })).toBe('Hello Sam world')
  })
})
