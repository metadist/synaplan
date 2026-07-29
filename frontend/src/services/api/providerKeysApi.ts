/**
 * Admin Provider Keys API — first-run setup wizard backend.
 *
 * SECURITY: All endpoints require admin access. API keys are write-only:
 * responses only ever carry a masked hint (e.g. "gsk_••••••••••••abcd").
 */
import { z } from 'zod'
import { httpClient } from './httpClient'

// === Zod Schemas for Runtime Validation ===

const ProviderKeyStatusZ = z.object({
  name: z.string(),
  displayName: z.string(),
  configured: z.boolean(),
  source: z.enum(['db', 'env', 'none']),
  origin: z.enum(['env', 'ui']).nullable(),
  maskedKey: z.string(),
  consoleUrl: z.string(),
  envVar: z.string(),
  freeTier: z.boolean(),
  recommended: z.boolean(),
})

const ListProviderKeysResponseZ = z.object({
  success: z.literal(true),
  defaultChatProvider: z.string(),
  providers: z.array(ProviderKeyStatusZ),
})

const SaveProviderKeyResponseZ = z.object({
  success: z.literal(true),
  provider: z.string(),
  maskedKey: z.string(),
  defaultsApplied: z.boolean(),
})

const TestProviderKeyResponseZ = z.object({
  ok: z.boolean(),
  status: z.number().nullable().optional(),
  error: z.string().nullable().optional(),
})

const ApplyDefaultsResponseZ = z.object({
  success: z.literal(true),
  provider: z.string(),
  capabilities: z.array(z.string()),
})

const DeleteProviderKeyResponseZ = z.object({
  success: z.literal(true),
})

// === Inferred Types ===

export type ProviderKeyStatus = z.infer<typeof ProviderKeyStatusZ>
export type ProviderKeysList = z.infer<typeof ListProviderKeysResponseZ>
export type TestProviderKeyResult = z.infer<typeof TestProviderKeyResponseZ>

// === API Functions ===

/**
 * List all supported cloud AI providers and their key status.
 */
export async function listProviderKeys(): Promise<ProviderKeysList> {
  return httpClient('/api/v1/admin/provider-keys', {
    schema: ListProviderKeysResponseZ,
  })
}

/**
 * Validate (live, against the provider API) and store an API key.
 * Keys are stored encrypted and apply without a restart.
 */
export async function saveProviderKey(
  provider: string,
  key: string,
  options: { applyDefaults?: boolean } = {}
): Promise<z.infer<typeof SaveProviderKeyResponseZ>> {
  return httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}`, {
    method: 'PUT',
    body: JSON.stringify({ key, applyDefaults: options.applyDefaults ?? false }),
    schema: SaveProviderKeyResponseZ,
  })
}

/**
 * Delete the stored key (falls back to the env var, if set).
 */
export async function deleteProviderKey(provider: string): Promise<void> {
  await httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}`, {
    method: 'DELETE',
    schema: DeleteProviderKeyResponseZ,
  })
}

/**
 * Live-test the currently configured key of a provider.
 */
export async function testProviderKey(provider: string): Promise<TestProviderKeyResult> {
  return httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}/test`, {
    method: 'POST',
    schema: TestProviderKeyResponseZ,
  })
}

/**
 * Apply the recommended global default models for a provider.
 */
export async function applyProviderDefaults(
  provider: string
): Promise<z.infer<typeof ApplyDefaultsResponseZ>> {
  return httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}/apply-defaults`, {
    method: 'POST',
    schema: ApplyDefaultsResponseZ,
  })
}
