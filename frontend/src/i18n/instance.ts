import { createI18n } from 'vue-i18n'
import { getInitialLanguage, resilientMessageCompiler, supportedLanguages } from './shared'

const emptyMessages = Object.fromEntries(supportedLanguages.map((locale) => [locale, {}]))

export const i18n = createI18n({
  legacy: false,
  locale: getInitialLanguage(),
  fallbackLocale: 'en',
  messageCompiler: resilientMessageCompiler,
  messages: emptyMessages,
})
