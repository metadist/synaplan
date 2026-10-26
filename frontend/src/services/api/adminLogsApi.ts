import type { z } from 'zod'
import { GetAdminLogsResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

/**
 * The redacted operational event ring (docs/OBSERVABILITY.md): warnings and
 * above, allow-listed fields, no content. Read through the authenticated
 * client so the native app's Bearer session works the same as a cookie.
 */
export type AdminLogsResponse = z.infer<typeof GetAdminLogsResponseSchema>
export type RingEvent = NonNullable<AdminLogsResponse['events']>[number]

export interface RecentEventsQuery {
  /** The container hostname a server stamps on its events. */
  host?: string
  sinceMinutes?: number
  limit?: number
}

export const adminLogsApi = {
  recent: async (query: RecentEventsQuery = {}): Promise<RingEvent[]> => {
    const params = new URLSearchParams({ mode: 'recent' })
    if (query.host) params.set('host', query.host)
    if (query.sinceMinutes) params.set('since_minutes', String(query.sinceMinutes))
    if (query.limit) params.set('limit', String(query.limit))
    const response = await httpClient(`/api/v1/admin/logs?${params.toString()}`, {
      schema: GetAdminLogsResponseSchema,
    })
    return response.events ?? []
  },
}
