import { ApiError } from '@/services/api/httpClient'

/** 404 or 403 from a per-chat request means that selection is finished. */
export function chatGoneStatus(error: unknown): 403 | 404 | null {
  if (!(error instanceof ApiError)) return null
  if (error.status === 403 || error.status === 404) return error.status
  return null
}
