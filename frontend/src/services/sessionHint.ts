/**
 * Session Hint - lightweight client-side flag indicating whether the user
 * has ever successfully authenticated in this browser profile.
 *
 * NOT security-sensitive: the cookie-based session is the source of truth.
 * The hint is purely a UX optimization that prevents this cascade of
 * 401 noise on a fresh visit (incognito / cleared storage):
 *
 *     GET  /api/v1/auth/me        → 401
 *     POST /api/v1/auth/refresh   → 401
 *     "Token refresh failed, logging out"
 *     POST /api/v1/auth/logout    → 401
 *     → forced redirect to /login?reason=session_expired
 *
 * Without the hint, ANY API call that gets a 401 (auth/me, SSE token,
 * any protected endpoint) would unconditionally try to refresh — even
 * for users who have never logged in. With the hint, refresh attempts
 * short-circuit immediately when no prior session is known.
 *
 * Set: on successful login / getCurrentUser / refresh / OAuth callback.
 * Cleared: on logout (silent or not) and on refresh failure.
 *
 * Related: https://github.com/metadist/synaplan/issues/204
 */

const SESSION_HINT_KEY = 'sh'

/**
 * Mark the current browser as having an active (or recently active) session.
 * Subsequent visits will then attempt auth bootstrap; without it we skip
 * the whole refresh dance.
 */
export function setSessionHint(): void {
  try {
    localStorage.setItem(SESSION_HINT_KEY, '1')
  } catch {
    // localStorage unavailable (private mode quotas, disabled storage) -
    // graceful degradation: we accept the extra 401 on next visit rather
    // than fail the login flow.
  }
}

/**
 * Clear the hint. Called on logout and whenever a refresh definitively
 * fails (the cookie is dead — don't pretend the user still has a session).
 */
export function clearSessionHint(): void {
  try {
    localStorage.removeItem(SESSION_HINT_KEY)
  } catch {
    // localStorage unavailable - graceful degradation
  }
}

/**
 * True when this browser has at least one historical successful auth.
 * Returns `true` if localStorage is unavailable, so we never accidentally
 * lock a working session out just because storage is sandboxed.
 */
export function hasSessionHint(): boolean {
  try {
    return localStorage.getItem(SESSION_HINT_KEY) === '1'
  } catch {
    return true
  }
}
