import type { z } from 'zod'
import {
  GetAdminModelHealthStatusResponseSchema,
  PostAdminModelHealthExemptResponseSchema,
  PostAdminModelHealthRefreshResponseSchema,
  PostAdminModelHealthResetResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

const BASE_PATH = '/api/v1/admin/model-health'

export type ModelStatusSnapshot = z.infer<typeof GetAdminModelHealthStatusResponseSchema>
export type ModelStatusProvider = ModelStatusSnapshot['providers'][number]
export type ModelStatusEntry = ModelStatusProvider['models'][number]
export type ModelStatusState = ModelStatusEntry['state']
export type ModelStatusRefreshResult = z.infer<typeof PostAdminModelHealthRefreshResponseSchema>

/**
 * Model availability API (ROLE_ADMIN on the backend).
 *
 * Every call here is free: the backend asks providers for their published
 * model list and reads counters from traffic that happened anyway. Nothing in
 * here ever runs inference.
 */
export const modelStatusApi = {
  /** Stored verdicts and traffic counters — no provider is contacted. */
  getStatus: async (): Promise<ModelStatusSnapshot> => {
    return httpClient(BASE_PATH, { schema: GetAdminModelHealthStatusResponseSchema })
  },

  /**
   * Re-check now, optionally for a single provider. Fetch the status again
   * afterwards for the new snapshot.
   */
  refresh: async (provider?: string): Promise<ModelStatusRefreshResult> => {
    return httpClient(`${BASE_PATH}/refresh`, {
      method: 'POST',
      body: JSON.stringify(provider ? { provider } : {}),
      schema: PostAdminModelHealthRefreshResponseSchema,
    })
  },

  /** Pause or resume automatic disabling for one model. */
  setExempt: async (
    modelId: number,
    exempt: boolean
  ): Promise<z.infer<typeof PostAdminModelHealthExemptResponseSchema>> => {
    return httpClient(`${BASE_PATH}/models/${modelId}/exempt`, {
      method: 'POST',
      body: JSON.stringify({ exempt }),
      schema: PostAdminModelHealthExemptResponseSchema,
    })
  },

  /** Forget the recorded successes and failures of the current window. */
  resetCounters: async (
    modelId: number
  ): Promise<z.infer<typeof PostAdminModelHealthResetResponseSchema>> => {
    return httpClient(`${BASE_PATH}/models/${modelId}/reset`, {
      method: 'POST',
      schema: PostAdminModelHealthResetResponseSchema,
    })
  },
}
