import {
  GetApiConfigModelsListResponseSchema,
  GetApiConfigRoutingPlannerModelGetResponseSchema,
  PostApiConfigModelsDefaultsSaveResponseSchema,
  PostApiConfigRoutingPlannerModelSaveResponseSchema,
} from '@/generated/api-schemas'
import type { AIModel, Capability, ProviderAvailability } from '@/types/ai-models'
import { httpClient } from './httpClient'
import { z } from 'zod'

export interface ModelsResponse {
  success: boolean
  models: Partial<Record<Capability, AIModel[]>>
  /** Provider-level availability of this installation (key/URL configured). */
  providers: ProviderAvailability[]
  /** Capabilities a group allow-list limits for this member. */
  restricted?: string[]
  /** Groups whose model lists were combined for this member. */
  groupLimits?: { names: string[]; combined: boolean }
}

export interface DefaultsResponse {
  success: boolean
  defaults: Record<Capability, number | null>
  locked?: Partial<Record<Capability, boolean>>
  sources?: Partial<Record<Capability, 'admin' | 'group' | 'user'>>
}

export interface SaveDefaultsRequest {
  defaults: Partial<Record<Capability, number>> & { VOICE_LIVE?: number }
  /** Admin only: save the instance defaults guests and members without their own choice use. */
  global?: boolean
}

/** `user`: the signed-in person's defaults. `instance` (admin only): everyone else's. */
export type DefaultsScope = 'user' | 'instance'

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
  // The OpenAPI model object names the chat fields and passes the rest through.
  // Runtime validation keeps priceKnown; the app still uses ModelsResponse
  // because the other capability lists are not spelled out on the schema.
  const data = await httpClient('/api/v1/config/models', {
    schema: GetApiConfigModelsListResponseSchema,
  })
  return data as ModelsResponse
}

/**
 * Get current default model configuration
 */
export const getDefaultModels = async (
  scope: DefaultsScope = 'user'
): Promise<DefaultsResponse> => {
  const query = scope === 'instance' ? '?scope=instance' : ''
  return httpClient<DefaultsResponse>(`/api/v1/config/models/defaults${query}`)
}

export type SaveDefaultsResponse = z.infer<typeof PostApiConfigModelsDefaultsSaveResponseSchema>

/**
 * Save default model configuration. `replaced` lists capabilities whose saved
 * model does not apply, mapped to the model that applies instead.
 */
export const saveDefaultModels = async (
  defaults: SaveDefaultsRequest
): Promise<SaveDefaultsResponse> => {
  return httpClient('/api/v1/config/models/defaults', {
    method: 'POST',
    body: JSON.stringify(defaults),
    schema: PostApiConfigModelsDefaultsSaveResponseSchema,
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

export type PlannerModelResponse = z.infer<typeof GetApiConfigRoutingPlannerModelGetResponseSchema>

export type SavePlannerModelResponse = z.infer<
  typeof PostApiConfigRoutingPlannerModelSaveResponseSchema
>

export interface SaveAgentModelsRequest {
  modelId?: number | null
  readingModelId?: number | null
  checkingModelId?: number | null
}

/**
 * Get the planner and research model selections for the current user.
 */
export const getPlannerModel = async (): Promise<PlannerModelResponse> => {
  return httpClient('/api/v1/config/routing/planner-model', {
    schema: GetApiConfigRoutingPlannerModelGetResponseSchema,
  })
}

/**
 * Save (modelId) or clear (null) the per-user planner or research model overrides.
 */
export const savePlannerModel = async (
  modelIdOrPayload: number | null | SaveAgentModelsRequest
): Promise<SavePlannerModelResponse> => {
  const payload: SaveAgentModelsRequest =
    typeof modelIdOrPayload === 'object' && modelIdOrPayload !== null
      ? modelIdOrPayload
      : { modelId: modelIdOrPayload }
  return httpClient('/api/v1/config/routing/planner-model', {
    method: 'POST',
    body: JSON.stringify(payload),
    schema: PostApiConfigRoutingPlannerModelSaveResponseSchema,
  })
}

/**
 * Legacy admin endpoint for the leftover DEFAULTMODEL.SUMMARIZE slot.
 * Runtime no longer reads that slot — summaries use Text Analytics (ANALYZE).
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
