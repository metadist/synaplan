import { useConfigStore } from '@/stores/config'

/**
 * Public origin for links that leave the app (share URLs, absolute media).
 *
 * Never use `window.location.origin` for these: in the native shell that is
 * `capacitor://localhost` / `https://localhost`, which is not a reachable
 * platform URL. `appBaseUrl` already remaps the native case to the real
 * backend/web origin (Epic 3).
 */
function publicAppBaseUrl(): string {
  return useConfigStore().appBaseUrl.replace(/\/$/, '')
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

  return `${publicAppBaseUrl()}${normalizedPath}`
}

/**
 * HTTPS URL a recipient can open for a publicly shared chat.
 *
 * Path is the SPA/crawler page (`/shared/{token}`), not the JSON API route.
 */
export function buildChatShareUrl(shareToken: string): string {
  return `${publicAppBaseUrl()}/shared/${shareToken}`
}
