/**
 * Runtime accent-color theming (Epic 4).
 *
 * When a white-label deployment configures a custom `branding.primaryColor`, we
 * override the `--brand*` CSS variables on :root at runtime. The default color
 * is left untouched so the carefully tuned light/dark stylesheet values (incl.
 * the lighter dark-mode brand) keep working for the stock Synaplan look.
 */
import config from '@/stores/config'

const DEFAULT_PRIMARY = '#003fc7'
const HEX_COLOR = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

export function applyBrandingTheme(): void {
  const color = config.branding.primaryColor

  if (!HEX_COLOR.test(color) || color.toLowerCase() === DEFAULT_PRIMARY) {
    return
  }

  const root = document.documentElement
  root.style.setProperty('--brand', color)
  root.style.setProperty('--brand-hover', `color-mix(in srgb, ${color} 88%, black)`)
  root.style.setProperty('--brand-light', `color-mix(in srgb, ${color} 55%, white)`)
  root.style.setProperty('--brand-alpha-light', `color-mix(in srgb, ${color} 10%, transparent)`)
}
