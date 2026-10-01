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

/** Ink candidates for text placed ON a brand fill (the stylesheet tokens). */
const ON_BRAND_LIGHT = '#ffffff'
const ON_BRAND_DARK = '#0a0e1a'

export function applyBrandingTheme(): void {
  applyColors()
  applyFonts()
  applyIcon()
}

/**
 * WCAG contrast helpers for brand fills. A custom brand can be any hex, so the
 * ink on top of it cannot be a fixed per-theme token: a dark explicit
 * dark-mode color with the fixed near-black ink reads as black-on-dark, and a
 * light primary with fixed white ink as white-on-light. The ink is the design
 * token that already reaches AA, or pure black when near-black still falls
 * short; the bubble fill is darkened until white passes, so bubbles (whose
 * internals assume white ink) stay readable whatever the brand.
 */
function hexToRgb(hex: string): [number, number, number] {
  const full = hex.length === 4 ? `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}` : hex
  return [
    parseInt(full.slice(1, 3), 16) / 255,
    parseInt(full.slice(3, 5), 16) / 255,
    parseInt(full.slice(5, 7), 16) / 255,
  ]
}

function relativeLuminance(hex: string): number {
  const channel = (c: number): number =>
    c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
  const [r, g, b] = hexToRgb(hex).map(channel)
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contrastRatio(a: string, b: string): number {
  const [hi, lo] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/** WCAG AA for normal-size text. */
const AA_NORMAL_TEXT = 4.5

/**
 * Ink for text on a brand fill. White or near-black when that token already
 * reaches AA; otherwise pure black. Near-black (#0a0e1a) loses to white on
 * mid-greys such as #787878, and that white is only 4.42:1 — under AA.
 * Pure black against the same fill clears 4.5:1.
 */
export function pickOnBrandColor(fillHex: string): string {
  const onLight = contrastRatio(fillHex, ON_BRAND_LIGHT)
  const onDark = contrastRatio(fillHex, ON_BRAND_DARK)
  if (onLight >= AA_NORMAL_TEXT && onLight >= onDark) {
    return ON_BRAND_LIGHT
  }
  if (onDark >= AA_NORMAL_TEXT && onDark >= onLight) {
    return ON_BRAND_DARK
  }
  return contrastRatio(fillHex, '#000000') >= onLight ? '#000000' : ON_BRAND_LIGHT
}

function mixHex(a: string, b: string, keepA: number): string {
  const [ar, ag, ab] = hexToRgb(a)
  const [br, bg, bc] = hexToRgb(b)
  const mix = (x: number, y: number): string =>
    Math.round((x * keepA + y * (1 - keepA)) * 255)
      .toString(16)
      .padStart(2, '0')
  return `#${mix(ar, br)}${mix(ag, bg)}${mix(ab, bc)}`
}

/**
 * Bubble fills assume white ink. Keep the brand when white already reaches
 * AA on it; otherwise mix 18% toward black, up to 10 times. Pure white, a
 * valid brand color, reaches 4.5:1 on the fourth step.
 */
export function ensureFillForWhiteText(fillHex: string): string {
  let fill =
    fillHex.length === 4
      ? `#${fillHex[1]}${fillHex[1]}${fillHex[2]}${fillHex[2]}${fillHex[3]}${fillHex[3]}`
      : fillHex
  for (let i = 0; i < 10 && contrastRatio(fill, ON_BRAND_LIGHT) < 4.5; i++) {
    fill = mixHex(fill, '#000000', 0.82)
  }
  return fill
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
      `--brand-alpha-light:color-mix(in srgb, ${primaryColor} 10%, transparent)`,
      // Buttons, checkboxes and icon-contrast read the ink that wins on this
      // brand — never a fixed white that vanishes on a light custom color.
      `--on-brand:${pickOnBrandColor(primaryColor)}`,
      // User bubbles use the saturated brand, darkened until white passes, so
      // they never turn into a bright wash with unreadable white text.
      `--brand-fill:${ensureFillForWhiteText(primaryColor)}`
    )
  }

  if (hasDarkPrimary) {
    // Operator picked the dark color explicitly — use it as-is and derive only
    // the auxiliary tints from it. The ink wins on that choice (a dark custom
    // color with the fixed near-black ink would read as black-on-dark); the
    // bubble fill darkens until white passes.
    darkVars.push(
      `--brand:${primaryColorDark}`,
      `--brand-hover:color-mix(in srgb, ${primaryColorDark} 82%, white)`,
      `--brand-light:color-mix(in srgb, ${primaryColorDark} 70%, white)`,
      `--brand-alpha-light:color-mix(in srgb, ${primaryColorDark} 20%, transparent)`,
      `--on-brand:${pickOnBrandColor(primaryColorDark)}`,
      `--brand-fill:${ensureFillForWhiteText(primaryColorDark)}`
    )
  } else if (hasLightPrimary) {
    // No explicit dark color: derive a dark-friendly tint from the light one
    // for icons and buttons. The tint is always light, so near-black ink wins;
    // the message bubble keeps the saturated primary (darkened until white
    // passes) so it does not turn into a bright wash.
    const darkTint = mixHex(primaryColor, '#ffffff', 0.58)
    darkVars.push(
      `--brand:color-mix(in srgb, ${primaryColor} 58%, white)`,
      `--brand-hover:color-mix(in srgb, ${primaryColor} 46%, white)`,
      `--brand-light:color-mix(in srgb, ${primaryColor} 40%, white)`,
      `--brand-alpha-light:color-mix(in srgb, ${primaryColor} 20%, transparent)`,
      `--on-brand:${pickOnBrandColor(darkTint)}`,
      `--brand-fill:${ensureFillForWhiteText(primaryColor)}`
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
    document.getElementById(BRAND_COLOR_STYLE_ID)?.remove()
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

/**
 * Point the document favicon at `branding.iconUrl` when configured.
 * Empty keeps the bundled Synaplan bird from index.html.
 *
 * index.html ships both an SVG icon and a PNG fallback. Overwriting every
 * href with one URL while leaving the original `type` would mismatch (e.g.
 * type="image/png" pointing at an .svg), which some browsers then ignore.
 * Collapse to a single correctly-typed <link rel="icon"> instead.
 */
function applyIcon(): void {
  const iconUrl = config.branding.iconUrl
  if (!iconUrl) {
    return
  }

  const type = iconMimeType(iconUrl)
  const icons = Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="icon"]'))
  const [primary, ...extras] = icons

  if (primary) {
    primary.href = iconUrl
    primary.removeAttribute('sizes')
    if (type) {
      primary.type = type
    } else {
      primary.removeAttribute('type')
    }
    extras.forEach((link) => link.remove())
  } else {
    const link = document.createElement('link')
    link.rel = 'icon'
    link.href = iconUrl
    if (type) {
      link.type = type
    }
    document.head.appendChild(link)
  }

  const apple = document.querySelector<HTMLLinkElement>('link[rel="apple-touch-icon"]')
  if (apple) {
    apple.href = iconUrl
  }
}

function iconMimeType(url: string): string | undefined {
  let pathname: string
  try {
    pathname = new URL(url, 'https://placeholder.local').pathname
  } catch {
    pathname = url.split(/[?#]/)[0] ?? url
  }

  switch (pathname.split('.').pop()?.toLowerCase()) {
    case 'svg':
      return 'image/svg+xml'
    case 'png':
      return 'image/png'
    case 'ico':
      return 'image/x-icon'
    case 'webp':
      return 'image/webp'
    case 'gif':
      return 'image/gif'
    case 'jpg':
    case 'jpeg':
      return 'image/jpeg'
    default:
      return undefined
  }
}

function isHttpsUrl(value: string): boolean {
  try {
    return new URL(value).protocol === 'https:'
  } catch {
    return false
  }
}
