/**
 * Admin Provider Keys API — first-run setup wizard backend.
 *
 * SECURITY: All endpoints require admin access. API keys are write-only:
 * responses only ever carry a masked hint (e.g. "gsk_••••••••••••abcd").
 */
import type { z } from 'zod'
import {
  DeleteAdminProviderKeysDeleteResponseSchema,
  GetAdminProviderKeysListResponseSchema,
  PostAdminProviderKeysApplyDefaultsResponseSchema,
  PostAdminProviderKeysTestResponseSchema,
  PutAdminProviderKeysSaveResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ProviderKeysList = z.infer<typeof GetAdminProviderKeysListResponseSchema>
export type ProviderKeyStatus = ProviderKeysList['providers'][number]
export type TestProviderKeyResult = z.infer<typeof PostAdminProviderKeysTestResponseSchema>
export type SaveProviderKeyResult = z.infer<typeof PutAdminProviderKeysSaveResponseSchema>
export type ApplyProviderDefaultsResult = z.infer<
  typeof PostAdminProviderKeysApplyDefaultsResponseSchema
>
export type DeleteProviderKeyResult = z.infer<typeof DeleteAdminProviderKeysDeleteResponseSchema>

/**
 * List all supported cloud AI providers and their key status.
 */
export async function listProviderKeys(): Promise<ProviderKeysList> {
  return httpClient('/api/v1/admin/provider-keys', {
    schema: GetAdminProviderKeysListResponseSchema,
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
): Promise<SaveProviderKeyResult> {
  return httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}`, {
    method: 'PUT',
    body: JSON.stringify({ key, applyDefaults: options.applyDefaults ?? false }),
    schema: PutAdminProviderKeysSaveResponseSchema,
  })
}

/**
 * Delete the stored key. When the provider's environment variable is still set,
 * the response says so: the provider stays configured from that value.
 */
export async function deleteProviderKey(provider: string): Promise<DeleteProviderKeyResult> {
  return httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}`, {
    method: 'DELETE',
    schema: DeleteAdminProviderKeysDeleteResponseSchema,
  })
}

/**
 * Live-test the currently configured key of a provider.
 */
export async function testProviderKey(provider: string): Promise<TestProviderKeyResult> {
  return httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}/test`, {
    method: 'POST',
    schema: PostAdminProviderKeysTestResponseSchema,
  })
}

/**
 * Apply the recommended global default models for a provider.
 */
export async function applyProviderDefaults(
  provider: string
): Promise<ApplyProviderDefaultsResult> {
  return httpClient(`/api/v1/admin/provider-keys/${encodeURIComponent(provider)}/apply-defaults`, {
    method: 'POST',
    schema: PostAdminProviderKeysApplyDefaultsResponseSchema,
  })
}
