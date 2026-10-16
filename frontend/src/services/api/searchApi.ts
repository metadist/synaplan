import type { z } from 'zod'
import {
  SmartSearchInterpretResponseSchema,
  SmartSearchResponseSchema,
  smartSearchInterpret_Body,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type SmartSearchResponse = z.infer<typeof SmartSearchResponseSchema>
export type SmartSearchHit = SmartSearchResponse['results'][number]
export type SmartSearchKind = SmartSearchHit['kind']
export type SmartSearchSettingAction = NonNullable<SmartSearchHit['action']>

export type SmartSearchInterpretRequest = z.infer<typeof smartSearchInterpret_Body>
export type SmartSearchInterpretCandidate = SmartSearchInterpretRequest['candidates'][number]
export type SmartSearchInterpretation = z.infer<typeof SmartSearchInterpretResponseSchema>

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

/** AI tier: which of the shown results fits a question in plain words. */
export async function interpretSearch(
  request: SmartSearchInterpretRequest,
  signal?: AbortSignal
): Promise<SmartSearchInterpretation> {
  return httpClient('/api/v1/search/interpret', {
    method: 'POST',
    body: JSON.stringify(request),
    signal,
    schema: SmartSearchInterpretResponseSchema,
  })
}
