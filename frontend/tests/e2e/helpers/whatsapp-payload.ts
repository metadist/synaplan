/**
 * Meta Cloud API webhook payloads for POST /api/v1/webhooks/whatsapp.
 */

import { createHmac } from 'node:crypto'

/** Matches WHATSAPP_APP_SECRET in backend/.env.test. Not a real secret. */
const E2E_APP_SECRET = 'e2e-whatsapp-app-secret'

/**
 * The request Meta would send: the serialized body and its
 * X-Hub-Signature-256, made with the app secret. The backend refuses a
 * webhook call without a matching signature.
 */
export function signedWebhookRequest(payload: object): {
  data: string
  headers: Record<string, string>
} {
  const data = JSON.stringify(payload)
  const secret = process.env.WHATSAPP_APP_SECRET || E2E_APP_SECRET
  const signature = createHmac('sha256', secret).update(data).digest('hex')

  return {
    data,
    headers: {
      'Content-Type': 'application/json',
      'X-Hub-Signature-256': `sha256=${signature}`,
    },
  }
}

const PHONE_NUMBER_ID = '123456789012345'
const DISPLAY_PHONE = '15550001234'

function metaBase(messageId: string, from: string, type: string, extra: Record<string, unknown>) {
  return {
    entry: [
      {
        id: 'WHATSAPP_BUSINESS_ACCOUNT_ID',
        changes: [
          {
            field: 'messages',
            value: {
              messaging_product: 'whatsapp',
              metadata: {
                display_phone_number: DISPLAY_PHONE,
                phone_number_id: PHONE_NUMBER_ID,
              },
              messages: [
                {
                  from,
                  id: messageId,
                  timestamp: String(Math.floor(Date.now() / 1000)),
                  type,
                  ...extra,
                },
              ],
            },
          },
        ],
      },
    ],
  }
}

/**
 * Unique sender phone number per test. The backend maps each number to an
 * auto-created ANONYMOUS user whose MESSAGES limit is a LIFETIME total (10)
 * — a fixed number would burn through it across runs on a long-lived test
 * DB and start failing with "Rate limit exceeded".
 */
export function uniqueWaSender(): string {
  return `49151${String(Date.now()).slice(-8)}${Math.floor(Math.random() * 10)}`
}

export function metaPayloadText(messageId: string, from: string, body: string): object {
  return metaBase(messageId, from, 'text', {
    text: { body },
  })
}

/** Image message. Use mediaId that stub can serve (e.g. img-… → image/jpeg). */
export function metaPayloadImage(
  messageId: string,
  from: string,
  mediaId: string,
  caption?: string
): object {
  const image: Record<string, unknown> = { id: mediaId }
  if (caption !== undefined) image.caption = caption
  return metaBase(messageId, from, 'image', { image })
}

/** Audio message. Use mediaId starting with "audio" so stub returns audio/ogg. */
export function metaPayloadAudio(messageId: string, from: string, mediaId: string): object {
  return metaBase(messageId, from, 'audio', {
    audio: { id: mediaId },
  })
}

export { PHONE_NUMBER_ID, DISPLAY_PHONE }
