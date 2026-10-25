import { ApiError } from '@/services/api/httpClient'

/**
 * The server answered and refused, so nothing was written. No answer, a 5xx
 * or an unreadable answer may come after the write committed.
 */
export function isDefiniteRefusal(error: unknown): boolean {
  return error instanceof ApiError && error.status >= 400 && error.status < 500
}
