import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiConnectionsListResponseSchema,
  PostApiConnectionsCreateResponseSchema,
  PostApiConnectionsTestResponseSchema,
} from '@/generated/api-schemas'

type RawConnection = NonNullable<
  z.infer<typeof GetApiConnectionsListResponseSchema>['connections']
>[number]

export interface ConnectionItem {
  id: string
  source: string
  type: string
  name: string
  status: string
  last_checked: number | null
  has_secret: boolean
  manage_path?: string
  config?: Record<string, unknown> | null
}

function asConnection(raw: RawConnection | undefined): ConnectionItem {
  if (!raw || !raw.id || !raw.name) {
    throw new Error('Malformed connection response')
  }
  return {
    id: raw.id,
    source: raw.source ?? 'registry',
    type: raw.type ?? 'generic',
    name: raw.name,
    status: raw.status ?? 'never_tested',
    last_checked: raw.last_checked ?? null,
    has_secret: raw.has_secret === true,
    manage_path: raw.manage_path,
    config: raw.config ?? null,
  }
}

export const connectionsApi = {
  async list(): Promise<ConnectionItem[]> {
    const data = await httpClient('/api/v1/connections', {
      schema: GetApiConnectionsListResponseSchema,
    })
    return (data.connections ?? []).map((row) => asConnection(row))
  },

  async create(payload: {
    name: string
    type: string
    secret?: string
    config?: Record<string, unknown>
  }): Promise<ConnectionItem> {
    const data = await httpClient('/api/v1/connections', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: PostApiConnectionsCreateResponseSchema,
    })
    return asConnection(data.connection)
  },

  async test(id: string): Promise<ConnectionItem> {
    const data = await httpClient(`/api/v1/connections/${id}/test`, {
      method: 'POST',
      schema: PostApiConnectionsTestResponseSchema,
    })
    return asConnection(data.connection)
  },

  async remove(id: string): Promise<void> {
    await httpClient(`/api/v1/connections/${id}`, { method: 'DELETE' })
  },
}
