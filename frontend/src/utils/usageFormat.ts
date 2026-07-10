/**
 * Currency / token formatting for the usage taximeter.
 *
 * Kept framework-free (plain functions taking the active locale) so it is
 * trivially unit-testable and shared by the bar, ring and stats panel.
 */

/** Format a euro amount with the locale's currency style (e.g. "1,12 €"). */
export function formatEuro(value: number, locale = 'en'): string {
  return new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' }).format(
    Number.isFinite(value) ? value : 0
  )
}

/** Format a token count with locale grouping (e.g. "8.420"). */
export function formatTokens(value: number, locale = 'en'): string {
  return new Intl.NumberFormat(locale).format(Math.max(0, Math.round(value)))
}

/**
 * Display string for a cost: below one cent (but > 0) it renders the provided
 * "< 0.01 €" label so tiny spends stay honest instead of showing "0,00 €".
 */
export function formatCostDisplay(
  value: number,
  locale: string,
  lessThanCentLabel: string
): string {
  if (value > 0 && value < 0.01) {
    return lessThanCentLabel
  }
  return formatEuro(value, locale)
}
