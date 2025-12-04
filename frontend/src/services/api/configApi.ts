import type { AIModel, Capability } from '@/types/ai-models'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'

export interface ModelsResponse {
  success: boolean
  models: Record<Capability, AIModel[]>
}

export interface DefaultsResponse {
  success: boolean
  defaults: Record<Capability, number | null>
}

export interface SaveDefaultsRequest {
  defaults: Partial<Record<Capability, number>>
}

export interface ModelCheckResponse {
  available: boolean
  provider_type: 'local' | 'external' | 'unknown'
  model_name: string
  service: string
  message?: string
  install_command?: string
  env_var?: string
  setup_instructions?: string
  setup_required?: boolean
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
    const errorData = await response.json().catch(() => ({}))
    throw new Error(errorData.error || errorData.message || `HTTP ${response.status}`)
  }

  return response.json()
}

/**
 * Get all available models grouped by capability
 */
export const getModels = async (): Promise<ModelsResponse> => {
  return apiFetch<ModelsResponse>('/api/v1/config/models')
}

/**
 * Get current default model configuration
 */
export const getDefaultModels = async (): Promise<DefaultsResponse> => {
  return apiFetch<DefaultsResponse>('/api/v1/config/models/defaults')
}

/**
 * Save default model configuration
 */
export const saveDefaultModels = async (defaults: SaveDefaultsRequest): Promise<{ success: boolean; message: string }> => {
  return apiFetch<{ success: boolean; message: string }>('/api/v1/config/models/defaults', {
    method: 'POST',
    body: JSON.stringify(defaults),
  })
}

/**
 * Check if a model is available/ready to use
 */
export const checkModelAvailability = async (modelId: number): Promise<ModelCheckResponse> => {
  return apiFetch<ModelCheckResponse>(`/api/v1/config/models/${modelId}/check`)
}

export const configApi = {
  getModels,
  getDefaultModels,
  saveDefaultModels,
  checkModelAvailability
}

