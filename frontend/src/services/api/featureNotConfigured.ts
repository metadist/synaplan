import { z } from 'zod'
import { ApiError } from '@/services/api/httpClient'

/**
 * Body of the uniform 404 that `App\Module\Http\ModuleGateListener` returns on
 * the route of an absent feature module whose gate is on.
 */
export const FEATURE_NOT_CONFIGURED = 'feature_not_configured'

const FeatureNotConfiguredBodySchema = z.object({
  error: z.literal(FEATURE_NOT_CONFIGURED),
  module: z.string().min(1),
  docs: z.string(),
})

export type FeatureNotConfigured = z.infer<typeof FeatureNotConfiguredBodySchema>

/**
 * Typed view of a failed request: the module id and docs anchor when the error
 * is the module gate's 404, null for every other error.
 */
export function featureNotConfigured(error: unknown): FeatureNotConfigured | null {
  if (!(error instanceof ApiError) || error.status !== 404) {
    return null
  }
  const parsed = FeatureNotConfiguredBodySchema.safeParse(error.details)
  return parsed.success ? parsed.data : null
}
