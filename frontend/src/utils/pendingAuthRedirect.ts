/**
 * Session-scoped helpers that survive a login round-trip — in particular
 * an OAuth provider redirect that destroys all SPA state (and therefore the
 * `?redirect=…` query param on `/login`).
 *
 * Set by `LoginView.handleSocialLogin` and `AddinConnectView.bootstrap` just
 * before they trigger a navigation that may bounce through a third-party
 * provider. Consumed by `OAuthCallback` (and as a fallback by
 * `LoginView.handleLogin`) once the user lands back on Synaplan.
 *
 * Security:
 *   - Only same-origin relative paths (starting with `/`) are accepted,
 *     to prevent an open-redirect from a tampered `?redirect=`.
 *   - Capped at 2 KiB to make abuse uninteresting.
 *   - 10-minute TTL so a stale entry from a previous session can't hijack
 *     the next login.
 *   - One-shot: consume clears the entry.
 *   - sessionStorage failures (private mode, SSR, quota) silently no-op
 *     rather than throw, because login must keep working regardless.
 */

const PENDING_REDIRECT_KEY = 'synaplan.pendingAuthRedirect'
const TTL_MS = 10 * 60 * 1000
const MAX_PATH_LENGTH = 2048

interface PendingRedirect {
  path: string
  expiresAt: number
}

function safeRemove(key: string): void {
  try {
    sessionStorage.removeItem(key)
  } catch {
    // ignore — sessionStorage may be unavailable (private mode, SSR).
  }
}

function safeGet(key: string): PendingRedirect | null {
  try {
    const raw = sessionStorage.getItem(key)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<PendingRedirect>
    if (typeof parsed.path !== 'string' || typeof parsed.expiresAt !== 'number') {
      // Wrong shape — drop it so we don't keep re-parsing the same garbage.
      safeRemove(key)
      return null
    }
    if (parsed.expiresAt < Date.now()) {
      safeRemove(key)
      return null
    }
    // Defence in depth: re-validate the path on read. A tampered or stale
    // entry with the right shape but an unsafe path (protocol-relative,
    // schemed, oversized, etc.) must not be returned to callers, who use
    // the result for `router.push` without further checks.
    if (!isSafeRedirectPath(parsed.path)) {
      safeRemove(key)
      return null
    }
    return { path: parsed.path, expiresAt: parsed.expiresAt }
  } catch {
    // Malformed JSON or sessionStorage throwing on read — self-heal by
    // dropping the entry so subsequent calls don't keep failing.
    safeRemove(key)
    return null
  }
}

function safeSet(key: string, value: PendingRedirect): void {
  try {
    sessionStorage.setItem(key, JSON.stringify(value))
  } catch {
    // sessionStorage may be unavailable (private mode, SSR, quota).
  }
}

/**
 * Reject anything that isn't a single-leading-slash same-origin path.
 * Specifically rejects:
 *   - empty / non-string inputs
 *   - protocol-relative URLs (`//evil.example`)
 *   - schemed URLs (`https://…`, `javascript:…`, `data:…`)
 *   - paths containing a backslash (some browsers normalise `\` → `/`,
 *     which can be used to smuggle a host through path-only validation)
 *   - over-long payloads
 */
export function isSafeRedirectPath(path: unknown): path is string {
  if (typeof path !== 'string') return false
  if (path.length === 0 || path.length > MAX_PATH_LENGTH) return false
  if (!path.startsWith('/')) return false
  if (path.startsWith('//')) return false
  if (path.includes('\\')) return false
  return true
}

/**
 * Persist the post-login redirect target. Silently ignores unsafe inputs;
 * the caller doesn't need to validate first.
 */
export function setPendingRedirect(path: string): void {
  if (!isSafeRedirectPath(path)) return
  safeSet(PENDING_REDIRECT_KEY, { path, expiresAt: Date.now() + TTL_MS })
}

/**
 * Read the pending redirect WITHOUT clearing it. Useful for previewing
 * (e.g. unit tests, debug logs); production code should prefer
 * {@link consumePendingRedirect} to avoid double-application.
 */
export function peekPendingRedirect(): string | null {
  return safeGet(PENDING_REDIRECT_KEY)?.path ?? null
}

/**
 * Read and clear the pending redirect in one shot. Returns `null` if there
 * isn't one (or it expired, or sessionStorage is unavailable).
 */
export function consumePendingRedirect(): string | null {
  const v = safeGet(PENDING_REDIRECT_KEY)
  if (!v) return null
  safeRemove(PENDING_REDIRECT_KEY)
  return v.path
}

/**
 * Drop any pending redirect (e.g. on explicit sign-out or on a navigation
 * that supersedes the pending intent).
 */
export function clearPendingRedirect(): void {
  safeRemove(PENDING_REDIRECT_KEY)
}
