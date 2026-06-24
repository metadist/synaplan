/**
 * Native (Capacitor) auth-token storage.
 *
 * The web build authenticates via HttpOnly cookies and NEVER touches this
 * module. The native shell runs cross-origin (`capacitor://localhost`) where
 * cookies are unreliable, so it consumes the Bearer access/refresh tokens the
 * backend returns in the login/refresh JSON body (Epic 3) and replays the
 * access token as `Authorization: Bearer`.
 *
 * STORAGE (Epic 7 hardening): tokens are persisted in platform secure storage
 * — iOS Keychain / Android Keystore-backed (AES-GCM) storage via
 * `@aparajita/capacitor-secure-storage`, NOT plain localStorage/preferences.
 *
 * Because secure storage is asynchronous but the HTTP layer attaches the Bearer
 * header synchronously, we keep an in-memory cache as the synchronous source of
 * truth and treat secure storage as the durable backing store:
 *   - `loadNativeTokens()` hydrates the cache once at app boot.
 *   - reads (`getNative*`/`hasNativeTokens`) are synchronous against the cache.
 *   - writes (`setNativeTokens`/`clearNativeTokens`) update the cache
 *     immediately and persist to secure storage best-effort in the background.
 */
import { SecureStorage } from '@aparajita/capacitor-secure-storage'
import { getNativeApiBaseUrl, isNativeApp } from '@/services/api/nativeRuntime'

/**
 * MOBILE-APP SEAM (Epic 3 §3.0): per-server identity.
 *
 * The native shell can point the app at any Synaplan server (the in-app server
 * switcher lives in `synaplan-apps`). Tokens are stored keyed by the resolved
 * server URL so server A's Bearer token is NEVER read for server B, and
 * switching back to A restores A's session. The server is fixed for the
 * lifetime of a WebView load (changing it reloads the SPA), so the scope is
 * stable here. Default/web resolves to the production server's scope.
 */
function serverScope(): string {
  // djb2 — tiny, synchronous, stable. Not for security, just key namespacing.
  const url = getNativeApiBaseUrl()
  let hash = 5381
  for (let i = 0; i < url.length; i++) {
    hash = ((hash << 5) + hash + url.charCodeAt(i)) | 0
  }
  return (hash >>> 0).toString(36)
}

const ACCESS_TOKEN_KEY = `syn_native_at_${serverScope()}`
const REFRESH_TOKEN_KEY = `syn_native_rt_${serverScope()}`

export interface NativeTokens {
  accessToken: string
  refreshToken: string
}

/**
 * Shape of the `tokens` object the backend adds to the login/refresh body for
 * native clients. Extra fields (tokenType, expiresIn) are ignored here.
 */
export interface NativeTokenPayload {
  accessToken?: unknown
  refreshToken?: unknown
}

// Synchronous in-memory cache (see module docblock).
let accessToken: string | null = null
let refreshToken: string | null = null

/**
 * Hydrate the in-memory cache from secure storage. Must be awaited once at app
 * boot (main.ts) before the first authenticated request so a restarted app
 * restores its session. No-op on web.
 */
export async function loadNativeTokens(): Promise<void> {
  if (!isNativeApp()) {
    return
  }
  accessToken = await readSecure(ACCESS_TOKEN_KEY)
  refreshToken = await readSecure(REFRESH_TOKEN_KEY)
}

/** Persist the access (and optionally refresh) token from a login/refresh body. */
export function setNativeTokens(payload: NativeTokenPayload | null | undefined): void {
  if (!payload) {
    return
  }

  if ('string' === typeof payload.accessToken && '' !== payload.accessToken) {
    accessToken = payload.accessToken
    void writeSecure(ACCESS_TOKEN_KEY, payload.accessToken)
  }
  // Refresh tokens are only re-issued on full login; a plain access-token
  // refresh keeps the existing one, so only overwrite when present.
  if ('string' === typeof payload.refreshToken && '' !== payload.refreshToken) {
    refreshToken = payload.refreshToken
    void writeSecure(REFRESH_TOKEN_KEY, payload.refreshToken)
  }
}

export function getNativeAccessToken(): string | null {
  return accessToken
}

export function getNativeRefreshToken(): string | null {
  return refreshToken
}

/** True when a Bearer access token is available (native "session hint"). */
export function hasNativeTokens(): boolean {
  return null !== accessToken
}

/** Drop all native tokens (logout / auth failure). */
export function clearNativeTokens(): void {
  accessToken = null
  refreshToken = null
  void deleteSecure(ACCESS_TOKEN_KEY)
  void deleteSecure(REFRESH_TOKEN_KEY)
}

// ── Secure storage helpers (async, native only) ────────────────────────────
// All wrapped so a storage failure degrades to a non-persisted session rather
// than throwing into the auth flow.

async function readSecure(key: string): Promise<string | null> {
  if (!isNativeApp()) {
    return null
  }
  try {
    const value = await SecureStorage.getItem(key)
    return 'string' === typeof value ? value : null
  } catch {
    return null
  }
}

async function writeSecure(key: string, value: string): Promise<void> {
  if (!isNativeApp()) {
    return
  }
  try {
    await SecureStorage.setItem(key, value)
  } catch {
    // Persist best-effort; the in-memory cache still serves this session.
  }
}

async function deleteSecure(key: string): Promise<void> {
  if (!isNativeApp()) {
    return
  }
  try {
    await SecureStorage.removeItem(key)
  } catch {
    // Nothing to clear if storage is unavailable.
  }
}
