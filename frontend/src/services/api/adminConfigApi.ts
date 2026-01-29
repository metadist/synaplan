/**
 * Admin System Configuration API
 *
 * SECURITY: All endpoints require admin access. Sensitive values are always masked.
 */
import { httpClient } from './httpClient'

// === Types ===

export interface ConfigFieldSchema {
  tab: string
  section: string
  type: 'text' | 'password' | 'url' | 'email' | 'number' | 'boolean' | 'select'
  sensitive: boolean
  description: string
  default: string
  options?: string[]
}

export interface ConfigSection {
  label: string
  fields: string[]
}

export interface ConfigTab {
  label: string
  sections: Record<string, ConfigSection>
}

export interface ConfigSchema {
  tabs: Record<string, ConfigTab>
  fields: Record<string, ConfigFieldSchema>
}

export interface ConfigValue {
  value: string
  isSet: boolean
  isMasked: boolean
}

export interface ConfigBackup {
  id: string
  timestamp: string
  size: number
}

export interface TestConnectionResult {
  success: boolean
  message: string
  details?: Record<string, unknown>
}

// === API Functions ===

/**
 * Get configuration schema with field definitions.
 */
export async function getConfigSchema(): Promise<ConfigSchema> {
  const response = await httpClient<{ success: boolean; schema: ConfigSchema }>(
    '/api/v1/admin/config/schema'
  )
  return response.schema
}

/**
 * Get current configuration values (sensitive fields masked).
 */
export async function getConfigValues(): Promise<Record<string, ConfigValue>> {
  const response = await httpClient<{ success: boolean; values: Record<string, ConfigValue> }>(
    '/api/v1/admin/config/values'
  )
  return response.values
}

/**
 * Update a configuration value.
 */
export async function updateConfigValue(
  key: string,
  value: string
): Promise<{ success: boolean; requiresRestart: boolean }> {
  return httpClient<{ success: boolean; requiresRestart: boolean }>('/api/v1/admin/config/values', {
    method: 'PUT',
    body: JSON.stringify({ key, value }),
  })
}

/**
 * Test connection to a service.
 */
export async function testConnection(service: string): Promise<TestConnectionResult> {
  return httpClient<TestConnectionResult>(`/api/v1/admin/config/test/${service}`, {
    method: 'POST',
  })
}

/**
 * Get list of available backups.
 */
export async function getConfigBackups(): Promise<ConfigBackup[]> {
  const response = await httpClient<{ success: boolean; backups: ConfigBackup[] }>(
    '/api/v1/admin/config/backups'
  )
  return response.backups
}

/**
 * Restore configuration from a backup.
 */
export async function restoreConfigBackup(
  backupId: string
): Promise<{ success: boolean; message: string }> {
  return httpClient<{ success: boolean; message: string }>(
    `/api/v1/admin/config/restore/${backupId}`,
    {
      method: 'POST',
    }
  )
}
