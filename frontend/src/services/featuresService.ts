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
  env_vars: z
    .union([z.record(z.string(), FeatureEnvVarSchema), z.array(z.unknown())])
    .optional()
    .transform((val) => (Array.isArray(val) ? undefined : val)),
  models_available: z.number().optional(),
  url: z.string().nullable().optional(),
  version: z.string().nullable().optional(),
})

/**
 * One declared feature module (`App\Module\ModuleStatusPresenter::row()`).
 * Optional on the response so a backend without the module registry still
 * renders the legacy feature list.
 */
const FeatureModuleSchema = z.object({
  id: z.string(),
  label_key: z.string(),
  state: z.enum(['absent', 'available', 'needs_setup']),
  configured: z.boolean(),
  healthy: z.boolean(),
  message: z.string(),
  // PHP serialises an empty details array as `[]`, a filled one as an object.
  details: z
    .union([z.record(z.string(), z.unknown()), z.array(z.unknown())])
    .transform((val) => (Array.isArray(val) ? {} : val)),
  configured_by: z.object({
    env: z.array(z.string()),
    bconfig: z.array(z.string()),
    providers: z.array(z.string()),
    plugs: z.array(z.string()),
  }),
  capabilities: z.array(z.string()),
  docs_anchor: z.string(),
  mobile_class: z.enum(['backend-only', 'ota-candidate']),
})

const FeaturesStatusSchema = z.object({
  features: z.record(z.string(), FeatureSchema),
  summary: z.object({
    total: z.number(),
    healthy: z.number(),
    unhealthy: z.number(),
    all_ready: z.boolean(),
  }),
  modules: z.array(FeatureModuleSchema).optional(),
})

export type FeatureEnvVar = z.infer<typeof FeatureEnvVarSchema>
export type Feature = z.infer<typeof FeatureSchema>
export type FeatureModule = z.infer<typeof FeatureModuleSchema>
export type FeaturesStatus = z.infer<typeof FeaturesStatusSchema>

/** Thrown when `/api/v1/config/features` returns 403 (non-admin caller). */
export class FeatureStatusForbiddenError extends Error {
  constructor() {
    super('Feature status requires admin access')
    this.name = 'FeatureStatusForbiddenError'
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
    if (error instanceof Error && error.message.startsWith('API Error: 403')) {
      throw new FeatureStatusForbiddenError()
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
