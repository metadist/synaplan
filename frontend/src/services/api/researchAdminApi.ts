import { z } from 'zod'
import {
  GetAdminResearchResponseSchema,
  PostAdminResearchSampleResponseSchema,
  PutAdminResearchResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ResearchAdmin = z.infer<typeof GetAdminResearchResponseSchema>
export type ResearchSample = z.infer<typeof PostAdminResearchSampleResponseSchema>

export function fetchResearchAdmin(): Promise<ResearchAdmin> {
  return httpClient('/api/v1/admin/research', { schema: GetAdminResearchResponseSchema })
}

export function saveResearchAdmin(body: Record<string, unknown>): Promise<ResearchAdmin> {
  return httpClient('/api/v1/admin/research', {
    method: 'PUT',
    body: JSON.stringify(body),
    schema: PutAdminResearchResponseSchema,
  })
}

export function startResearchSample(): Promise<ResearchSample> {
  return httpClient('/api/v1/admin/research/sample', {
    method: 'POST',
    schema: PostAdminResearchSampleResponseSchema,
  })
}
