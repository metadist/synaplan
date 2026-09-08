import type { z } from 'zod'
import { GetUserPlugsWebSearchStatusResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type UserWebSearchStatus = z.infer<typeof GetUserPlugsWebSearchStatusResponseSchema>

export async function getUserWebSearch(): Promise<UserWebSearchStatus> {
  return httpClient('/api/v1/config/plugs/web-search', {
    schema: GetUserPlugsWebSearchStatusResponseSchema,
  })
}

export async function saveUserWebSearch(provider: string | null): Promise<UserWebSearchStatus> {
  return httpClient('/api/v1/config/plugs/web-search', {
    method: 'PUT',
    body: JSON.stringify({ provider }),
    schema: GetUserPlugsWebSearchStatusResponseSchema,
  })
}
