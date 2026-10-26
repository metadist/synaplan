import { z } from 'zod'
import {
  ClaimPayFirstCheckoutResponseSchema,
  StartPayFirstCheckoutResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type CheckoutClaim = z.infer<typeof ClaimPayFirstCheckoutResponseSchema>

export const checkoutApi = {
  start(email: string, plan: string, challengeToken: string) {
    return httpClient('/api/v1/checkout/start', {
      method: 'POST',
      body: JSON.stringify({ email, plan, challengeToken }),
      schema: StartPayFirstCheckoutResponseSchema,
    })
  },

  claim(sessionId: string): Promise<CheckoutClaim> {
    return httpClient('/api/v1/checkout/claim', {
      method: 'POST',
      body: JSON.stringify({ sessionId }),
      schema: ClaimPayFirstCheckoutResponseSchema,
    })
  },
}
