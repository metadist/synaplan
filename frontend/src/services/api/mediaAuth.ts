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
 * tokens, which media elements cannot send as a header. On native we use two
 * transports:
 *
 *  - `authenticatedMediaSrc()` appends a credential as a query param for URLs
 *    assigned directly to media elements. Preferred is the purpose-scoped
 *    `media_token` from `GET /api/v1/files/media-token`: read-only, media-only,
 *    30 minutes, so a URL that leaks out of the WebView cannot be replayed
 *    against the rest of the API. A server that predates that endpoint answers
 *    404, and we fall back to the session access token as `?token=`, which the
 *    `QueryTokenAuthenticator` accepts on the `^/api` firewall.
 *  - `fetchMediaBlob()` attaches `Authorization: Bearer` for JS-driven
 *    downloading, where no URL is exposed and the full session token is fine.
 *
 * A credential is only ever attached to URLs that target the Synaplan backend,
 * never to external hosts (e.g. provider CDN image URLs).
 *
 * Either credential outlives far less than an open chat — the session token
 * lasts 5 minutes (`TokenService::ACCESS_TOKEN_TTL`), the media token 30
 * (`MediaAccessTokenService::TTL`) — so both transports have to cope with an
 * expired one: `fetchMediaBlob()` refreshes and retries once, and
 * `useMediaSrc()` mints a fresh credential before components render a URL plus
 * a `reloadMedia()` for their error handlers. Without that, everything older
 * than the TTL — scrolling back, reopening a chat, resuming from the
 * background — renders a permanently broken element.
 */
import { ref } from 'vue'
import { GetApiFilesMediaTokenResponseSchema } from '@/generated/api-schemas'
import { getNativeAccessToken } from '@/services/api/nativeAuth'
import { getNativeApiBaseUrl, isNativeApp } from '@/services/api/nativeRuntime'
import { saveOrDownloadBlob } from '@/services/api/nativeDownload'
import { ApiError, httpClient, refreshAccessToken } from '@/services/api/httpClient'

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

/** Query parameter checked by `MediaAccessTokenService` (media-only). */
const MEDIA_TOKEN_PARAM = 'media_token'
/** Query parameter checked by `QueryTokenAuthenticator` (full session token). */
const SESSION_TOKEN_PARAM = 'token'

/**
 * Pick the credential to put in a media URL: the purpose-scoped media token
 * when we hold one, otherwise the session token so a server without the
 * media-token endpoint keeps working.
 */
function mediaUrlCredential(): { param: string; value: string } | null {
  if (null !== mediaToken && Date.now() < mediaToken.expiresAt) {
    return { param: MEDIA_TOKEN_PARAM, value: mediaToken.value }
  }
  const session = getNativeAccessToken()
  return session ? { param: SESSION_TOKEN_PARAM, value: session } : null
}

/**
 * Make a media URL loadable by a bare `<img>`/`<audio>`/`<video>` element.
 *
 * Web: returns the URL unchanged (cookie auth). Native: resolves it against
 * the configured server and appends a credential as a query param for backend
 * URLs, so the element's request authenticates without headers.
 *
 * Not reactive on purpose — see `useMediaSrc()` for the component-facing API.
 */
export function authenticatedMediaSrc(url: string | null | undefined): string {
  const resolved = resolveMediaUrl(url)
  if ('' === resolved || !isNativeApp() || !isBackendMediaUrl(resolved)) {
    return resolved
  }
  const credential = mediaUrlCredential()
  if (null === credential) {
    return resolved
  }
  const separator = resolved.includes('?') ? '&' : '?'
  return `${resolved}${separator}${credential.param}=${encodeURIComponent(credential.value)}`
}

/** Refresh this long before the token actually lapses. */
const TOKEN_EXPIRY_SKEW_MS = 30_000

const MEDIA_TOKEN_ENDPOINT = '/api/v1/files/media-token'

let mediaToken: { value: string; expiresAt: number } | null = null
let mediaTokenRequest: Promise<void> | null = null

/**
 * Servers older than the media-token endpoint answer 404. Remembered per API
 * base URL so switching to a newer server re-probes without costing a 404 on
 * every media load in between.
 */
let mediaTokenUnsupportedFor: string | null = null

/**
 * Mint a media token, or give up quietly.
 *
 * Any failure is non-fatal: `mediaUrlCredential()` then falls back to the
 * session token, which every supported server accepts. Concurrent callers
 * share one request so opening a chat full of images mints a single token.
 */
async function requestMediaToken(): Promise<void> {
  const server = getNativeApiBaseUrl()
  if (mediaTokenUnsupportedFor === server) {
    return
  }
  mediaTokenRequest ??= (async () => {
    try {
      const { token, expiresIn } = await httpClient(MEDIA_TOKEN_ENDPOINT, {
        schema: GetApiFilesMediaTokenResponseSchema,
      })
      mediaToken = { value: token, expiresAt: Date.now() + expiresIn * 1000 }
    } catch (error) {
      if (error instanceof ApiError && 404 === error.status) {
        mediaTokenUnsupportedFor = server
      }
      mediaToken = null
    } finally {
      mediaTokenRequest = null
    }
  })()

  return mediaTokenRequest
}

/** Drop the cached media token so the next `ensureMediaCredential()` mints one. */
function invalidateMediaToken(): void {
  mediaToken = null
}

/**
 * Testing seam: reset the module-level credential cache.
 *
 * @internal
 */
export function __resetMediaCredentialCache(): void {
  mediaToken = null
  mediaTokenRequest = null
  mediaTokenUnsupportedFor = null
}

/**
 * Make sure the next URL built by `authenticatedMediaSrc()` carries a
 * credential that survives the request.
 *
 * Native only — the web build authenticates media with its HttpOnly cookie.
 * Prefers minting a media token; if the server does not offer one, it keeps
 * the session token fresh instead.
 */
export async function ensureMediaCredential(): Promise<void> {
  if (!isNativeApp() || !getNativeAccessToken()) {
    return
  }
  if (null === mediaToken || Date.now() >= mediaToken.expiresAt - TOKEN_EXPIRY_SKEW_MS) {
    // The media token is minted with the session token, so that has to be
    // valid first — and it is the fallback credential when minting is
    // unsupported.
    await ensureFreshSessionToken()
    await requestMediaToken()
  }
}

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
 * Refresh the session access token when it is about to expire, so anything
 * built right after this resolves carries a credential that survives the
 * request.
 *
 * Native only: the web build has no readable expiry for its HttpOnly cookie
 * and recovers from a media 401 reactively instead (`fetchMediaBlob`,
 * `reloadMedia`). An unreadable token is left alone for the same reason.
 */
async function ensureFreshSessionToken(): Promise<void> {
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
  await forceSessionTokenRefresh()
}

/**
 * Refresh unconditionally, for the "the element told us the URL was rejected"
 * path. Runs on web too, where the access-token cookie has the same 5-minute
 * lifetime. A failed refresh is swallowed: the caller retries with whatever
 * credential is left and surfaces its own error state.
 */
async function forceSessionTokenRefresh(): Promise<void> {
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
 * after the initial credential check resolves and when `reloadMedia()` is
 * called. Tracking every token rotation instead would rewrite the `src` of
 * every media element in the chat every few minutes, restarting playback.
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
    // The element rejected the URL, so treat both credentials as spent: mint a
    // new media token off a refreshed session, and bust the cache so the
    // element actually re-requests instead of reusing its failed response.
    invalidateMediaToken()
    await forceSessionTokenRefresh()
    await ensureMediaCredential()
    cacheBuster.value = Date.now()
    revision.value++
  }

  // Render immediately with the cached credential, then re-render once we know
  // it is fresh. When nothing changed the rebuilt URL is identical and Vue
  // skips the update, so the common case costs no extra request.
  void ensureMediaCredential().then(() => {
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
  await ensureFreshSessionToken()

  let response = await requestMedia(target)

  if (401 === response.status || 403 === response.status) {
    await forceSessionTokenRefresh()
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
