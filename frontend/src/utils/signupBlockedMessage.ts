/**
 * Stable server codes for a refused sign-up / sign-in, mapped to translated
 * sentences. The server also sends an English `error` text; views prefer the
 * translation when the code is known and fall back to that text otherwise.
 *
 * Codes come either upper-case from a JSON body (`SIGNUP_RATE_LIMITED`) or
 * lower-case from the OAuth callback redirect (`error_code=signup_rate_limited`).
 */
const KEYS: Record<string, string> = {
  SIGNUP_RATE_LIMITED: 'auth.signupBlocked.rateLimited',
  SIGNUP_PAUSED: 'auth.signupBlocked.paused',
  SIGNUP_CHALLENGE_FAILED: 'auth.signupBlocked.challengeFailed',
  SIGNUP_CHALLENGE_REQUIRED: 'auth.signupBlocked.challengeRequired',
  SIGNUP_ADMISSION_REQUIRED: 'auth.signupBlocked.admissionRequired',
  SIGNUP_CODE_INVALID: 'auth.signupBlocked.codeInvalid',
  SIGNUP_PLAN_INVALID: 'auth.signupBlocked.planInvalid',
}

/**
 * Answers that arrive without a code: the busy body of a full server, and the
 * texts authService reports for a lost connection or an unreadable answer.
 * Shown raw they read as "server_busy" or "Network error".
 */
const TEXT_KEYS: Record<string, string> = {
  server_busy: 'auth.signupBlocked.busy',
  'Network error': 'auth.signupBlocked.network',
  'Registration failed': 'auth.signupBlocked.failed',
}

export function signupBlockedI18nKey(code: string | null | undefined): string | null {
  if (!code) return null
  return KEYS[code.toUpperCase()] ?? null
}

/** The translated sentence for a known code or text, otherwise the server's own text. */
export function signupBlockedText(
  t: (key: string) => string,
  code: string | null | undefined,
  fallback: string | null
): string | null {
  const key = signupBlockedI18nKey(code) ?? (fallback ? TEXT_KEYS[fallback] : undefined)
  return key ? t(key) : fallback
}
