import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiAppsNotionAccountResponseSchema,
  GetApiAppsNotionStatusResponseSchema,
  PatchApiAppsNotionWritingResponseSchema,
  PostApiAppsNotionConnectResponseSchema,
} from '@/generated/api-schemas'

export type NotionAppStatus = z.infer<typeof GetApiAppsNotionStatusResponseSchema>
export type NotionAppAccount = z.infer<typeof GetApiAppsNotionAccountResponseSchema>

export const notionAppApi = {
  async status(): Promise<NotionAppStatus> {
    return httpClient('/api/v1/apps/notion', {
      method: 'GET',
      schema: GetApiAppsNotionStatusResponseSchema,
    })
  },

  async account(): Promise<NotionAppAccount> {
    return httpClient('/api/v1/apps/notion/account', {
      method: 'GET',
      schema: GetApiAppsNotionAccountResponseSchema,
    })
  },

  async connect(): Promise<string> {
    const data = await httpClient('/api/v1/apps/notion/connect', {
      method: 'POST',
      schema: PostApiAppsNotionConnectResponseSchema,
    })
    return data.authorize_url
  },

  async setWriting(allowWrite: boolean): Promise<NotionAppStatus> {
    return httpClient('/api/v1/apps/notion', {
      method: 'PATCH',
      body: JSON.stringify({ allow_write: allowWrite }),
      schema: PatchApiAppsNotionWritingResponseSchema,
    })
  },
}
