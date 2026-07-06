// Subscription API service
import type { z } from 'zod'
import {
  GetSubscriptionBudgetResponseSchema,
  PostSubscriptionTopupResponseSchema,
  PostIapVerifyResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export interface SubscriptionPlan {
  id: string
  name: string
  /** Null when billing/Stripe is disabled on this server (OpenAPI: nullable). */
  stripePriceId: string | null
  /**
   * MOBILE-APP SEAM (Epic 5.5): the native store product ID the app purchases
   * for this tier (Apple/Google). Null/placeholder until the server configures
   * real store products.
   */
  iapProductId?: string | null
  price: number
  currency: string
  interval: string
  features: string[]
}

export interface CheckoutSession {
  sessionId: string
  url: string
}

export interface SubscriptionStatus {
  hasSubscription: boolean
  /**
   * MOBILE-APP SEAM (Epic 5.1): unified entitlement truth — true when any
   * channel (Stripe / Apple / Google) has a currently-valid subscription.
   */
  active?: boolean
  plan: string
  /** Alias of `plan` (the entitled tier). */
  tier?: string
  /**
   * The channel that owns the subscription. Web buys via Stripe; the app via
   * Apple/Google IAP. Legacy Stripe subs report `'stripe'` (backfilled), and
   * exactly one channel owns an active subscription at a time.
   */
  source?: 'stripe' | 'apple' | 'google' | null
  /**
   * Where to manage the subscription for IAP channels (Apple/Google system
   * settings). Null for Stripe — call `createPortalSession()` instead.
   */
  manageUrl?: string | null
  /** True when the subscription is set to cancel at the end of the period. */
  cancelAtPeriodEnd?: boolean
  status?: string
  /**
   * Unix timestamp (seconds since epoch) of the next billing date, or
   * `null` when the subscription is not in a billable state.
   *
   * Per Copilot review on PR #931, this used to be typed as `string`
   * which silently hid the actual `number | null` shape coming back
   * from Stripe webhook payloads. `formatDate()` already accepts both
   * shapes, but other callers branching on `typeof` would have been
   * unsafe. Mirrors the OpenAPI annotation on
   * `SubscriptionController::getSubscriptionStatus`.
   */
  nextBilling?: number | null
  cancelAt?: number | null
  stripeSubscriptionId?: string | null
  /**
   * Set by the backend when an `invoice.payment_failed` webhook fires.
   * The user keeps access during Stripe's smart-retry window, but the
   * SubscriptionView surfaces a dedicated warning so they can update
   * their card before access is revoked (issue #856).
   */
  paymentFailed?: boolean
}

export interface PortalSession {
  url: string
}

/**
 * MOBILE-APP SEAM (Epic 5.4): result of server-side IAP receipt validation.
 * The server is the single source of truth — the app shows the outcome but
 * never grants a tier itself. Inferred from the generated schema (per
 * AGENTS.md: never hand-write interfaces for API responses).
 */
export type IapVerifyResult = z.infer<typeof PostIapVerifyResponseSchema>

// Inferred from the generated Zod schemas (per AGENTS.md: never hand-write
// interfaces for API responses).
export type TopupSession = z.infer<typeof PostSubscriptionTopupResponseSchema>

export type BudgetStatus = z.infer<typeof GetSubscriptionBudgetResponseSchema>

export const subscriptionApi = {
  async getPlans(): Promise<{
    plans: SubscriptionPlan[]
    stripeConfigured: boolean
    iapConfigured?: boolean
  }> {
    return httpClient<{
      plans: SubscriptionPlan[]
      stripeConfigured: boolean
      iapConfigured?: boolean
    }>('/api/v1/subscription/plans', {
      method: 'GET',
    })
  },

  async createCheckoutSession(planId: string): Promise<CheckoutSession> {
    return httpClient<CheckoutSession>('/api/v1/subscription/checkout', {
      method: 'POST',
      body: JSON.stringify({ planId }),
    })
  },

  async getSubscriptionStatus(): Promise<SubscriptionStatus> {
    return httpClient<SubscriptionStatus>('/api/v1/subscription/status', {
      method: 'GET',
    })
  },

  async createPortalSession(): Promise<PortalSession> {
    return httpClient<PortalSession>('/api/v1/subscription/portal', {
      method: 'POST',
    })
  },

  /**
   * MOBILE-APP SEAM (Epic 5.4): validate a native in-app purchase server-side
   * and (if valid) grant the tier. Called from the IAP plugin's validator hook;
   * `receipt` is the Apple signed-transaction JWS or the Google purchase token.
   */
  async verifyIapPurchase(input: {
    platform: 'apple' | 'google'
    receipt: string
    productId?: string
  }): Promise<IapVerifyResult> {
    return httpClient('/api/v1/iap/verify', {
      method: 'POST',
      body: JSON.stringify(input),
      schema: PostIapVerifyResponseSchema,
    })
  },

  /**
   * Current cost-budget status (markup-aware, incl. period top-ups).
   */
  async getBudget(): Promise<BudgetStatus> {
    return httpClient('/api/v1/subscription/budget', {
      method: 'GET',
      schema: GetSubscriptionBudgetResponseSchema,
    })
  },

  /**
   * Create a one-time Stripe Checkout to top up the cost budget in EUR-100 steps.
   */
  async createTopupSession(steps = 1): Promise<TopupSession> {
    return httpClient('/api/v1/subscription/topup', {
      method: 'POST',
      body: JSON.stringify({ steps }),
      schema: PostSubscriptionTopupResponseSchema,
    })
  },

  async syncFromStripe(): Promise<{
    success: boolean
    level: string
    status: string
    message?: string
  }> {
    return httpClient<{ success: boolean; level: string; status: string; message?: string }>(
      '/api/v1/subscription/sync',
      {
        method: 'POST',
      }
    )
  },
}
