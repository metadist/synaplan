/** First week after the account was created. After that the intro prompt goes away. */
export const ACCOUNT_INTRO_WINDOW_MS = 7 * 24 * 60 * 60 * 1000

export type EmptyLandingActionsInput = {
  incognito: boolean
  hasPinnedAssistant: boolean
  starterPromptCount: number
  canCompose: boolean
  needsProviderSetup: boolean
  /** Guests have no account, so the intro still applies until they sign up. */
  hasAccount: boolean
  /** `BCREATED` from /auth/me. Null when the session has no account timestamp. */
  accountCreatedAt: string | null
  nowMs: number
}

/**
 * "What can you do" is the first-week action when the composer can submit
 * and the assistant (if any) did not bring its own starter prompts.
 * A week after the account was created, the person has had time to learn
 * what is possible, so the prompt stays off.
 */
export function shouldShowSelfAwareEmptyHint(input: EmptyLandingActionsInput): boolean {
  if (input.incognito || !input.canCompose || input.needsProviderSetup) {
    return false
  }
  if (input.hasPinnedAssistant && input.starterPromptCount > 0) {
    return false
  }
  return isAccountInIntroWindow(input.hasAccount, input.accountCreatedAt, input.nowMs)
}

export function isAccountInIntroWindow(
  hasAccount: boolean,
  accountCreatedAt: string | null,
  nowMs: number
): boolean {
  if (!hasAccount) {
    return true
  }
  const createdMs = parseAccountCreatedAt(accountCreatedAt)
  if (createdMs == null) {
    return false
  }
  const ageMs = nowMs - createdMs
  if (ageMs < 0) {
    return true
  }
  return ageMs < ACCOUNT_INTRO_WINDOW_MS
}

/**
 * `BUSER.BCREATED` is stored without a timezone (`Y-m-d H:i:s`, `Y-m-dTH:i:s`,
 * or compact `YmdHis`). Read it as UTC so the week does not shift with the
 * browser's zone.
 */
export function parseAccountCreatedAt(value: string | null | undefined): number | null {
  if (value == null) {
    return null
  }
  const trimmed = value.trim()
  const separated = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(trimmed)
  const match = separated ?? /^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/.exec(trimmed)
  if (!match) {
    return null
  }
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  const hour = Number(match[4])
  const minute = Number(match[5])
  const second = Number(match[6])
  if (month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || second > 59) {
    return null
  }
  const ms = Date.UTC(year, month - 1, day, hour, minute, second)
  const check = new Date(ms)
  if (
    check.getUTCFullYear() !== year ||
    check.getUTCMonth() !== month - 1 ||
    check.getUTCDate() !== day
  ) {
    return null
  }
  return ms
}
