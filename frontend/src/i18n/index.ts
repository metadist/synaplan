import { createI18n } from 'vue-i18n'
import en from './en.json'
import de from './de.json'

const savedLanguage = localStorage.getItem('language') || 'en'

export const i18n = createI18n({
  legacy: false,
  locale: savedLanguage,
  fallbackLocale: 'en',
  messages: { 
    en,
    de
  },
})
