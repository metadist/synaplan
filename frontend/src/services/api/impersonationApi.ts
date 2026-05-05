/**
 * Admin → user impersonation API client.
 *
 * Cookie-based — relies on `credentials: 'include'` so the backend can both
 * read the current admin tokens (to stash them) and set the new tokens for
 * the target user. There are no headers or body params besides what's encoded
 * in the URL: keep this thin.
 */

import { z } from 'zod'

import {
  PostApiAdminImpersonateStartResponseSchema,
  PostApiAdminImpersonateStopResponseSchema,
} from '@/generated/api-schemas'
import { getApiBaseUrl } from '@/services/api/httpClient'

export type ImpersonationStartResponse = z.infer<typeof PostApiAdminImpersonateStartResponseSchema>
export type ImpersonationStopResponse = z.infer<typeof PostApiAdminImpersonateStopResponseSchema>

export interface ImpersonationApiResult<T> {
  success: boolean
  data?: T
  /** Human-readable error message, ready to surface to the admin. */
  error?: string
  /** HTTP status, useful for distinguishing 403 (rule violation) from 5xx. */
  status?: number
}

async function readJsonError(response: Response): Promise<string | undefined> {
  try {
    const data = (await response.json()) as { error?: unknown; message?: unknown }
    if (typeof data?.error === 'string') return data.error
    if (typeof data?.message === 'string') return data.message
  } catch {
    // Empty body / non-JSON — fall through to a generic message.
  }
  return undefined
}

export const impersonationApi = {
  /**
   * Begin impersonating the given user. The browser will receive fresh session
   * cookies; the caller is expected to refresh the auth store afterwards.
   */
  async start(userId: number): Promise<ImpersonationApiResult<ImpersonationStartResponse>> {
    try {
      const response = await fetch(
        `${getApiBaseUrl()}/api/v1/admin/impersonate/${encodeURIComponent(userId)}`,
        {
          method: 'POST',
          credentials: 'include',
        }
      )

      if (!response.ok) {
        return {
          success: false,
          status: response.status,
          error:
            (await readJsonError(response)) ?? `Impersonation failed (HTTP ${response.status})`,
        }
      }

      const raw = await response.json()
      const data = PostApiAdminImpersonateStartResponseSchema.parse(raw)

      return { success: true, data, status: response.status }
    } catch (err) {
      return {
        success: false,
        error: err instanceof Error ? err.message : 'Network error while starting impersonation',
      }
    }
  },

  /**
   * Stop the active impersonation. Server-side this restores the stashed
   * admin cookies; client-side the caller must refresh auth state.
   */
  async stop(): Promise<ImpersonationApiResult<ImpersonationStopResponse>> {
    try {
      const response = await fetch(`${getApiBaseUrl()}/api/v1/admin/impersonate/exit`, {
        method: 'POST',
        credentials: 'include',
      })

      if (!response.ok) {
        return {
          success: false,
          status: response.status,
          error:
            (await readJsonError(response)) ??
            `Failed to exit impersonation (HTTP ${response.status})`,
        }
      }

      const raw = await response.json()
      const data = PostApiAdminImpersonateStopResponseSchema.parse(raw)

      return { success: true, data, status: response.status }
    } catch (err) {
      return {
        success: false,
        error: err instanceof Error ? err.message : 'Network error while stopping impersonation',
      }
    }
  },
}
