import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  DeleteApiAppsNotionWatchesDeleteResponseSchema,
  GetApiAppsNotionAccountResponseSchema,
  GetApiAppsNotionContentActivityResponseSchema,
  GetApiAppsNotionStatusResponseSchema,
  GetApiAppsNotionWatchesListResponseSchema,
  PatchApiAppsNotionSharingResponseSchema,
  PatchApiAppsNotionWatchesUpdateResponseSchema,
  PatchApiAppsNotionWritingResponseSchema,
  PostApiAppsNotionConnectResponseSchema,
  PostApiAppsNotionContentFilesResponseSchema,
  PostApiAppsNotionSharedUseResponseSchema,
  PostApiAppsNotionWatchesCreateResponseSchema,
} from '@/generated/api-schemas'

export type NotionAppStatus = z.infer<typeof GetApiAppsNotionStatusResponseSchema>
export type NotionAppAccount = z.infer<typeof GetApiAppsNotionAccountResponseSchema>
export type NotionActivityItem = z.infer<
  typeof GetApiAppsNotionContentActivityResponseSchema
>['items'][number]
export type NotionWatchList = z.infer<typeof GetApiAppsNotionWatchesListResponseSchema>
export type NotionWatch = NotionWatchList['watches'][number]
export type NotionFilePushResult = z.infer<typeof PostApiAppsNotionContentFilesResponseSchema>

export interface NotionWatchInput {
  page: string
  action: NotionWatch['action']
  folder?: string | null
  task_id?: number | null
}

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

  async setShared(shared: boolean): Promise<NotionAppStatus> {
    return httpClient('/api/v1/apps/notion/sharing', {
      method: 'PATCH',
      body: JSON.stringify({ shared }),
      schema: PatchApiAppsNotionSharingResponseSchema,
    })
  },

  async useShared(): Promise<void> {
    await httpClient('/api/v1/apps/notion/shared/use', {
      method: 'POST',
      schema: PostApiAppsNotionSharedUseResponseSchema,
    })
  },

  async activity(): Promise<NotionActivityItem[]> {
    const data = await httpClient('/api/v1/apps/notion/activity', {
      method: 'GET',
      schema: GetApiAppsNotionContentActivityResponseSchema,
    })
    return data.items
  },

  async pushFile(fileId: number, page: string): Promise<NotionFilePushResult> {
    return httpClient('/api/v1/apps/notion/files', {
      method: 'POST',
      body: JSON.stringify({ file_id: fileId, page }),
      schema: PostApiAppsNotionContentFilesResponseSchema,
    })
  },

  async watches(): Promise<NotionWatchList> {
    return httpClient('/api/v1/apps/notion/watches', {
      method: 'GET',
      schema: GetApiAppsNotionWatchesListResponseSchema,
    })
  },

  async createWatch(input: NotionWatchInput): Promise<NotionWatch> {
    const data = await httpClient('/api/v1/apps/notion/watches', {
      method: 'POST',
      body: JSON.stringify(input),
      schema: PostApiAppsNotionWatchesCreateResponseSchema,
    })
    return data.watch
  },

  async setWatchEnabled(id: number, enabled: boolean): Promise<NotionWatch> {
    const data = await httpClient(`/api/v1/apps/notion/watches/${id}`, {
      method: 'PATCH',
      body: JSON.stringify({ enabled }),
      schema: PatchApiAppsNotionWatchesUpdateResponseSchema,
    })
    return data.watch
  },

  async deleteWatch(id: number): Promise<void> {
    await httpClient(`/api/v1/apps/notion/watches/${id}`, {
      method: 'DELETE',
      schema: DeleteApiAppsNotionWatchesDeleteResponseSchema,
    })
  },
}
