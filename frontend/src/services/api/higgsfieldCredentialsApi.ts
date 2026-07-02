/**
 * Higgsfield Credentials API
 *
 * Manage the signed-in user's personal Higgsfield API key + secret. The pair
 * is stored encrypted at rest on the backend (BCONFIG via EncryptionService),
 * and overrides the platform-wide HIGGSFIELD_API_KEY / HIGGSFIELD_API_SECRET
 * env default whenever it is set.
 *
 * Backend endpoints:
 *   GET    /api/v1/ai-providers/higgsfield/credentials
 *   PUT    /api/v1/ai-providers/higgsfield/credentials
 *   DELETE /api/v1/ai-providers/higgsfield/credentials
 *   POST   /api/v1/ai-providers/higgsfield/credentials/test
 */

import type { z } from 'zod'
import {
  DeleteApiAiHiggsfieldCredentialsDeleteResponseSchema,
  GetApiAiHiggsfieldCredentialsGetResponseSchema,
  PostApiAiHiggsfieldCredentialsTestResponseSchema,
  PutApiAiHiggsfieldCredentialsPutResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

// Response types are inferred from the generated Zod schemas (per AGENTS.md:
// never hand-write interfaces for API responses). The request body type below
// is NOT an API response, so it stays a plain interface.
export type HiggsfieldEffectiveSource = 'user' | 'platform' | 'none'

export type HiggsfieldCredentialState = z.infer<
  typeof GetApiAiHiggsfieldCredentialsGetResponseSchema
>

export type SaveHiggsfieldCredentialsResponse = z.infer<
  typeof PutApiAiHiggsfieldCredentialsPutResponseSchema
>

export type ClearHiggsfieldCredentialsResponse = z.infer<
  typeof DeleteApiAiHiggsfieldCredentialsDeleteResponseSchema
>

export type TestHiggsfieldCredentialsResponse = z.infer<
  typeof PostApiAiHiggsfieldCredentialsTestResponseSchema
>

export interface SaveHiggsfieldCredentialsRequest {
  api_key: string
  api_secret: string
}

const BASE = '/api/v1/ai-providers/higgsfield/credentials'

export async function getHiggsfieldCredentialState(): Promise<HiggsfieldCredentialState> {
  return httpClient(BASE, {
    method: 'GET',
    schema: GetApiAiHiggsfieldCredentialsGetResponseSchema,
  })
}

export async function saveHiggsfieldCredentials(
  data: SaveHiggsfieldCredentialsRequest
): Promise<SaveHiggsfieldCredentialsResponse> {
  return httpClient(BASE, {
    method: 'PUT',
    body: JSON.stringify(data),
    schema: PutApiAiHiggsfieldCredentialsPutResponseSchema,
  })
}

export async function clearHiggsfieldCredentials(): Promise<ClearHiggsfieldCredentialsResponse> {
  return httpClient(BASE, {
    method: 'DELETE',
    schema: DeleteApiAiHiggsfieldCredentialsDeleteResponseSchema,
  })
}

export async function testHiggsfieldCredentials(): Promise<TestHiggsfieldCredentialsResponse> {
  return httpClient(`${BASE}/test`, {
    method: 'POST',
    schema: PostApiAiHiggsfieldCredentialsTestResponseSchema,
  })
}
