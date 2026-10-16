import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { i18n, supportedLanguages } from '@/i18n'
import { loadAllMessages } from '@/i18n/loadAllMessages'
import {
  getLoadedPairs,
  loadNamespaces,
  markNamespacesLoadedForTests,
  resetI18nLoaderForTests,
  setLocale,
  setNamespaceImporterForTests,
} from '@/i18n/loader'

function restoreFullCatalog(): void {
  resetI18nLoaderForTests()
  setNamespaceImporterForTests(null)
  for (const locale of supportedLanguages) {
    i18n.global.setLocaleMessage(locale, loadAllMessages(locale))
  }
  markNamespacesLoadedForTests()
  i18n.global.locale.value = 'en'
}

describe('i18n lazy loader', () => {
  beforeEach(() => {
    restoreFullCatalog()
    resetI18nLoaderForTests()
    i18n.global.locale.value = 'en'
    localStorage.setItem('language', 'en')
  })

  afterEach(() => {
    restoreFullCatalog()
    vi.restoreAllMocks()
  })

  it('loads the English twin whenever a non-English locale is requested', async () => {
    const calls: string[] = []
    setNamespaceImporterForTests(async (locale, namespace) => {
      calls.push(`${locale}:${namespace}`)
      return { [namespace]: { loaded: locale } }
    })

    await loadNamespaces('de', ['chat'])

    expect(calls.sort()).toEqual(['de:chat', 'de:core', 'en:chat', 'en:core'])
    expect(getLoadedPairs().sort()).toEqual(['de:chat', 'de:core', 'en:chat', 'en:core'])
  })

  it('skips already-loaded locale/namespace pairs', async () => {
    let calls = 0
    setNamespaceImporterForTests(async () => {
      calls += 1
      return { common: { ok: 'OK' } }
    })

    await loadNamespaces('en', ['core'])
    const first = calls
    expect(first).toBeGreaterThan(0)

    await loadNamespaces('en', ['core'])
    expect(calls).toBe(first)
  })

  it('keeps the previous locale when a load fails', async () => {
    setNamespaceImporterForTests(async () => {
      throw new Error('chunk failed')
    })

    const ok = await setLocale('de')

    expect(ok).toBe(false)
    expect(i18n.global.locale.value).toBe('en')
    expect(localStorage.getItem('language')).toBe('en')
  })

  it('flips the locale and persists it after a successful load', async () => {
    setNamespaceImporterForTests(async () => ({ common: { ok: 'OK' } }))

    const ok = await setLocale('fr')

    expect(ok).toBe(true)
    expect(i18n.global.locale.value).toBe('fr')
    expect(localStorage.getItem('language')).toBe('fr')
    expect(document.documentElement.lang).toBe('fr')
  })

  it('rejects an unsupported language without touching the current locale', async () => {
    const ok = await setLocale('xx')
    expect(ok).toBe(false)
    expect(i18n.global.locale.value).toBe('en')
  })
})
