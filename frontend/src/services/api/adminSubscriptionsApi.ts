import { httpClient } from './httpClient'

export interface AdminSubscription {
  id: number
  name: string
  level: string
  priceMonthly: string
  priceYearly: string
  description: string
  active: boolean
  costBudgetMonthly: string
  costBudgetYearly: string
}

export interface AdminSubscriptionUpdateRequest {
  costBudgetMonthly?: number
  costBudgetYearly?: number
  active?: boolean
}

interface AdminSubscriptionsListResponse {
  success: boolean
  subscriptions: AdminSubscription[]
}

interface AdminSubscriptionUpdateResponse {
  success: boolean
  subscription: AdminSubscription
}

export const adminSubscriptionsApi = {
  list: async (): Promise<AdminSubscriptionsListResponse> => {
    return httpClient<AdminSubscriptionsListResponse>('/api/v1/admin/subscriptions')
  },
  update: async (
    id: number,
    data: AdminSubscriptionUpdateRequest
  ): Promise<AdminSubscriptionUpdateResponse> => {
    return httpClient<AdminSubscriptionUpdateResponse>(`/api/v1/admin/subscriptions/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },
}
