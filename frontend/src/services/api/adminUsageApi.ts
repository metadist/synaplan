import { z } from 'zod'
import {
  AdminUsageKindTotal,
  AdminUsageRequest,
  AdminUsageUser,
  ListAdminUsageUserRequestsResponseSchema,
  ListAdminUsageUsersResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type UsageUser = z.infer<typeof AdminUsageUser>
export type UsageKindTotal = z.infer<typeof AdminUsageKindTotal>
export type UsageRequest = z.infer<typeof AdminUsageRequest>
export type UsagePeriod = '24h' | '7d' | '30d' | 'all'
export type UsageSort = 'cost' | 'requests' | 'tokens'

export type UsageUsersPage = z.infer<typeof ListAdminUsageUsersResponseSchema>
export type UsageRequestsPage = z.infer<typeof ListAdminUsageUserRequestsResponseSchema>

const BASE = '/api/v1/admin/usage'

export const adminUsageApi = {
  users(params: {
    period: UsagePeriod
    sort: UsageSort
    page: number
    perPage?: number
  }): Promise<UsageUsersPage> {
    const query = new URLSearchParams({
      period: params.period,
      sort: params.sort,
      page: String(params.page),
      perPage: String(params.perPage ?? 20),
    })
    return httpClient(`${BASE}/users?${query}`, { schema: ListAdminUsageUsersResponseSchema })
  },

  requests(
    userId: number,
    params: { period: UsagePeriod; page: number; perPage?: number }
  ): Promise<UsageRequestsPage> {
    const query = new URLSearchParams({
      period: params.period,
      page: String(params.page),
      perPage: String(params.perPage ?? 20),
    })
    return httpClient(`${BASE}/users/${userId}/requests?${query}`, {
      schema: ListAdminUsageUserRequestsResponseSchema,
    })
  },
}
