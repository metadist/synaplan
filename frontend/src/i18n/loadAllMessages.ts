import type { SupportedLanguage } from './shared'

type LocaleModule = { default: Record<string, unknown> }

const eagerModules = import.meta.glob<LocaleModule>('./locales/*/*.json', { eager: true })

export function loadAllMessages(locale: string): Record<string, unknown> {
  const needle = `/locales/${locale}/`
  const merged: Record<string, unknown> = {}
  for (const [path, mod] of Object.entries(eagerModules)) {
    if (path.includes(needle)) {
      Object.assign(merged, mod.default)
    }
  }
  return merged
}

export function loadAllLocaleMessages(): Record<SupportedLanguage, Record<string, unknown>> {
  return {
    de: loadAllMessages('de'),
    en: loadAllMessages('en'),
    es: loadAllMessages('es'),
    fr: loadAllMessages('fr'),
    tr: loadAllMessages('tr'),
  }
}
