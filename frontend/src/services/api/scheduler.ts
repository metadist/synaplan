import type { z } from 'zod'
import { GetAdminSchedulerStatusResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type SchedulerStatus = z.infer<typeof GetAdminSchedulerStatusResponseSchema>

const STATUS_PATH = '/api/v1/admin/scheduler/status'

/**
 * Background-job status (ROLE_ADMIN). A non-admin receives 403; an unreachable
 * status store comes back as 503. Callers treat both as a failed read.
 */
export const schedulerApi = {
  getStatus: async (): Promise<SchedulerStatus> => {
    return httpClient(STATUS_PATH, { schema: GetAdminSchedulerStatusResponseSchema })
  },
}
