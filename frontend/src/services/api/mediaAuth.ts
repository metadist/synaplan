/**
 * Authenticated media access for direct-URL surfaces (`<img>`, `<audio>`,
 * `<video>`) and fetch-based media downloads.
 *
 * On the web build media requests are same-origin, so the HttpOnly session
 * cookie authenticates them and every helper here is a transparent no-op —
 * web behavior is unchanged.
 *
 * MOBILE-APP SEAM (Epic 7): the native shell runs cross-origin
 * (`capacitor://localhost`) without cookies and authenticates with Bearer
 * tokens, which media elements cannot send as a header. On native we reuse
 * the two transports the backend already supports:
 *
 *  - `authenticatedMediaSrc()` appends the access token as a `?token=` query
 *    param for URLs assigned directly to media elements. This is validated by
 *    the backend's `QueryTokenAuthenticator` — the same mechanism SSE uses
 *    (see `chatApi.ts`), because `EventSource` cannot set headers either.
 *  - `fetchMediaBlob()` attaches `Authorization: Bearer` for JS-driven
 *    loading and downloading.
 *
 * The token is only ever attached to URLs that target the Synaplan backend,
 * never to external hosts (e.g. provider CDN image URLs).
 */
import { getNativeAccessToken } from '@/services/api/nativeAuth'
import { getNativeApiBaseUrl, isNativeApp } from '@/services/api/nativeRuntime'
import { saveOrDownloadBlob } from '@/services/api/nativeDownload'

/** True when the URL targets the Synaplan backend (relative, or the native API host). */
function isBackendMediaUrl(url: string): boolean {
  if (url.startsWith('/')) {
    return true
  }
  return isNativeApp() && url.startsWith(`${getNativeApiBaseUrl()}/`)
}

/**
 * True when a media URL cannot simply be assigned to an element/fetched with
 * cookies but needs the Bearer-authenticated `fetchMediaBlob()` path.
 * Always false on web.
 */
export function needsAuthenticatedMediaFetch(url: string): boolean {
  return isNativeApp() && isBackendMediaUrl(url)
}

/**
 * Make a media URL loadable by a bare `<img>`/`<audio>`/`<video>` element.
 *
 * Web: returns the URL unchanged (cookie auth). Native: appends the Bearer
 * access token as `?token=` for backend URLs so the element's request
 * authenticates without headers.
 */
export function authenticatedMediaSrc(url: string): string {
  if (!url || !isNativeApp() || !isBackendMediaUrl(url)) {
    return url
  }
  const token = getNativeAccessToken()
  if (!token) {
    return url
  }
  const separator = url.includes('?') ? '&' : '?'
  return `${url}${separator}token=${encodeURIComponent(token)}`
}

/**
 * Fetch a media URL as a Blob with the right auth transport per platform:
 * session cookie on web, `Authorization: Bearer` (cookies omitted) on native.
 */
export async function fetchMediaBlob(url: string): Promise<Blob> {
  const headers: Record<string, string> = {}
  if (needsAuthenticatedMediaFetch(url)) {
    const token = getNativeAccessToken()
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    }
  }

  const response = await fetch(url, {
    method: 'GET',
    credentials: isNativeApp() ? 'omit' : 'include',
    headers,
  })

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`)
  }

  return response.blob()
}

/**
 * Download a media URL under `filename`: authenticated blob fetch, then the
 * platform-appropriate save (web anchor download / native Filesystem + Share).
 */
export async function downloadMediaUrl(url: string, filename: string): Promise<void> {
  const blob = await fetchMediaBlob(url)
  await saveOrDownloadBlob(blob, filename)
}
