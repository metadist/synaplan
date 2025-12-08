// Subscription API service
import { httpClient } from './httpClient'

export interface SubscriptionPlan {
  id: string
  name: string
  stripePriceId: string
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
  plan: string
  status?: string
  nextBilling?: string
  cancelAt?: string
  stripeSubscriptionId?: string
}

export interface PortalSession {
  url: string
}

export const subscriptionApi = {
  async getPlans(): Promise<{ plans: SubscriptionPlan[]; stripeConfigured: boolean }> {
    return httpClient<{ plans: SubscriptionPlan[]; stripeConfigured: boolean }>('/api/v1/subscription/plans', {
      method: 'GET'
    })
  },

  async createCheckoutSession(planId: string): Promise<CheckoutSession> {
    return httpClient<CheckoutSession>('/api/v1/subscription/checkout', {
      method: 'POST',
      body: JSON.stringify({ planId })
    })
  },

  async getSubscriptionStatus(): Promise<SubscriptionStatus> {
    return httpClient<SubscriptionStatus>('/api/v1/subscription/status', {
      method: 'GET'
    })
  },

  async createPortalSession(): Promise<PortalSession> {
    return httpClient<PortalSession>('/api/v1/subscription/portal', {
      method: 'POST'
    })
  },

  async syncFromStripe(): Promise<{ success: boolean; level: string; status: string; message?: string }> {
    return httpClient<{ success: boolean; level: string; status: string; message?: string }>('/api/v1/subscription/sync', {
      method: 'POST'
    })
  }
}

