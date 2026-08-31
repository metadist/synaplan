import { GetAppMessagedigestResolveResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export interface MessageDigestReference {
  messageId: number
  chatId: number
  title: string
  channel: string
  sourceDate: number
}

/**
 * Resolve [Message:ID] badge references (deep-memory digests) for the given
 * message ids. Unknown or foreign ids are silently omitted by the backend.
 */
export async function resolveMessageDigests(
  messageIds: number[]
): Promise<MessageDigestReference[]> {
  if (messageIds.length === 0) return []

  const ids = Array.from(new Set(messageIds)).slice(0, 100).join(',')
  const data = await httpClient(`/api/v1/user/message-digests?ids=${encodeURIComponent(ids)}`, {
    schema: GetAppMessagedigestResolveResponseSchema,
  })

  // The generated schema marks item fields optional; normalize so the store
  // and badge renderer can rely on a complete reference.
  return (data.digests ?? [])
    .filter((d) => typeof d.messageId === 'number' && d.messageId > 0)
    .map((d) => ({
      messageId: d.messageId as number,
      chatId: d.chatId ?? 0,
      title: d.title ?? '',
      channel: d.channel ?? '',
      sourceDate: d.sourceDate ?? 0,
    }))
}
