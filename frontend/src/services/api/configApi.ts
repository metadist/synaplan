import type { AIModel, Capability } from '@/types/ai-models'
import { httpClient } from './httpClient'
import { z } from 'zod'

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
export const saveDefaultModels = async (
  defaults: SaveDefaultsRequest
): Promise<{ success: boolean; message: string }> => {
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
  checkModelAvailability,
}

// Memory Service Check (new)
const MemoryServiceCheckSchema = z.object({
  available: z.boolean(),
  configured: z.boolean(),
})

export type MemoryServiceCheck = z.infer<typeof MemoryServiceCheckSchema>

/**
 * Check memory service availability (lightweight, async check)
 * Uses skipAuth because this is called during app init before auth is established
 * and may run on public pages (shared chats)
 */
export async function checkMemoryServiceAvailability(): Promise<MemoryServiceCheck> {
  return httpClient('/api/v1/config/memory-service/check', {
    schema: MemoryServiceCheckSchema,
    skipAuth: true,
  })
}
