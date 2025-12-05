import { useConfigStore } from '@/stores/config'

const config = useConfigStore()
const API_BASE_URL = config.apiBaseUrl

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

/**
 * Helper function to make authenticated API calls
 */
async function apiFetch<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem('auth_token')
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options,
    headers: {
      ...headers,
      ...options.headers,
    },
  })

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({ error: response.statusText }))
    throw new Error(errorData.error || `HTTP ${response.status}`)
  }

  return response.json()
}

/**
 * Get all API keys for the current user
 */
export async function listApiKeys(): Promise<ListApiKeysResponse> {
  return apiFetch<ListApiKeysResponse>('/api/v1/apikeys')
}

/**
 * Create a new API key
 */
export async function createApiKey(data: CreateApiKeyRequest): Promise<CreateApiKeyResponse> {
  return apiFetch<CreateApiKeyResponse>('/api/v1/apikeys', {
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
): Promise<{ success: boolean; api_key: ApiKey }> {
  return apiFetch(`/api/v1/apikeys/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(data),
  })
}

/**
 * Revoke (delete) an API key
 */
export async function revokeApiKey(id: number): Promise<{ success: boolean; message: string }> {
  return apiFetch(`/api/v1/apikeys/${id}`, {
    method: 'DELETE',
  })
}

