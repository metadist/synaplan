import { createI18n } from 'vue-i18n'
import { compile } from '@intlify/core-base'
import type { MessageCompiler, MessageFunction } from '@intlify/core-base'
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

// A message function that ignores the interpolation context and returns the
// raw text unchanged. Mirrors the shape vue-i18n expects from a compiled
// message (a callable carrying a `source`).
function rawTextMessage(text: string): MessageFunction<string> {
  const fn = Object.assign(() => text, { source: text })
  return fn as unknown as MessageFunction<string>
}

// Resilient message compiler (issue #1137).
//
// vue-i18n compiles every message it renders through an ICU-style parser, so a
// string containing literal curly braces — code snippets (`{ count; }`), JSON,
// or `{placeholder}` text coming from web-search results — throws a
// "Message compilation error: invalid token in placeholder". That error
// bubbles up through component render and the global ErrorBoundary, leaving the
// whole view stuck on "Something Went Wrong" every time the offending message
// is loaded (a permanent, un-recoverable crash).
//
// This wrapper delegates to the real compiler for the happy path (named
// interpolation, plurals, linked messages all keep working) and only falls
// back to rendering the raw string when compilation fails. A single malformed
// message can no longer take the entire UI down.
const resilientMessageCompiler: MessageCompiler = (message, context) => {
  try {
    return compile(message, context)
  } catch (error) {
    // Only string sources can be safely shown verbatim; a pre-compiled AST
    // node failing is unexpected, so let it surface.
    if (typeof message !== 'string') {
      throw error
    }
    if (import.meta.env.DEV) {
      const reason = error instanceof Error ? error.message : String(error)
      console.warn(
        `[i18n] Could not compile message, rendering raw text instead: "${message}" (${reason})`
      )
    }
    return rawTextMessage(message)
  }
}

export const i18n = createI18n({
  legacy: false,
  locale: getInitialLanguage(),
  fallbackLocale: 'en',
  messageCompiler: resilientMessageCompiler,
  messages: {
    de,
    en,
    es,
    tr,
  },
})
