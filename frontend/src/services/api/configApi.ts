import type { AIModel, Capability, ProviderAvailability } from '@/types/ai-models'
import { httpClient } from './httpClient'
import { z } from 'zod'

export interface ModelsResponse {
  success: boolean
  models: Partial<Record<Capability, AIModel[]>>
  /** Provider-level availability of this installation (key/URL configured). */
  providers: ProviderAvailability[]
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

export interface ResetDefaultsResponse {
  success: boolean
  message: string
  defaults: Record<string, number>
}

export interface PlannerModelResponse {
  success: boolean
  modelId: number | null
  fallbackModelId: number | null
}

/**
 * Get the planner model selection (DEFAULTMODEL.PLAN) for the current user,
 * plus the Sorting model id it falls back to when no planner model is set.
 */
export const getPlannerModel = async (): Promise<PlannerModelResponse> => {
  return httpClient<PlannerModelResponse>('/api/v1/config/routing/planner-model')
}

/**
 * Save (modelId) or clear (null) the per-user planner model override.
 */
export const savePlannerModel = async (
  modelId: number | null
): Promise<{ success: boolean; modelId: number | null }> => {
  return httpClient<{ success: boolean; modelId: number | null }>(
    '/api/v1/config/routing/planner-model',
    {
      method: 'POST',
      body: JSON.stringify({ modelId }),
    }
  )
}

/**
 * Get the platform-wide summary model (DEFAULTMODEL.SUMMARIZE) that condenses
 * long conversations, plus the Sorting model it falls back to. Admin only.
 */
export const getSummaryModel = async (): Promise<PlannerModelResponse> => {
  return httpClient<PlannerModelResponse>('/api/v1/config/routing/summary-model')
}

/**
 * Save (modelId) or clear (null) the platform-wide summary model. Admin only.
 */
export const saveSummaryModel = async (
  modelId: number | null
): Promise<{ success: boolean; modelId: number | null }> => {
  return httpClient<{ success: boolean; modelId: number | null }>(
    '/api/v1/config/routing/summary-model',
    {
      method: 'POST',
      body: JSON.stringify({ modelId }),
    }
  )
}

/**
 * Remove all user-specific model overrides so the user falls back
 * to the platform defaults.
 */
export const resetDefaultModels = async (): Promise<ResetDefaultsResponse> => {
  return httpClient<ResetDefaultsResponse>('/api/v1/config/models/defaults/reset', {
    method: 'POST',
  })
}

export const configApi = {
  getModels,
  getDefaultModels,
  saveDefaultModels,
  checkModelAvailability,
  resetDefaultModels,
  getPlannerModel,
  savePlannerModel,
  getSummaryModel,
  saveSummaryModel,
}

// Qdrant Availability Check
const MemoryServiceCheckSchema = z.object({
  available: z.boolean(),
  configured: z.boolean(),
})

export type MemoryServiceCheck = z.infer<typeof MemoryServiceCheckSchema>

/**
 * Check Qdrant availability (lightweight, async check)
 * Uses skipAuth because this is called during app init before auth is established
 * and may run on public pages (shared chats)
 */
export async function checkMemoryServiceAvailability(): Promise<MemoryServiceCheck> {
  return httpClient('/api/v1/config/memory-service/check', {
    schema: MemoryServiceCheckSchema,
    skipAuth: true,
  })
}
