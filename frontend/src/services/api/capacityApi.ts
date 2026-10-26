import type { z } from 'zod'
import { GetAdminCapacityServersResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

/**
 * Capacity of every server, as its watchdog reported it (architecture update
 * AU7). One short request that reads Redis; it never asks a server.
 */
export type CapacityReport = z.infer<typeof GetAdminCapacityServersResponseSchema>
export type CapacityServer = CapacityReport['servers'][number]

export const capacityApi = {
  servers: (): Promise<CapacityReport> =>
    httpClient('/api/v1/admin/capacity/servers', {
      schema: GetAdminCapacityServersResponseSchema,
    }),
}
