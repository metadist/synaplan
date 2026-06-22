/**
 * Native (Capacitor) auth-token storage.
 *
 * The web build authenticates via HttpOnly cookies and NEVER touches this
 * module. The native shell runs cross-origin (`capacitor://localhost`) where
 * cookies are unreliable, so it consumes the Bearer access/refresh tokens the
 * backend returns in the login/refresh JSON body (Epic 3) and replays the
 * access token as `Authorization: Bearer`.
 *
 * STORAGE: currently `localStorage`, scoped to the WebView's app sandbox. This
 * is a deliberate spike/dev choice. Epic 7 (security hardening) replaces the
 * persistence layer with platform secure storage (iOS Keychain / Android
 * Keystore) behind THIS SAME interface — so keep every token read/write going
 * through these functions to make that swap a single-file change.
 */

const ACCESS_TOKEN_KEY = 'syn_native_at'
const REFRESH_TOKEN_KEY = 'syn_native_rt'

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

/** Persist the access (and optionally refresh) token from a login/refresh body. */
export function setNativeTokens(payload: NativeTokenPayload | null | undefined): void {
  if (!payload) {
    return
  }

  try {
    if ('string' === typeof payload.accessToken && '' !== payload.accessToken) {
      localStorage.setItem(ACCESS_TOKEN_KEY, payload.accessToken)
    }
    // Refresh tokens are only re-issued on full login; a plain access-token
    // refresh keeps the existing one, so only overwrite when present.
    if ('string' === typeof payload.refreshToken && '' !== payload.refreshToken) {
      localStorage.setItem(REFRESH_TOKEN_KEY, payload.refreshToken)
    }
  } catch {
    // Storage unavailable — the session simply won't survive an app restart.
  }
}

export function getNativeAccessToken(): string | null {
  try {
    return localStorage.getItem(ACCESS_TOKEN_KEY)
  } catch {
    return null
  }
}

export function getNativeRefreshToken(): string | null {
  try {
    return localStorage.getItem(REFRESH_TOKEN_KEY)
  } catch {
    return null
  }
}

/** True when a Bearer access token is available (native "session hint"). */
export function hasNativeTokens(): boolean {
  return null !== getNativeAccessToken()
}

/** Drop all native tokens (logout / auth failure). */
export function clearNativeTokens(): void {
  try {
    localStorage.removeItem(ACCESS_TOKEN_KEY)
    localStorage.removeItem(REFRESH_TOKEN_KEY)
  } catch {
    // Nothing to clear if storage is unavailable.
  }
}
