import { createI18n } from 'vue-i18n'
import de from './de.json'
import en from './en.json'
import es from './es.json'
import tr from './tr.json'

// Supported languages in alphabetical order (EN is default)
export const supportedLanguages = ['de', 'en', 'es', 'tr'] as const
export type SupportedLanguage = (typeof supportedLanguages)[number]

// Check for language from URL query parameter first, then localStorage
function getInitialLanguage(): SupportedLanguage {
  // Check URL query parameter
  const urlParams = new URLSearchParams(window.location.search)
  const urlLang = urlParams.get('lang')?.toLowerCase()
  if (urlLang && supportedLanguages.includes(urlLang as SupportedLanguage)) {
    // Save to localStorage for subsequent visits
    localStorage.setItem('language', urlLang)
    return urlLang as SupportedLanguage
  }

  // Fall back to localStorage or default
  const savedLanguage = localStorage.getItem('language')
  if (savedLanguage && supportedLanguages.includes(savedLanguage as SupportedLanguage)) {
    return savedLanguage as SupportedLanguage
  }

  return 'en'
}

export const i18n = createI18n({
  legacy: false,
  locale: getInitialLanguage(),
  fallbackLocale: 'en',
  messages: {
    de,
    en,
    es,
    tr,
  },
})
