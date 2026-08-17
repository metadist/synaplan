import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiConnectionsListResponseSchema,
  PatchApiConnectionsUpdateResponseSchema,
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
  /** Prompt-safe name the planner and the user use ("nextcloud", "calendar"). */
  channel?: string
}

export interface ConnectionTestResult {
  connection: ConnectionItem
  /** Null for types without a tester: those only confirm a stored credential. */
  succeeded: boolean | null
  /** Readable reason when the test failed, straight from the tester. */
  error: string | null
  /** Remote account the connection points at, when the tester can report one. */
  account: string | null
}

function channelName(raw: RawConnection): string | undefined {
  if (typeof raw.channel === 'string' && raw.channel.trim() !== '') {
    return raw.channel
  }
  const fromConfig = raw.config && typeof raw.config === 'object' ? raw.config.channel : undefined
  return typeof fromConfig === 'string' && fromConfig.trim() !== '' ? fromConfig : undefined
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
    channel: channelName(raw),
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

  async test(id: string): Promise<ConnectionTestResult> {
    const data = await httpClient(`/api/v1/connections/${id}/test`, {
      method: 'POST',
      schema: PostApiConnectionsTestResponseSchema,
    })
    return {
      connection: asConnection(data.connection),
      succeeded: data.connection?.test_succeeded ?? null,
      error: data.connection?.test_error ?? null,
      account: data.connection?.account ?? null,
    }
  },

  async update(
    id: string,
    payload: { name?: string; secret?: string; config?: Record<string, unknown> }
  ): Promise<ConnectionItem> {
    const data = await httpClient(`/api/v1/connections/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
      schema: PatchApiConnectionsUpdateResponseSchema,
    })
    return asConnection(data.connection)
  },

  async remove(id: string): Promise<void> {
    await httpClient(`/api/v1/connections/${id}`, { method: 'DELETE' })
  },
}
