import type { z } from 'zod'
import {
  GetAdminSubscriptionsListResponseSchema,
  PatchAdminSubscriptionsUpdateResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

// Inferred from the generated Zod schemas (per AGENTS.md: never hand-write
// interfaces for API responses).
type AdminSubscriptionsListResponse = z.infer<typeof GetAdminSubscriptionsListResponseSchema>
type AdminSubscriptionUpdateResponse = z.infer<typeof PatchAdminSubscriptionsUpdateResponseSchema>

export type AdminSubscription = AdminSubscriptionsListResponse['subscriptions'][number]

export interface AdminSubscriptionUpdateRequest {
  priceMonthly?: number
  priceYearly?: number
  currency?: string
  costBudgetMonthly?: number
  costBudgetYearly?: number
  active?: boolean
}

export const adminSubscriptionsApi = {
  list: async (): Promise<AdminSubscriptionsListResponse> => {
    return httpClient('/api/v1/admin/subscriptions', {
      schema: GetAdminSubscriptionsListResponseSchema,
    })
  },
  update: async (
    id: number,
    data: AdminSubscriptionUpdateRequest
  ): Promise<AdminSubscriptionUpdateResponse> => {
    return httpClient(`/api/v1/admin/subscriptions/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
      schema: PatchAdminSubscriptionsUpdateResponseSchema,
    })
  },
}
