import type { AIModel, Capability } from '@/types/ai-models'
import { httpClient } from './httpClient'

export interface ModelsResponse {
  success: boolean
  models: Partial<Record<Capability, AIModel[]>>
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
 * Get all available models grouped by capability
 */
export const getModels = async (): Promise<ModelsResponse> => {
  return httpClient<ModelsResponse>('/api/v1/config/models')
}

/**
 * Get current default model configuration
 */
export const getDefaultModels = async (): Promise<DefaultsResponse> => {
  return httpClient<DefaultsResponse>('/api/v1/config/models/defaults')
}

/**
 * Save default model configuration
 */
export const saveDefaultModels = async (defaults: SaveDefaultsRequest): Promise<{ success: boolean; message: string }> => {
  return httpClient<{ success: boolean; message: string }>('/api/v1/config/models/defaults', {
    method: 'POST',
    body: JSON.stringify(defaults),
  })
}

/**
 * Check if a model is available/ready to use
 */
export const checkModelAvailability = async (modelId: number): Promise<ModelCheckResponse> => {
  return httpClient<ModelCheckResponse>(`/api/v1/config/models/${modelId}/check`)
}

export const configApi = {
  getModels,
  getDefaultModels,
  saveDefaultModels,
  checkModelAvailability
}

