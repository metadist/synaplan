import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  DeleteApiUrlWatchesDeleteResponseSchema,
  GetApiUrlWatchesGetResponseSchema,
  GetApiUrlWatchesListResponseSchema,
  PostApiUrlWatchesCreateResponseSchema,
  PostApiUrlWatchesRefreshResponseSchema,
} from '@/generated/api-schemas'

type RawListWatch = NonNullable<
  z.infer<typeof GetApiUrlWatchesListResponseSchema>['watches']
>[number]
type RawDetailWatch = z.infer<typeof GetApiUrlWatchesGetResponseSchema>['watch']

export interface UrlWatch {
  id: number
  url: string
  title: string
  preview: string
  fetchedAt: string | null
  created: string | null
  updated: string | null
  body: string
}

export interface UrlWatchCompare {
  status: string
  diffText: string
}

function asWatch(raw: RawListWatch | RawDetailWatch | undefined): UrlWatch {
  if (!raw || raw.id == null || !raw.url) {
    throw new Error('Malformed URL watch response')
  }
  return {
    id: raw.id,
    url: raw.url,
    title: raw.title ?? '',
    preview: raw.preview ?? '',
    fetchedAt: raw.fetchedAt ?? null,
    created: raw.created ?? null,
    updated: raw.updated ?? null,
    body: 'body' in raw && typeof raw.body === 'string' ? raw.body : '',
  }
}

export const urlWatchesApi = {
  async list(): Promise<UrlWatch[]> {
    const data = await httpClient('/api/v1/url-watches', {
      schema: GetApiUrlWatchesListResponseSchema,
    })
    return (data.watches ?? []).map((watch) => asWatch(watch))
  },

  async create(url: string): Promise<UrlWatch> {
    const data = await httpClient('/api/v1/url-watches', {
      method: 'POST',
      body: JSON.stringify({ url }),
      schema: PostApiUrlWatchesCreateResponseSchema,
    })
    return asWatch(data.watch)
  },

  async get(id: number): Promise<UrlWatch> {
    const data = await httpClient(`/api/v1/url-watches/${id}`, {
      schema: GetApiUrlWatchesGetResponseSchema,
    })
    return asWatch(data.watch)
  },

  async refresh(id: number): Promise<{ watch: UrlWatch; compare: UrlWatchCompare }> {
    const data = await httpClient(`/api/v1/url-watches/${id}/refresh`, {
      method: 'POST',
      schema: PostApiUrlWatchesRefreshResponseSchema,
    })
    return {
      watch: asWatch(data.watch),
      compare: {
        status: data.compare?.status ?? 'first_save',
        diffText: data.compare?.diffText ?? '',
      },
    }
  },

  async remove(id: number): Promise<void> {
    await httpClient(`/api/v1/url-watches/${id}`, {
      method: 'DELETE',
      schema: DeleteApiUrlWatchesDeleteResponseSchema,
    })
  },
}
