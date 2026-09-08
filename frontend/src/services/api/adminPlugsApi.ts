import type { z } from 'zod'
import {
  GetAdminPlugsExtractionStatusResponseSchema,
  PostAdminPlugsExtractionTestResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ExtractionStatus = z.infer<typeof GetAdminPlugsExtractionStatusResponseSchema>
export type ExtractionTestResult = z.infer<typeof PostAdminPlugsExtractionTestResponseSchema>

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
