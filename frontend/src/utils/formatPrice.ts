import { i18n } from '@/i18n'

/**
 * Format a plan price with its server-configured currency (ISO 4217) in the
 * user's UI language. Falls back to a plain "12.34 XXX" suffix when the code
 * is unknown to the runtime, so a typo in the admin panel never breaks the UI.
 */
export function formatPlanPrice(price: number, currency: string): string {
  const locale = i18n.global.locale.value
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(price)
  } catch {
    return `${price.toFixed(2)} ${currency}`
  }
}
