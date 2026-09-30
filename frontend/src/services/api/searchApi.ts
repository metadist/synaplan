import type { z } from 'zod'
import { SmartSearchResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type SmartSearchResponse = z.infer<typeof SmartSearchResponseSchema>
export type SmartSearchHit = SmartSearchResponse['results'][number]
export type SmartSearchKind = SmartSearchHit['kind']
export type SmartSearchSettingAction = NonNullable<SmartSearchHit['action']>

/** Global search over the user's own content (and settings for admins). */
export async function searchEverything(
  q: string,
  options: { kinds?: SmartSearchKind[]; limit?: number; signal?: AbortSignal } = {}
): Promise<SmartSearchResponse> {
  return httpClient('/api/v1/search', {
    method: 'POST',
    body: JSON.stringify({ q, kinds: options.kinds, limit: options.limit }),
    signal: options.signal,
    schema: SmartSearchResponseSchema,
  })
}
