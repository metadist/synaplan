/**
 * Native (Capacitor) runtime detection & configuration.
 *
 * The SPA is built once and shipped to BOTH the web deployment and the native
 * app shell (Capacitor WebView, see the `synaplan-apps` repo). Inside the
 * WebView the page origin is `capacitor://localhost` (iOS) / `https://localhost`
 * (Android), so every same-origin assumption breaks: the backend lives on a
 * different host and cross-origin cookies are unreliable. This module is the
 * single place that answers "are we running inside the native shell, and which
 * backend should we talk to?".
 *
 * Intentionally dependency-free (no `@capacitor/core`): pulling the Capacitor
 * runtime into the shared web bundle would bloat the web build for a detection
 * we can do from the User-Agent that the native shell already appends
 * (Epic 2 contract: `Synaplan Mobile V<major>.<minor>`).
 */

/** Frozen marker the native shell appends to the WebView User-Agent (Epic 2). */
const NATIVE_UA_MARKER = 'Synaplan Mobile'

/**
 * Production backend the native shell talks to (cross-origin).
 *
 * Locked in `synaplan-apps/docs/IDENTIFIERS.md`. The native shell may override
 * this at runtime via `window.__SYNAPLAN_API_BASE_URL__` for staging / dev /
 * spike builds (set before the SPA bundle executes); the override wins so we
 * never have to fork the bundle per environment.
 */
const DEFAULT_NATIVE_API_BASE_URL = 'https://web.synaplan.com'

/**
 * True when the page is running inside the Synaplan native app shell.
 *
 * Detected from the appended User-Agent so it works before any backend round
 * trip (we must know this BEFORE `config.init()` to point the very first
 * request at the right host).
 */
export function isNativeApp(): boolean {
  if ('undefined' === typeof navigator) {
    return false
  }

  return navigator.userAgent.includes(NATIVE_UA_MARKER)
}

/**
 * Resolve the backend base URL for the native shell.
 *
 * Precedence: runtime override (`window.__SYNAPLAN_API_BASE_URL__`) → compiled
 * default (production). Always returned WITHOUT a trailing slash so callers can
 * safely concatenate `/api/...` paths.
 */
export function getNativeApiBaseUrl(): string {
  const override = (globalThis as { __SYNAPLAN_API_BASE_URL__?: unknown }).__SYNAPLAN_API_BASE_URL__

  if ('string' === typeof override && '' !== override.trim()) {
    return override.trim().replace(/\/$/, '')
  }

  return DEFAULT_NATIVE_API_BASE_URL
}
