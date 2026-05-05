/**
 * API Keys API - User-owned API key management
 *
 * Uses the shared httpClient so that expired session access-tokens are
 * transparently refreshed and requests retried (see httpClient.ts).
 */

import { httpClient } from './httpClient'

export interface ApiKey {
  id: number
  name: string
  key_prefix: string
  status: 'active' | 'inactive'
  scopes: string[]
  last_used: number | null
  created: number
}

export interface CreateApiKeyRequest {
  name: string
  scopes?: string[]
}

export interface CreateApiKeyResponse {
  success: boolean
  api_key: {
    id: number
    name: string
    key: string // Full key - only shown once!
    key_prefix?: string
    scopes: string[]
    created: number
    status: 'active' | 'inactive'
    last_used: number | null
  }
  message: string
}

export interface ListApiKeysResponse {
  success: boolean
  api_keys: ApiKey[]
}

export interface UpdateApiKeyRequest {
  name?: string
  status?: 'active' | 'inactive'
  scopes?: string[]
}

export interface UpdateApiKeyResponse {
  success: boolean
  api_key: ApiKey
}

export interface RevokeApiKeyResponse {
  success: boolean
  message: string
}

/**
 * Get all API keys for the current user
 */
export async function listApiKeys(): Promise<ListApiKeysResponse> {
  return httpClient<ListApiKeysResponse>('/api/v1/apikeys', {
    method: 'GET',
  })
}

/**
 * Create a new API key
 */
export async function createApiKey(data: CreateApiKeyRequest): Promise<CreateApiKeyResponse> {
  return httpClient<CreateApiKeyResponse>('/api/v1/apikeys', {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

/**
 * Update an API key (activate/deactivate, change name, scopes)
 */
export async function updateApiKey(
  id: number,
  data: UpdateApiKeyRequest
): Promise<UpdateApiKeyResponse> {
  return httpClient<UpdateApiKeyResponse>(`/api/v1/apikeys/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(data),
  })
}

/**
 * Revoke (delete) an API key
 */
export async function revokeApiKey(id: number): Promise<RevokeApiKeyResponse> {
  return httpClient<RevokeApiKeyResponse>(`/api/v1/apikeys/${id}`, {
    method: 'DELETE',
  })
}
