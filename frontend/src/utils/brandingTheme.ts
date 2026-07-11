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
const BRAND_COLOR_STYLE_ID = 'brand-color-vars'

export function applyBrandingTheme(): void {
  applyColors()
  applyFonts()
}

/**
 * Inject the brand palette as a stylesheet (NOT inline styles on <html>).
 *
 * Inline styles win over every stylesheet rule, so setting `--brand` inline
 * forced the SAME raw primary color in both light and dark — on the near-black
 * dark surfaces a saturated brand (e.g. a deep indigo) then reads as a harsh
 * "pink/purple" on the send + active buttons. A stylesheet lets the dark theme
 * use its own value: the explicit dark-mode color when the operator configured
 * one (`primaryColorDark` etc.), otherwise a lightened, dark-mode-friendly tint
 * derived from the light color — mirroring how the stock brand (#003fc7)
 * becomes #6d9ae0 in dark. `html.dark` / `html:not(.dark)` keep a higher
 * specificity than the base `.dark` / `:root` rules so this always wins, and it
 * re-resolves automatically when the theme class toggles at runtime.
 */
function applyColors(): void {
  const {
    primaryColor,
    secondaryColor,
    accentColor,
    primaryColorDark,
    secondaryColorDark,
    accentColorDark,
  } = config.branding

  const lightVars: string[] = []
  const darkVars: string[] = []

  // Primary only overrides when it's a valid, non-default hex (preserves the
  // tuned light/dark stylesheet values for the stock brand).
  const hasLightPrimary =
    HEX_COLOR.test(primaryColor) && primaryColor.toLowerCase() !== DEFAULT_PRIMARY
  const hasDarkPrimary = HEX_COLOR.test(primaryColorDark)

  if (hasLightPrimary) {
    lightVars.push(
      `--brand:${primaryColor}`,
      `--brand-hover:color-mix(in srgb, ${primaryColor} 88%, black)`,
      `--brand-light:color-mix(in srgb, ${primaryColor} 55%, white)`,
      `--brand-alpha-light:color-mix(in srgb, ${primaryColor} 10%, transparent)`
    )
  }

  if (hasDarkPrimary) {
    // Operator picked the dark color explicitly — use it as-is and derive only
    // the auxiliary tints from it.
    darkVars.push(
      `--brand:${primaryColorDark}`,
      `--brand-hover:color-mix(in srgb, ${primaryColorDark} 82%, white)`,
      `--brand-light:color-mix(in srgb, ${primaryColorDark} 70%, white)`,
      `--brand-alpha-light:color-mix(in srgb, ${primaryColorDark} 20%, transparent)`
    )
  } else if (hasLightPrimary) {
    // No explicit dark color: derive a dark-friendly tint from the light one.
    darkVars.push(
      `--brand:color-mix(in srgb, ${primaryColor} 58%, white)`,
      `--brand-hover:color-mix(in srgb, ${primaryColor} 46%, white)`,
      `--brand-light:color-mix(in srgb, ${primaryColor} 40%, white)`,
      `--brand-alpha-light:color-mix(in srgb, ${primaryColor} 20%, transparent)`
    )
  }

  // Secondary/accent are additive, opt-in variables (unset by default). The
  // dark variant falls back to the light value when unset.
  if (HEX_COLOR.test(secondaryColor)) {
    lightVars.push(`--brand-secondary:${secondaryColor}`)
    darkVars.push(
      `--brand-secondary:${HEX_COLOR.test(secondaryColorDark) ? secondaryColorDark : secondaryColor}`
    )
  } else if (HEX_COLOR.test(secondaryColorDark)) {
    darkVars.push(`--brand-secondary:${secondaryColorDark}`)
  }
  if (HEX_COLOR.test(accentColor)) {
    lightVars.push(`--brand-accent:${accentColor}`)
    darkVars.push(
      `--brand-accent:${HEX_COLOR.test(accentColorDark) ? accentColorDark : accentColor}`
    )
  } else if (HEX_COLOR.test(accentColorDark)) {
    darkVars.push(`--brand-accent:${accentColorDark}`)
  }

  if (lightVars.length === 0 && darkVars.length === 0) {
    return
  }

  let styleEl = document.getElementById(BRAND_COLOR_STYLE_ID) as HTMLStyleElement | null
  if (!styleEl) {
    styleEl = document.createElement('style')
    styleEl.id = BRAND_COLOR_STYLE_ID
    document.head.appendChild(styleEl)
  }
  styleEl.textContent = `html:not(.dark){${lightVars.join(';')}}html.dark{${darkVars.join(';')}}`
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
