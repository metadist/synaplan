/**
 * Authenticated media access for direct-URL surfaces (`<img>`, `<audio>`,
 * `<video>`) and fetch-based media downloads.
 *
 * On the web build media requests are same-origin, so the HttpOnly session
 * cookie authenticates them and URL handling here is a transparent no-op —
 * web behavior is unchanged apart from the shared 401 recovery below.
 *
 * MOBILE-APP SEAM (Epic 7): the native shell runs cross-origin
 * (`capacitor://localhost`) without cookies and authenticates with Bearer
 * tokens, which media elements cannot send as a header. On native we reuse
 * the two transports the backend already supports:
 *
 *  - `authenticatedMediaSrc()` appends the access token as a `?token=` query
 *    param for URLs assigned directly to media elements. This is validated by
 *    the backend's `QueryTokenAuthenticator`, which is wired into the `^/api`
 *    firewall.
 *  - `fetchMediaBlob()` attaches `Authorization: Bearer` for JS-driven
 *    loading and downloading.
 *
 * The token is only ever attached to URLs that target the Synaplan backend,
 * never to external hosts (e.g. provider CDN image URLs).
 *
 * Access tokens live 5 minutes (`TokenService::ACCESS_TOKEN_TTL`), far less
 * than a chat stays open, so both transports have to cope with an expired
 * credential: `fetchMediaBlob()` refreshes and retries once, and
 * `useMediaSrc()` gives components a fresh token before they render a URL plus
 * a `reloadMedia()` for their error handlers. Without that, everything older
 * than five minutes — scrolling back, reopening a chat, resuming from the
 * background — renders a permanently broken element.
 */
import { ref } from 'vue'
import { getNativeAccessToken } from '@/services/api/nativeAuth'
import { getNativeApiBaseUrl, isNativeApp } from '@/services/api/nativeRuntime'
import { saveOrDownloadBlob } from '@/services/api/nativeDownload'
import { refreshAccessToken } from '@/services/api/httpClient'

/** Legacy per-message share route, served outside the token-aware firewall. */
const LEGACY_SHARE_PREFIX = '/up/'
const UPLOADS_PREFIX = '/api/v1/files/uploads/'

/**
 * `/up/{path}` is handled by the `main` firewall, which has no Bearer and no
 * query-token authenticator — the native shell can never authenticate it.
 * `StaticUploadController` resolves the very same relative path (its lookup
 * matches the raw path as well as the prefixed one) and lives under `^/api`,
 * where the token is accepted.
 *
 * Only rewritten while we hold a token: the two routes gate on different share
 * flags (`Message::isPublic()` vs the chat's), so an anonymous viewer of a
 * shared message must keep the original route.
 */
function rewriteLegacySharePath(path: string): string {
  if (!path.startsWith(LEGACY_SHARE_PREFIX) || !getNativeAccessToken()) {
    return path
  }
  return `${UPLOADS_PREFIX}${path.slice(LEGACY_SHARE_PREFIX.length)}`
}

/**
 * Turn a media path into something a WebView can actually request.
 *
 * The single place that absolutizes media URLs. On web a root-relative path is
 * already correct (same origin) and is returned untouched. In the native shell
 * the page origin is `capacitor://localhost`, so a relative path would resolve
 * against the app bundle instead of the backend — it gets the configured
 * server prefixed, and the legacy share route rewritten.
 */
export function resolveMediaUrl(url: string | null | undefined): string {
  const raw = (url ?? '').trim()
  if ('' === raw || raw.startsWith('data:') || raw.startsWith('blob:')) {
    return raw
  }
  if (!raw.startsWith('/')) {
    return raw
  }
  if (!isNativeApp()) {
    return raw
  }
  return `${getNativeApiBaseUrl()}${rewriteLegacySharePath(raw)}`
}

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
  return isNativeApp() && isBackendMediaUrl(resolveMediaUrl(url))
}

/**
 * Make a media URL loadable by a bare `<img>`/`<audio>`/`<video>` element.
 *
 * Web: returns the URL unchanged (cookie auth). Native: resolves it against
 * the configured server and appends the Bearer access token as `?token=` for
 * backend URLs so the element's request authenticates without headers.
 *
 * Not reactive on purpose — see `useMediaSrc()` for the component-facing API.
 */
export function authenticatedMediaSrc(url: string | null | undefined): string {
  const resolved = resolveMediaUrl(url)
  if ('' === resolved || !isNativeApp() || !isBackendMediaUrl(resolved)) {
    return resolved
  }
  const token = getNativeAccessToken()
  if (!token) {
    return resolved
  }
  const separator = resolved.includes('?') ? '&' : '?'
  return `${resolved}${separator}token=${encodeURIComponent(token)}`
}

/** Refresh this long before the token actually lapses. */
const TOKEN_EXPIRY_SKEW_MS = 30_000

/**
 * Read the `exp` claim (unix seconds) out of an access token.
 *
 * Tokens are `base64(jsonPayload).hmacSignature` (`TokenService::encodeToken`).
 * We only *read* the claim to decide when to refresh ahead of time — the
 * signature is verified server-side on every request, so a token with a
 * tampered `exp` costs an unnecessary refresh at worst.
 */
function accessTokenExpiryMs(token: string): number | null {
  const payload = token.split('.')[0]
  if (!payload) {
    return null
  }
  try {
    const claims = JSON.parse(atob(payload)) as { exp?: unknown }
    return 'number' === typeof claims.exp ? claims.exp * 1000 : null
  } catch {
    return null
  }
}

/**
 * Refresh the access token when it is about to expire, so a URL built right
 * after this resolves carries a credential that survives the request.
 *
 * Native only: the web build has no readable expiry for its HttpOnly cookie
 * and recovers from a media 401 reactively instead (`fetchMediaBlob`,
 * `reloadMedia`). An unreadable token is left alone for the same reason.
 */
export async function ensureFreshMediaToken(): Promise<void> {
  if (!isNativeApp()) {
    return
  }
  const token = getNativeAccessToken()
  if (!token) {
    return
  }
  const expiresAt = accessTokenExpiryMs(token)
  if (null === expiresAt || Date.now() < expiresAt - TOKEN_EXPIRY_SKEW_MS) {
    return
  }
  await forceMediaTokenRefresh()
}

/**
 * Refresh unconditionally, for the "the element told us the URL was rejected"
 * path. Runs on web too, where the access-token cookie has the same 5-minute
 * lifetime. A failed refresh is swallowed: the caller retries with whatever
 * credential is left and surfaces its own error state.
 */
async function forceMediaTokenRefresh(): Promise<void> {
  try {
    await refreshAccessToken()
  } catch {
    // Keep the current credential; the caller handles the failure.
  }
}

/**
 * Component-facing media URLs.
 *
 * `mediaSrc()` is reactive but deliberately *stable*: it only re-evaluates
 * after the initial token check resolves and when `reloadMedia()` is called.
 * Tracking every token rotation instead would rewrite the `src` of every media
 * element in the chat about once every five minutes, restarting playback.
 */
export function useMediaSrc(): {
  mediaSrc: (url: string | null | undefined) => string
  reloadMedia: () => Promise<void>
} {
  const revision = ref(0)
  const cacheBuster = ref(0)

  const mediaSrc = (url: string | null | undefined): string => {
    // Read both refs so the calling computed/render re-runs on reload.
    void revision.value
    const base = authenticatedMediaSrc(url)
    if ('' === base || 0 === cacheBuster.value) {
      return base
    }
    const separator = base.includes('?') ? '&' : '?'
    return `${base}${separator}_retry=${cacheBuster.value}`
  }

  const reloadMedia = async (): Promise<void> => {
    await forceMediaTokenRefresh()
    cacheBuster.value = Date.now()
    revision.value++
  }

  // Render immediately with the cached token, then re-render once we know it
  // is fresh. When nothing changed the rebuilt URL is identical and Vue skips
  // the update, so the common case costs no extra request.
  void ensureFreshMediaToken().then(() => {
    revision.value++
  })

  return { mediaSrc, reloadMedia }
}

/**
 * Fetch a media URL as a Blob with the right auth transport per platform:
 * session cookie on web, `Authorization: Bearer` (cookies omitted) on native.
 *
 * A rejected credential is refreshed and retried once — the access token
 * outlives neither an idle chat nor a backgrounded app.
 */
export async function fetchMediaBlob(url: string): Promise<Blob> {
  const target = resolveMediaUrl(url)
  await ensureFreshMediaToken()

  let response = await requestMedia(target)

  if (401 === response.status || 403 === response.status) {
    await forceMediaTokenRefresh()
    // Mirror httpClient: give a refreshed cookie a moment to land before the
    // retry, otherwise the second request can race it into another 401.
    await new Promise((resolve) => setTimeout(resolve, 100))
    response = await requestMedia(target)
  }

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${response.statusText}`)
  }

  return response.blob()
}

async function requestMedia(url: string): Promise<Response> {
  const headers: Record<string, string> = {}
  if (needsAuthenticatedMediaFetch(url)) {
    const token = getNativeAccessToken()
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    }
  }

  return fetch(url, {
    method: 'GET',
    credentials: isNativeApp() ? 'omit' : 'include',
    headers,
  })
}

/**
 * Download a media URL under `filename`: authenticated blob fetch, then the
 * platform-appropriate save (web anchor download / native Filesystem + Share).
 */
export async function downloadMediaUrl(url: string, filename: string): Promise<void> {
  const blob = await fetchMediaBlob(url)
  await saveOrDownloadBlob(blob, filename)
}
