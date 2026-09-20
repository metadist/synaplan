import { ref, watch, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { i18n } from './instance'
import {
  I18N_NAMESPACES,
  isI18nNamespace,
  type I18nNamespace,
  WIDGET_I18N_NAMESPACES,
  type WidgetI18nNamespace,
} from './namespaces'
import {
  getInitialLanguage,
  isSupportedLanguage,
  persistLanguage,
  supportedLanguages,
  type SupportedLanguage,
} from './shared'

type LocaleModule = { default: Record<string, unknown> }
type NamespaceImporter = (
  locale: SupportedLanguage,
  namespace: I18nNamespace
) => Promise<Record<string, unknown>>

const localeModules = import.meta.glob<LocaleModule>('./locales/*/*.json')

const loadedPairs = new Set<string>()
const inflightPairs = new Map<string, Promise<void>>()
const activeNamespaces = new Set<I18nNamespace>(['core'])

function pairKey(locale: SupportedLanguage, namespace: I18nNamespace): string {
  return `${locale}:${namespace}`
}

function modulePath(locale: SupportedLanguage, namespace: I18nNamespace): string {
  return `./locales/${locale}/${namespace}.json`
}

const defaultImporter: NamespaceImporter = async (locale, namespace) => {
  const loader = localeModules[modulePath(locale, namespace)]
  if (!loader) {
    throw new Error(`Missing i18n chunk ${locale}/${namespace}`)
  }
  const mod = await loader()
  return mod.default
}

let importer: NamespaceImporter = defaultImporter

function uniqueNamespaces(namespaces: readonly I18nNamespace[]): I18nNamespace[] {
  const seen = new Set<I18nNamespace>()
  const result: I18nNamespace[] = []
  for (const namespace of ['core', ...namespaces] as const) {
    if (!seen.has(namespace)) {
      seen.add(namespace)
      result.push(namespace)
    }
  }
  return result
}

async function loadOne(locale: SupportedLanguage, namespace: I18nNamespace): Promise<void> {
  const key = pairKey(locale, namespace)
  if (loadedPairs.has(key)) {
    return
  }
  const existing = inflightPairs.get(key)
  if (existing) {
    return existing
  }

  const promise = (async () => {
    const chunk = await importer(locale, namespace)
    i18n.global.mergeLocaleMessage(locale, chunk)
    loadedPairs.add(key)
  })().finally(() => {
    inflightPairs.delete(key)
  })

  inflightPairs.set(key, promise)
  return promise
}

export async function loadNamespaces(
  locale: SupportedLanguage,
  namespaces: readonly I18nNamespace[] = []
): Promise<void> {
  if (!isSupportedLanguage(locale)) {
    throw new Error(`Unsupported locale: ${locale}`)
  }

  const needed = uniqueNamespaces(namespaces)
  for (const namespace of needed) {
    activeNamespaces.add(namespace)
  }

  const locales: SupportedLanguage[] = locale === 'en' ? ['en'] : [locale, 'en']
  await Promise.all(locales.flatMap((loc) => needed.map((namespace) => loadOne(loc, namespace))))
}

export async function preloadCore(locale: SupportedLanguage = getInitialLanguage()): Promise<void> {
  await loadNamespaces(locale, ['core'])
}

export async function setLocale(lang: string): Promise<boolean> {
  if (!isSupportedLanguage(lang)) {
    return false
  }

  try {
    await loadNamespaces(lang, [...activeNamespaces])
    i18n.global.locale.value = lang
    persistLanguage(lang)
    return true
  } catch (error) {
    console.error('[i18n] Failed to load locale', lang, error)
    const { error: toastError } = useNotification()
    toastError(String(i18n.global.t('error.description')))
    return false
  }
}

export async function cycleLocale(): Promise<boolean> {
  const current = String(i18n.global.locale.value)
  const currentIndex = supportedLanguages.indexOf(current as SupportedLanguage)
  const next =
    supportedLanguages[(currentIndex >= 0 ? currentIndex + 1 : 0) % supportedLanguages.length]
  return setLocale(next)
}

export function useI18nNamespaces(namespaces: readonly I18nNamespace[]): { loaded: Ref<boolean> } {
  const loaded = ref(false)
  const { locale } = useI18n()

  const ensure = async () => {
    loaded.value = false
    const lang = String(locale.value)
    if (!isSupportedLanguage(lang)) {
      loaded.value = true
      return
    }
    await loadNamespaces(lang, namespaces)
    loaded.value = true
  }

  watch(locale, () => void ensure(), { immediate: true })
  return { loaded }
}

export function rememberRouteNamespaces(namespaces: readonly string[]): I18nNamespace[] {
  const resolved = namespaces.filter(isI18nNamespace)
  for (const namespace of resolved) {
    activeNamespaces.add(namespace)
  }
  return resolved
}

export function getActiveNamespaces(): I18nNamespace[] {
  return [...activeNamespaces]
}

export function getLoadedPairs(): string[] {
  return [...loadedPairs]
}

export function resetI18nLoaderForTests(): void {
  loadedPairs.clear()
  inflightPairs.clear()
  activeNamespaces.clear()
  activeNamespaces.add('core')
  importer = defaultImporter
}

export function setNamespaceImporterForTests(next: NamespaceImporter | null): void {
  importer = next ?? defaultImporter
}

export function markNamespacesLoadedForTests(
  locales: readonly SupportedLanguage[] = supportedLanguages,
  namespaces: readonly I18nNamespace[] = I18N_NAMESPACES
): void {
  for (const locale of locales) {
    for (const namespace of namespaces) {
      loadedPairs.add(pairKey(locale, namespace))
      activeNamespaces.add(namespace)
    }
  }
}

export const ALL_I18N_NAMESPACES = I18N_NAMESPACES
export const WIDGET_NAMESPACES = WIDGET_I18N_NAMESPACES
export type { I18nNamespace, WidgetI18nNamespace }
