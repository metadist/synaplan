import type { z } from 'zod'
import {
  DeleteAdminPlugsKeysDeleteResponseSchema,
  GetAdminPlugsExtractionStatusResponseSchema,
  GetAdminPlugsWebSearchStatusResponseSchema,
  PostAdminPlugsExtractionTestResponseSchema,
  PostAdminPlugsWebSearchTestResponseSchema,
  PutAdminPlugsKeysSaveResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ExtractionStatus = z.infer<typeof GetAdminPlugsExtractionStatusResponseSchema>
export type ExtractionTestResult = z.infer<typeof PostAdminPlugsExtractionTestResponseSchema>
export type WebSearchStatus = z.infer<typeof GetAdminPlugsWebSearchStatusResponseSchema>
export type WebSearchTestResult = z.infer<typeof PostAdminPlugsWebSearchTestResponseSchema>
export type PlugKeyStatus = z.infer<typeof PutAdminPlugsKeysSaveResponseSchema>

export async function getExtractionStatus(): Promise<ExtractionStatus> {
  return httpClient('/api/v1/admin/plugs/extraction', {
    schema: GetAdminPlugsExtractionStatusResponseSchema,
  })
}

export async function saveExtractionChains(
  chains: Record<string, string[]>
): Promise<ExtractionStatus> {
  return httpClient('/api/v1/admin/plugs/extraction/chains', {
    method: 'PUT',
    body: JSON.stringify({ chains }),
    schema: GetAdminPlugsExtractionStatusResponseSchema,
  })
}

export async function testExtraction(file: File): Promise<ExtractionTestResult> {
  const body = new FormData()
  body.append('file', file)
  return httpClient('/api/v1/admin/plugs/extraction/test', {
    method: 'POST',
    body,
    schema: PostAdminPlugsExtractionTestResponseSchema,
  })
}

export async function getWebSearchStatus(): Promise<WebSearchStatus> {
  return httpClient('/api/v1/admin/plugs/web-search', {
    schema: GetAdminPlugsWebSearchStatusResponseSchema,
  })
}

export async function saveWebSearch(payload: {
  active: string
  fallback: string
  userOverrideAllowed: boolean
}): Promise<WebSearchStatus> {
  return httpClient('/api/v1/admin/plugs/web-search', {
    method: 'PUT',
    body: JSON.stringify(payload),
    schema: GetAdminPlugsWebSearchStatusResponseSchema,
  })
}

export async function testWebSearch(provider: string, query: string): Promise<WebSearchTestResult> {
  return httpClient('/api/v1/admin/plugs/web-search/test', {
    method: 'POST',
    body: JSON.stringify({ provider, query }),
    schema: PostAdminPlugsWebSearchTestResponseSchema,
  })
}

export async function savePlugKey(provider: string, key: string): Promise<PlugKeyStatus> {
  return httpClient(`/api/v1/admin/plugs/keys/${encodeURIComponent(provider)}`, {
    method: 'PUT',
    body: JSON.stringify({ key }),
    schema: PutAdminPlugsKeysSaveResponseSchema,
  })
}

export async function deletePlugKey(provider: string): Promise<PlugKeyStatus> {
  return httpClient(`/api/v1/admin/plugs/keys/${encodeURIComponent(provider)}`, {
    method: 'DELETE',
    schema: DeleteAdminPlugsKeysDeleteResponseSchema,
  })
}
