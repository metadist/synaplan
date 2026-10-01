import { i18n, isSupportedLanguage, setLocale, type SupportedLanguage } from '@/i18n'

let applyingStoredLocale = false

/** A `?lang=` parameter overrides the account language for this page load only. */
export function urlLanguageOverride(): SupportedLanguage | null {
  if (typeof window === 'undefined') return null
  const urlLang = new URLSearchParams(window.location.search).get('lang')?.toLowerCase() ?? ''
  return isSupportedLanguage(urlLang) ? urlLang : null
}

/**
 * One sync after `/auth/me` (or login, which carries the same field).
 * `null` stores the current UI language. A stored language switches the UI,
 * unless `?lang=` is present. Never writes while impersonating.
 */
export async function syncAccountLanguage(
  language: string | null,
  options: { persistUnset?: boolean } = {}
): Promise<void> {
  const persistUnset = options.persistUnset !== false
  if (urlLanguageOverride()) return

  if (language === null) {
    if (!persistUnset) return
    const current = String(i18n.global.locale.value)
    if (!isSupportedLanguage(current)) return
    await saveAccountLanguage(current)
    return
  }

  if (!isSupportedLanguage(language)) return
  if (language === String(i18n.global.locale.value)) return

  applyingStoredLocale = true
  try {
    await setLocale(language)
  } finally {
    applyingStoredLocale = false
  }
}

/** Persist the UI language onto the signed-in account. Skipped for guests and while applying a stored locale. */
export async function saveAccountLanguage(
  language: SupportedLanguage
): Promise<'saved' | 'skipped' | 'failed'> {
  if (applyingStoredLocale) return 'skipped'

  const { authService } = await import('@/services/authService')
  if (!authService.isAuthenticated()) return 'skipped'

  try {
    const { profileApi } = await import('@/services/api/profileApi')
    await profileApi.updateProfile({ language })
    const current = authService.getUser().value
    if (current) current.language = language
    return 'saved'
  } catch (error) {
    console.warn('[i18n] Could not save the account language', error)
    return 'failed'
  }
}
