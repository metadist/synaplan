/**
 * Provider catalog API — `GET /api/v1/ai/providers`.
 *
 * The public subset of every provider's descriptor: names, capabilities,
 * branding (icon, flag, bundled glyph), help hint and which credential a key
 * row asks for. Readable by every signed-in user; it carries no secret.
 */
import type { z } from 'zod'
import { GetAiProvidersListResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ProviderCatalogResponse = z.infer<typeof GetAiProvidersListResponseSchema>
export type CatalogProvider = ProviderCatalogResponse['providers'][number]

export async function fetchProviderCatalog(): Promise<ProviderCatalogResponse> {
  return httpClient('/api/v1/ai/providers', {
    schema: GetAiProvidersListResponseSchema,
  })
}
