/**
 * Widget-build stand-in for the public `@/i18n` barrel.
 *
 * The real barrel re-exports the app loader (`import.meta.glob` of every
 * locale file). With `inlineDynamicImports` that would ship admin/config
 * strings in widget.js. The embed only needs the singleton + language list.
 */
export { i18n } from '@/i18n/instance'
export {
  languageOptions,
  supportedLanguages,
  getInitialLanguage,
  isSupportedLanguage,
  type SupportedLanguage,
} from '@/i18n/shared'
export { I18N_NAMESPACES, WIDGET_I18N_NAMESPACES, NAMESPACE_KEYS } from '@/i18n/namespaces'
