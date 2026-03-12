import { randomUUID } from 'crypto'
import { createHmac } from 'crypto'
import type { APIRequestContext } from '@playwright/test'
import { getApiUrl } from '../config/config'

const WEBHOOK_SECRET = 'whsec_fakeWebhookSecretForTests'
const PRICE_PRO = 'price_1TestSuitePro'

function signPayload(payload: string, secret: string): string {
  const timestamp = Math.floor(Date.now() / 1000)
  const signature = createHmac('sha256', secret).update(`${timestamp}.${payload}`).digest('hex')
  return `t=${timestamp},v1=${signature}`
}

export interface WebhookResult {
  status: number
  body: Record<string, unknown>
}

interface SendWebhookOptions {
  request: APIRequestContext
  eventType: string
  data: Record<string, unknown>
  eventId?: string
}

export async function sendStripeWebhook({
  request,
  eventType,
  data,
  eventId,
}: SendWebhookOptions): Promise<WebhookResult> {
  const payload = JSON.stringify({
    id: eventId ?? `evt_${randomUUID()}`,
    object: 'event',
    type: eventType,
    data: { object: data },
    created: Math.floor(Date.now() / 1000),
    livemode: false,
    api_version: '2024-06-20',
  })

  const signature = signPayload(payload, WEBHOOK_SECRET)

  const response = await request.post(`${getApiUrl()}/api/v1/stripe/webhook`, {
    data: payload,
    headers: {
      'Content-Type': 'application/json',
      'Stripe-Signature': signature,
    },
  })

  const body = await response.json()
  return { status: response.status(), body }
}

interface SubscriptionWebhookData {
  customerId: string
  subscriptionId: string
  userId: string
  priceId?: string
  status?: string
}

export async function sendCheckoutCompletedWebhook(
  request: APIRequestContext,
  opts: SubscriptionWebhookData
): Promise<WebhookResult> {
  return sendStripeWebhook({
    request,
    eventType: 'checkout.session.completed',
    data: {
      id: `cs_${randomUUID()}`,
      object: 'checkout.session',
      customer: opts.customerId,
      client_reference_id: opts.userId,
      customer_email: null,
      mode: 'subscription',
      subscription: opts.subscriptionId,
      status: 'complete',
    },
  })
}

export async function sendSubscriptionCreatedWebhook(
  request: APIRequestContext,
  opts: SubscriptionWebhookData
): Promise<WebhookResult> {
  const now = Math.floor(Date.now() / 1000)
  return sendStripeWebhook({
    request,
    eventType: 'customer.subscription.created',
    data: {
      id: opts.subscriptionId,
      object: 'subscription',
      customer: opts.customerId,
      status: opts.status ?? 'active',
      current_period_start: now,
      current_period_end: now + 30 * 24 * 3600,
      items: {
        data: [
          {
            price: {
              id: opts.priceId ?? PRICE_PRO,
            },
          },
        ],
      },
      cancel_at_period_end: false,
      metadata: { user_id: opts.userId, plan: 'PRO' },
    },
  })
}

export async function sendPaymentFailedWebhook(
  request: APIRequestContext,
  opts: { customerId: string }
): Promise<WebhookResult> {
  return sendStripeWebhook({
    request,
    eventType: 'invoice.payment_failed',
    data: {
      id: `in_${randomUUID()}`,
      object: 'invoice',
      customer: opts.customerId,
      subscription: `sub_${randomUUID()}`,
      amount_due: 1999,
      amount_paid: 0,
      status: 'open',
    },
  })
}
