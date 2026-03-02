import { api } from './apiService'

export interface FeatureEnvVar {
  required: boolean
  set: boolean
  hint: string
}

export interface Feature {
  id: string
  category: string
  name: string
  enabled: boolean
  status: 'active' | 'disabled' | 'healthy' | 'unhealthy'
  message: string
  setup_required: boolean
  env_vars?: Record<string, FeatureEnvVar>
  models_available?: number
  url?: string
  version?: string
}

export interface FeaturesStatus {
  features: Record<string, Feature>
  summary: {
    total: number
    healthy: number
    unhealthy: number
    all_ready: boolean
  }
}

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
    return response.data
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
