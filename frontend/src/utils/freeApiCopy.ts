import { ApiError } from '@/services/api/httpClient'

/** The three ways to make chat work on a fresh install. */
export type FreeApiChoice = 'demo' | 'ollama' | 'key'

/** Start failures with a sentence of their own; every other failure reads `freeApi.startError.unknown`. */
const START_ERRORS: Record<string, string> = {
  freeapi_unreachable: 'unreachable',
  freeapi_chat_ready: 'chatReady',
  freeapi_own_endpoint: 'ownEndpoint',
  freeapi_already_active: 'alreadyActive',
  freeapi_throttled: 'throttled',
  freeapi_full_today: 'fullToday',
  freeapi_paused: 'paused',
  freeapi_blocked: 'blocked',
  freeapi_expired: 'expired',
  freeapi_upgrade_required: 'upgradeRequired',
  freeapi_setup_incomplete: 'setupIncomplete',
}

/** The i18n key that says why the Synaplan Demo was not started. */
export function freeApiStartErrorKey(error: unknown): string {
  const code = error instanceof ApiError ? error.code : undefined
  const key = code !== undefined ? START_ERRORS[code] : undefined

  return `freeApi.startError.${key ?? 'unknown'}`
}
