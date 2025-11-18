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

/**
 * Get status of all optional features
 */
export async function getFeaturesStatus(): Promise<FeaturesStatus> {
  const response = await api.get<FeaturesStatus>('/api/v1/config/features')
  return response.data // Extrahiere data aus der Response
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

