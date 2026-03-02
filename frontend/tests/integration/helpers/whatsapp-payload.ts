/**
 * Meta Cloud API webhook payloads for POST /api/v1/webhooks/whatsapp.
 */

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
