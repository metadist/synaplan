import { z } from 'zod'
import { api } from './apiService'

const FeatureEnvVarSchema = z.object({
  required: z.boolean(),
  set: z.boolean(),
  hint: z.string(),
})

const FeatureSchema = z.object({
  id: z.string(),
  category: z.string(),
  name: z.string(),
  enabled: z.boolean(),
  status: z.enum(['active', 'disabled', 'healthy', 'unhealthy']),
  message: z.string(),
  setup_required: z.boolean(),
  env_vars: z.record(z.string(), FeatureEnvVarSchema).optional(),
  models_available: z.number().optional(),
  url: z.string().optional(),
  version: z.string().optional(),
})

const FeaturesStatusSchema = z.object({
  features: z.record(z.string(), FeatureSchema),
  summary: z.object({
    total: z.number(),
    healthy: z.number(),
    unhealthy: z.number(),
    all_ready: z.boolean(),
  }),
})

export type FeatureEnvVar = z.infer<typeof FeatureEnvVarSchema>
export type Feature = z.infer<typeof FeatureSchema>
export type FeaturesStatus = z.infer<typeof FeaturesStatusSchema>

export class DevOnlyFeatureError extends Error {
  constructor() {
    super('Feature only available in development mode')
    this.name = 'DevOnlyFeatureError'
  }
}

/**
 * Get status of all optional features
 */
export async function getFeaturesStatus(): Promise<FeaturesStatus> {
  try {
    const response = await api.get<FeaturesStatus>('/api/v1/config/features')
    return FeaturesStatusSchema.parse(response.data)
  } catch (error) {
    if (error instanceof Error && error.message.includes('403')) {
      throw new DevOnlyFeatureError()
    }
    throw error
  }
}

/**
 * Check if a specific feature is enabled
 */
export async function isFeatureEnabled(featureId: string): Promise<boolean> {
  try {
    const status = await getFeaturesStatus()
    return status.features[featureId]?.enabled ?? false
  } catch (error) {
    console.error(`Failed to check feature ${featureId}:`, error)
    return false
  }
}
