/**
 * Widget-build stand-in for the public `@/i18n` barrel.
 *
 * The real barrel re-exports the app loader (`import.meta.glob` of every
 * locale file). With `inlineDynamicImports` that would ship admin/config
 * strings in widget.js. The embed only needs the singleton + language list.
 */
export { i18n } from '@/i18n/instance'
import { isSupportedLanguage } from '@/i18n/shared'

export {
  languageOptions,
  supportedLanguages,
  getInitialLanguage,
  isSupportedLanguage,
  type SupportedLanguage,
} from '@/i18n/shared'
export { I18N_NAMESPACES, WIDGET_I18N_NAMESPACES, NAMESPACE_KEYS } from '@/i18n/namespaces'

/**
 * The embed inlines the auth store, which imports account-language sync.
 * Locale changes in the widget go through `i18n/widget`, and they must not
 * write the visitor's account language.
 */
export async function setLocale(lang: string): Promise<boolean> {
  // The widget loader owns the visible locale. This exists so the inlined
  // auth store can import it without pulling the app locale catalog.
  return isSupportedLanguage(lang) && false
}
