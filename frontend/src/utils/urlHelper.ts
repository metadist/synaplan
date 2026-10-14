import { getNativeApiBaseUrl } from '@/services/api/nativeRuntime'
import { getNativeServerUrl } from '@/services/api/nativeServer'
import { useConfigStore } from '@/stores/config'

/**
 * True for the origins the native shell uses to serve the bundled SPA.
 * iOS is `capacitor://localhost`, Android is `https://localhost` with no port.
 * The Vite dev server (`http://localhost:5173`) is not one of these.
 */
export function isWebViewOrigin(origin: string): boolean {
  if (origin.startsWith('capacitor://') || origin.startsWith('ionic://')) {
    return true
  }
  try {
    const url = new URL(origin)
    return 'https:' === url.protocol && 'localhost' === url.hostname && '' === url.port
  } catch {
    return false
  }
}

/**
 * The server the user picked in the app (shell API, then the bootstrap global).
 * Empty when this page is not running inside the shell.
 */
function configuredServiceOrigin(): string {
  const fromShell = getNativeServerUrl().trim()
  if ('' !== fromShell && !isWebViewOrigin(fromShell)) {
    return fromShell.replace(/\/$/, '')
  }
  const fromBootstrap = getNativeApiBaseUrl().trim()
  if ('' !== fromBootstrap && !isWebViewOrigin(fromBootstrap)) {
    return fromBootstrap.replace(/\/$/, '')
  }
  return ''
}

/**
 * Public origin for links that leave the app (share URLs, absolute media).
 *
 * Never use the WebView origin (`capacitor://localhost` / `https://localhost`):
 * a recipient cannot open it. Inside the shell the link uses the configured
 * service, even when the shell was not recognised via its User-Agent.
 */
export function publicAppOrigin(): string {
  const fromConfig = useConfigStore().appBaseUrl.replace(/\/$/, '')
  const pageOrigin = 'undefined' === typeof window ? '' : window.location.origin
  if (!isWebViewOrigin(pageOrigin) && !isWebViewOrigin(fromConfig)) {
    return fromConfig
  }
  const configured = configuredServiceOrigin()
  return '' !== configured ? configured : fromConfig
}

/**
 * Normalize media URLs to absolute URLs
 * Converts relative paths to absolute URLs using appBaseUrl
 */
export function normalizeMediaUrl(url: string | undefined | null): string {
  if (!url) return ''

  // Already absolute or data URL
  if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) {
    return url
  }

  // Add leading slash if missing
  const normalizedPath = url.startsWith('/') ? url : `/${url}`

  return `${publicAppOrigin()}${normalizedPath}`
}

/**
 * HTTPS URL a recipient can open for a publicly shared chat.
 *
 * Path is the SPA/crawler page (`/shared/{token}`), not the JSON API route.
 */
export function buildChatShareUrl(shareToken: string): string {
  return `${publicAppOrigin()}/shared/${shareToken}`
}
