import type { z } from 'zod'
import {
  GetAdminUpdatesStatusResponseSchema,
  PostAdminUpdatesCheckResponseSchema,
  PostAdminUpdatesDismissResponseSchema,
  PutAdminUpdatesSettingsResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

// Types are inferred from the generated Zod schemas (per AGENTS.md: never
// hand-write interfaces for API responses).
export type UpdateStatus = z.infer<typeof GetAdminUpdatesStatusResponseSchema>

/** Importance of the latest known release. */
export type UpdateSeverity = UpdateStatus['severity']

/** Deployment flavour the backend detected; decides which guide `guideUrl` points at. */
export type UpdatePlatform = UpdateStatus['platform']

const BASE_PATH = '/api/v1/admin/updates'

/**
 * Release-notice API (ROLE_ADMIN on the backend — never call this for a
 * non-admin, it would just 403).
 *
 * Detection and display only: nothing here changes the installation. The
 * update itself is always performed manually by the operator, following the
 * guide at `status.guideUrl`.
 */
export const updatesApi = {
  /** Stored result of the last check — no outbound request, works offline. */
  getStatus: async (): Promise<UpdateStatus> => {
    return httpClient(`${BASE_PATH}/status`, { schema: GetAdminUpdatesStatusResponseSchema })
  },

  /**
   * Run the check now. A network failure is a normal outcome and surfaces in
   * `lastError` rather than as a rejected promise.
   */
  check: async (): Promise<UpdateStatus> => {
    return httpClient(`${BASE_PATH}/check`, {
      method: 'POST',
      schema: PostAdminUpdatesCheckResponseSchema,
    })
  },

  /** Acknowledge a version so the notice stays hidden until a newer one appears. */
  dismiss: async (
    version: string
  ): Promise<z.infer<typeof PostAdminUpdatesDismissResponseSchema>> => {
    return httpClient(`${BASE_PATH}/dismiss`, {
      method: 'POST',
      body: JSON.stringify({ version }),
      schema: PostAdminUpdatesDismissResponseSchema,
    })
  },

  /** Master switch: while off, no outbound request is ever made. */
  setCheckEnabled: async (
    checkEnabled: boolean
  ): Promise<z.infer<typeof PutAdminUpdatesSettingsResponseSchema>> => {
    return httpClient(`${BASE_PATH}/settings`, {
      method: 'PUT',
      body: JSON.stringify({ checkEnabled }),
      schema: PutAdminUpdatesSettingsResponseSchema,
    })
  },
}
