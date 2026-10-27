import { ApiError } from '@/services/api/httpClient'

/**
 * Text safe to show a person in a toast. Only a 4xx answer from the backend
 * carries a sentence written for users; a server error, a network failure,
 * a schema mismatch or admin debug output falls back to the caller's copy.
 */
export function userFacingError(error: unknown, fallback: string): string {
  if (!(error instanceof ApiError) || error.status < 400 || error.status >= 500 || error.debug) {
    return fallback
  }
  const message = error.message.trim()
  return message !== '' && !message.startsWith('HTTP ') ? message : fallback
}
