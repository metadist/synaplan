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

import { httpClient } from './httpClient'

export type HiggsfieldEffectiveSource = 'user' | 'platform' | 'none'

export interface HiggsfieldCredentialState {
  has_platform_credentials: boolean
  has_user_credentials: boolean
  user_api_key_masked: string
  effective_source: HiggsfieldEffectiveSource
}

export interface SaveHiggsfieldCredentialsRequest {
  api_key: string
  api_secret: string
}

export interface SaveHiggsfieldCredentialsResponse {
  success: boolean
  has_user_credentials: boolean
  user_api_key_masked: string
}

export interface ClearHiggsfieldCredentialsResponse {
  success: boolean
  has_user_credentials: boolean
  has_platform_credentials: boolean
}

export interface TestHiggsfieldCredentialsResponse {
  success: boolean
  message: string
  source: HiggsfieldEffectiveSource
}

const BASE = '/api/v1/ai-providers/higgsfield/credentials'

export async function getHiggsfieldCredentialState(): Promise<HiggsfieldCredentialState> {
  return httpClient<HiggsfieldCredentialState>(BASE, { method: 'GET' })
}

export async function saveHiggsfieldCredentials(
  data: SaveHiggsfieldCredentialsRequest
): Promise<SaveHiggsfieldCredentialsResponse> {
  return httpClient<SaveHiggsfieldCredentialsResponse>(BASE, {
    method: 'PUT',
    body: JSON.stringify(data),
  })
}

export async function clearHiggsfieldCredentials(): Promise<ClearHiggsfieldCredentialsResponse> {
  return httpClient<ClearHiggsfieldCredentialsResponse>(BASE, { method: 'DELETE' })
}

export async function testHiggsfieldCredentials(): Promise<TestHiggsfieldCredentialsResponse> {
  return httpClient<TestHiggsfieldCredentialsResponse>(`${BASE}/test`, { method: 'POST' })
}
