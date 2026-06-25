/**
 * Runtime brand theming (Epic 4): accent color(s), fonts, and optional web-font.
 *
 * MOBILE-APP SEAM (Epic 4): a white-label deployment — or the mobile app pointed
 * at a branded server — drives the look from runtime config. Everything here is
 * default-safe: when a field is unset (or equals the stock value) we leave the
 * carefully tuned `style.css` defaults untouched, so the stock Synaplan look and
 * the dark-mode brand variables keep working unchanged.
 *
 * Note on fonts: an external `fontUrl` only loads if its origin is allowed by the
 * CSP (`index.html`) and, for the app, by the configured server's allowed
 * origins. A blocked font simply fails to load and the font stack falls back.
 */
import config from '@/stores/config'

const DEFAULT_PRIMARY = '#003fc7'
const HEX_COLOR = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

const FONT_LINK_ID = 'brand-font-link'
const HEADING_STYLE_ID = 'brand-heading-font'

export function applyBrandingTheme(): void {
  applyColors()
  applyFonts()
}

function applyColors(): void {
  const root = document.documentElement
  const { primaryColor, secondaryColor, accentColor } = config.branding

  // Primary only overrides when it's a valid, non-default hex (preserves the
  // tuned light/dark stylesheet values for the stock brand).
  if (HEX_COLOR.test(primaryColor) && primaryColor.toLowerCase() !== DEFAULT_PRIMARY) {
    root.style.setProperty('--brand', primaryColor)
    root.style.setProperty('--brand-hover', `color-mix(in srgb, ${primaryColor} 88%, black)`)
    root.style.setProperty('--brand-light', `color-mix(in srgb, ${primaryColor} 55%, white)`)
    root.style.setProperty(
      '--brand-alpha-light',
      `color-mix(in srgb, ${primaryColor} 10%, transparent)`
    )
  }

  // Secondary/accent are additive, opt-in variables (unset by default).
  if (HEX_COLOR.test(secondaryColor)) {
    root.style.setProperty('--brand-secondary', secondaryColor)
  }
  if (HEX_COLOR.test(accentColor)) {
    root.style.setProperty('--brand-accent', accentColor)
  }
}

function applyFonts(): void {
  const { fontFamily, headingFontFamily, fontUrl } = config.branding

  // Load an external web-font stylesheet first (guarded by CSP). Idempotent.
  if (fontUrl && isHttpsUrl(fontUrl) && !document.getElementById(FONT_LINK_ID)) {
    const link = document.createElement('link')
    link.id = FONT_LINK_ID
    link.rel = 'stylesheet'
    link.href = fontUrl
    document.head.appendChild(link)
  }

  // Body font: inline style on <body> wins over the stylesheet's hardcoded stack.
  if (fontFamily) {
    document.body.style.fontFamily = fontFamily
  }

  // Heading font: inject a single rule (idempotent) so headings can differ.
  const heading = headingFontFamily || fontFamily
  if (heading) {
    let styleEl = document.getElementById(HEADING_STYLE_ID) as HTMLStyleElement | null
    if (!styleEl) {
      styleEl = document.createElement('style')
      styleEl.id = HEADING_STYLE_ID
      document.head.appendChild(styleEl)
    }
    styleEl.textContent = `h1,h2,h3,h4,h5,h6{font-family:${heading};}`
  }
}

function isHttpsUrl(value: string): boolean {
  try {
    return new URL(value).protocol === 'https:'
  } catch {
    return false
  }
}
