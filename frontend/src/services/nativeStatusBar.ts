/**
 * Native status-bar theming (Epic 9.5 / Guideline 4.2).
 *
 * Keeps the OS status bar in sync with the app theme so the app reads as a
 * first-class native client rather than a web wrapper: dark mode → light icons
 * on a dark bar, light mode → dark icons on a light bar, with the bar background
 * matching the app's `--bg-app` (so white-label themes are honored too).
 *
 * No-op on web (no Capacitor StatusBar plugin). On iOS only the icon style is
 * adjustable; the Android-only background color is guarded accordingly.
 */
import { isNativeApp } from '@/services/api/nativeRuntime'

let registered = false

/** Resolve the active `--bg-app` CSS variable to a #rrggbb string, or null. */
function readAppBackgroundHex(): string | null {
  const raw = getComputedStyle(document.documentElement).getPropertyValue('--bg-app').trim()
  if (!raw) return null
  if (raw.startsWith('#')) return raw
  const parts = raw.match(/\d+/g)
  if (!parts || parts.length < 3) return null
  const [r, g, b] = parts.map(Number)
  return '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')
}

function isDarkActive(): boolean {
  return document.documentElement.classList.contains('dark')
}

export async function initNativeStatusBar(): Promise<void> {
  if (registered || !isNativeApp()) {
    return
  }
  registered = true

  const { StatusBar, Style } = await import('@capacitor/status-bar')
  const { Capacitor } = await import('@capacitor/core')
  const isAndroid = Capacitor.getPlatform() === 'android'

  const sync = async (): Promise<void> => {
    try {
      const dark = isDarkActive()
      // Style.Dark = light icons (for dark backgrounds); Style.Light = dark icons.
      await StatusBar.setStyle({ style: dark ? Style.Dark : Style.Light })
      if (isAndroid) {
        await StatusBar.setOverlaysWebView({ overlay: false })
        const hex = readAppBackgroundHex()
        if (hex) {
          await StatusBar.setBackgroundColor({ color: hex })
        }
      }
    } catch (err) {
      console.error('Status bar sync failed', err)
    }
  }

  await sync()

  // useTheme() toggles the `.dark` class on <html> synchronously; mirror any
  // change (incl. white-label `--bg-app` swaps) onto the native bar. A plain DOM
  // observer avoids depending on a Vue effect scope from this fire-and-forget init.
  new MutationObserver(() => void sync()).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  })
  // `theme: system` follows the OS without touching the class until it flips.
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => void sync())
}
