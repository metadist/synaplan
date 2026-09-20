import { createI18n } from 'vue-i18n'
import {
  getInitialLanguage,
  isSupportedLanguage,
  persistLanguage,
  resilientMessageCompiler,
  supportedLanguages,
  type SupportedLanguage,
} from './shared'
import { WIDGET_I18N_NAMESPACES, type WidgetI18nNamespace } from './namespaces'

type LocaleModule = { default: Record<string, unknown> }

const widgetModules = import.meta.glob<LocaleModule>([
  './locales/*/core.json',
  './locales/*/chat.json',
  './locales/*/widgets.json',
])

const loadedPairs = new Set<string>()

function modulePath(locale: SupportedLanguage, namespace: WidgetI18nNamespace): string {
  return `./locales/${locale}/${namespace}.json`
}

async function loadOne(locale: SupportedLanguage, namespace: WidgetI18nNamespace): Promise<void> {
  const key = `${locale}:${namespace}`
  if (loadedPairs.has(key)) {
    return
  }
  const loader = widgetModules[modulePath(locale, namespace)]
  if (!loader) {
    throw new Error(`Missing widget i18n chunk ${locale}/${namespace}`)
  }
  const mod = await loader()
  widgetI18n.global.mergeLocaleMessage(locale, mod.default)
  loadedPairs.add(key)
}

const emptyMessages = Object.fromEntries(supportedLanguages.map((locale) => [locale, {}]))

export const i18n = createI18n({
  legacy: false,
  locale: getInitialLanguage(),
  fallbackLocale: 'en',
  messageCompiler: resilientMessageCompiler,
  messages: emptyMessages,
})

const widgetI18n = i18n

export async function loadWidgetLocale(
  lang: SupportedLanguage = getInitialLanguage()
): Promise<SupportedLanguage> {
  const locale = isSupportedLanguage(lang) ? lang : 'en'
  const locales: SupportedLanguage[] = locale === 'en' ? ['en'] : [locale, 'en']
  await Promise.all(
    locales.flatMap((loc) => WIDGET_I18N_NAMESPACES.map((namespace) => loadOne(loc, namespace)))
  )
  widgetI18n.global.locale.value = locale
  persistLanguage(locale)
  return locale
}

export { supportedLanguages, languageOptions } from './shared'
export type { SupportedLanguage } from './shared'
export { WIDGET_I18N_NAMESPACES } from './namespaces'
