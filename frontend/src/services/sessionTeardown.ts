/**
 * Session Teardown - marks the window in which the client is giving up its
 * session but the SPA is still alive.
 *
 * Logout is not instant: it awaits POST /api/v1/auth/logout, wipes the
 * user-scoped stores, disconnects realtime, and only then hands the browser
 * over to the next page - for OIDC a full-page navigation to the provider's
 * end-session endpoint, which lands on /logged-out.
 *
 * Throughout that window there is no user in memory while requests started
 * earlier are still resolving. Any code that reacts to "no user" by assigning
 * `window.location.href` replaces the pending logout navigation instead of
 * following it: the end-session request dies as `net::ERR_ABORTED`, the user
 * lands on /login, and the provider session stays open - the user looks
 * logged out but is not. Store actions firing in the same window also still
 * write to the API (a chat created after the logout click).
 *
 * So the rule is: while a teardown is in progress, an unauthenticated state is
 * expected, not an error. Do not navigate, do not call protected endpoints -
 * let the logout reach its own destination.
 *
 * Set: when a logout starts through the auth store, silent ones included.
 * authService's own silent logouts (a refresh that came back 401) stay outside
 * this: they never request an end-session URL, so there is no navigation to
 * protect and the ordinary redirect to /login has to keep working.
 * Cleared: as soon as a principal is signed in again.
 */

let terminating = false

/** A logout has started; the destination navigation is not committed yet. */
export function beginSessionTeardown(): void {
  terminating = true
}

/** A principal is signed in again; ordinary auth handling applies. */
export function endSessionTeardown(): void {
  terminating = false
}

export function isSessionTerminating(): boolean {
  return terminating
}
