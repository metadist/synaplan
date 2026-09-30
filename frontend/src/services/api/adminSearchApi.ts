import type { z } from 'zod'
import {
  GetAdminSearchConfigResponseSchema,
  PutAdminSearchConfigResponseSchema,
  type putAdminSearchConfig_Body,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type AdminSearchConfig = z.infer<typeof GetAdminSearchConfigResponseSchema>
export type AdminSearchSlot = z.infer<typeof putAdminSearchConfig_Body>['slot']
export type AdminSearchChange = z.infer<typeof PutAdminSearchConfigResponseSchema>

export function getAdminSearchConfig(): Promise<AdminSearchConfig> {
  return httpClient('/api/v1/admin/search/config', {
    schema: GetAdminSearchConfigResponseSchema,
  })
}

export function putAdminSearchConfig(
  slot: AdminSearchSlot,
  modelId: number | null
): Promise<AdminSearchChange> {
  return httpClient('/api/v1/admin/search/config', {
    method: 'PUT',
    body: JSON.stringify({ slot, modelId }),
    schema: PutAdminSearchConfigResponseSchema,
  })
}
