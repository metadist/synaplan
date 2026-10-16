/**
 * Currency / token formatting for the usage taximeter.
 *
 * Kept framework-free (plain functions taking the active locale) so it is
 * trivially unit-testable and shared by the bar, ring and stats panel.
 */

/**
 * Model prices are stored as US dollars per 1M tokens. The usage meter shows
 * that same currency. Language only changes grouping and the symbol position.
 */
export function formatUsd(value: number, locale = 'en'): string {
  return new Intl.NumberFormat(locale, { style: 'currency', currency: 'USD' }).format(
    Number.isFinite(value) ? value : 0
  )
}

/** @deprecated Use formatUsd. Kept so older call sites keep compiling. */
export function formatEuro(value: number, locale = 'en'): string {
  return formatUsd(value, locale)
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
  return formatUsd(value, locale)
}
