import {
  GetApiWidgetVisitorKeyShowResponseSchema,
  PostApiWidgetVisitorKeyRequireSignatureResponseSchema,
  PostApiWidgetVisitorKeyRotateResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

/**
 * Who the embedding site says the visitor is. The id only counts with the
 * signature the site made on its server; the backend drops an unsigned id.
 */
export interface WidgetVisitorIdentity {
  externalUserId?: string
  externalUserIdSignature?: string
}

/** The fields a widget message carries for a visitor the embedding site named. */
export function visitorPayload(visitor?: WidgetVisitorIdentity): Record<string, string> {
  if (!visitor?.externalUserId) {
    return {}
  }
  return {
    externalUserId: visitor.externalUserId,
    ...(visitor.externalUserIdSignature
      ? { externalUserIdSignature: visitor.externalUserIdSignature }
      : {}),
  }
}

/** The key the widget owner's server signs visitor ids with. Owner only. */
export async function getVisitorKey(widgetId: string) {
  return await httpClient(`/api/v1/widgets/${encodeURIComponent(widgetId)}/visitor-key`, {
    method: 'GET',
    schema: GetApiWidgetVisitorKeyShowResponseSchema,
  })
}

/** Replaces the key; signatures made with the old one stop working. */
export async function rotateVisitorKey(widgetId: string) {
  return await httpClient(`/api/v1/widgets/${encodeURIComponent(widgetId)}/visitor-key/rotate`, {
    method: 'POST',
    schema: PostApiWidgetVisitorKeyRotateResponseSchema,
  })
}

/** Ends unsigned visitor ids for a widget from before signing. One way. */
export async function requireVisitorSignature(widgetId: string) {
  return await httpClient(
    `/api/v1/widgets/${encodeURIComponent(widgetId)}/visitor-key/require-signature`,
    { method: 'POST', schema: PostApiWidgetVisitorKeyRequireSignatureResponseSchema }
  )
}
