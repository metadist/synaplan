/**
 * Admin System Configuration API
 *
 * SECURITY: All endpoints require admin access. Sensitive values are always masked.
 */
import { z } from 'zod'
import { httpClient } from './httpClient'

// === Zod Schemas for Runtime Validation ===

const ConfigFieldSchemaZ = z.object({
  tab: z.string(),
  section: z.string(),
  type: z.enum(['text', 'password', 'url', 'email', 'number', 'boolean', 'select']),
  sensitive: z.boolean(),
  description: z.string(),
  default: z.string(),
  source: z.enum(['env', 'database']).optional(),
  options: z.array(z.string()).optional(),
})

const ConfigSectionZ = z.object({
  label: z.string(),
  fields: z.array(z.string()),
})

const ConfigTabZ = z.object({
  label: z.string(),
  sections: z.record(z.string(), ConfigSectionZ),
})

const ConfigSchemaZ = z.object({
  tabs: z.record(z.string(), ConfigTabZ),
  fields: z.record(z.string(), ConfigFieldSchemaZ),
})

const ConfigValueZ = z.object({
  value: z.string(),
  isSet: z.boolean(),
  isMasked: z.boolean(),
})

const ConfigBackupZ = z.object({
  id: z.string(),
  timestamp: z.string(),
  size: z.number(),
})

const TestConnectionResultZ = z.object({
  success: z.boolean(),
  message: z.string(),
  details: z.record(z.string(), z.unknown()).optional().nullable(),
})

// API Response schemas
const GetSchemaResponseZ = z.object({
  success: z.literal(true),
  schema: ConfigSchemaZ,
})

const GetValuesResponseZ = z.object({
  success: z.literal(true),
  values: z.record(z.string(), ConfigValueZ),
})

const UpdateValueResponseZ = z.object({
  success: z.boolean(),
  requiresRestart: z.boolean().optional(),
  error: z.string().optional(),
})

const GetBackupsResponseZ = z.object({
  success: z.literal(true),
  backups: z.array(ConfigBackupZ),
})

const RestoreBackupResponseZ = z.object({
  success: z.boolean(),
  message: z.string(),
})

// === Inferred Types from Schemas ===

export type ConfigFieldSchema = z.infer<typeof ConfigFieldSchemaZ>
export type ConfigSection = z.infer<typeof ConfigSectionZ>
export type ConfigTab = z.infer<typeof ConfigTabZ>
export type ConfigSchema = z.infer<typeof ConfigSchemaZ>
export type ConfigValue = z.infer<typeof ConfigValueZ>
export type ConfigBackup = z.infer<typeof ConfigBackupZ>
export type TestConnectionResult = z.infer<typeof TestConnectionResultZ>

// === API Functions ===

/**
 * Get configuration schema with field definitions.
 */
export async function getConfigSchema(): Promise<ConfigSchema> {
  const response = await httpClient('/api/v1/admin/config/schema', {
    schema: GetSchemaResponseZ,
  })
  return response.schema
}

/**
 * Get current configuration values (sensitive fields masked).
 */
export async function getConfigValues(): Promise<Record<string, ConfigValue>> {
  const response = await httpClient('/api/v1/admin/config/values', {
    schema: GetValuesResponseZ,
  })
  return response.values
}

/**
 * Update a configuration value.
 */
export async function updateConfigValue(
  key: string,
  value: string
): Promise<{ success: boolean; requiresRestart?: boolean; error?: string }> {
  return httpClient('/api/v1/admin/config/values', {
    method: 'PUT',
    body: JSON.stringify({ key, value }),
    schema: UpdateValueResponseZ,
  })
}

/**
 * Test connection to a service.
 */
export async function testConnection(service: string): Promise<TestConnectionResult> {
  return httpClient(`/api/v1/admin/config/test/${service}`, {
    method: 'POST',
    schema: TestConnectionResultZ,
  })
}

/**
 * Get list of available backups.
 */
export async function getConfigBackups(): Promise<ConfigBackup[]> {
  const response = await httpClient('/api/v1/admin/config/backups', {
    schema: GetBackupsResponseZ,
  })
  return response.backups
}

/**
 * Restore configuration from a backup.
 */
export async function restoreConfigBackup(
  backupId: string
): Promise<{ success: boolean; message: string }> {
  return httpClient(`/api/v1/admin/config/restore/${backupId}`, {
    method: 'POST',
    schema: RestoreBackupResponseZ,
  })
}
