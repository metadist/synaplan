import { ref } from 'vue'
import { i18n } from '@/i18n/instance'
import { loadNamespaces } from '@/i18n/loader'
import { supportedLanguages } from '@/i18n/shared'

/**
 * `te()`/`t()` with an explicit locale are not reactive dependencies, so
 * computeds that read other locales re-run only when this ref changes.
 */
const allLocalesLoaded = ref(false)
let request: Promise<void> | null = null

/**
 * Loads the `core` messages of every locale once, so page titles and
 * synonyms match a query typed in any of the five languages.
 */
export function ensureAllLocales(): Promise<void> {
  request ??= Promise.all(
    supportedLanguages.map((locale) => loadNamespaces(locale, ['core']).catch(() => undefined))
  ).then(() => {
    allLocalesLoaded.value = true
  })
  return request
}

/** Every translation of `key` that exists, so a query in any locale matches. */
export function allLocaleTexts(key: string): string[] {
  void allLocalesLoaded.value
  const texts = new Set<string>()
  for (const locale of supportedLanguages) {
    if (i18n.global.te(key, locale)) {
      texts.add(String(i18n.global.t(key, {}, { locale })))
    }
  }
  return [...texts]
}
