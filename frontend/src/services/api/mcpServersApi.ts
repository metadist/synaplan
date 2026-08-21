import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiMcpServersListResponseSchema,
  PostApiMcpServersCreateResponseSchema,
  PatchApiMcpServersUpdateResponseSchema,
  DeleteApiMcpServersDeleteResponseSchema,
  PostApiMcpServersTestResponseSchema,
  GetApiMcpServersToolsResponseSchema,
} from '@/generated/api-schemas'

/**
 * Settings → Connections → MCP servers (release 4.0 external data nodes).
 *
 * The auth header value is WRITE-ONLY: it is sent on create/update but never
 * returned by the API — responses only carry `has_auth_token`.
 */

export type McpServer = NonNullable<
  z.infer<typeof GetApiMcpServersListResponseSchema>['servers']
>[number]

export type McpTool = NonNullable<
  z.infer<typeof PostApiMcpServersTestResponseSchema>['tools']
>[number]

export interface McpServerPayload {
  name?: string
  url?: string
  auth_header?: string
  /** Absent = keep the stored secret; empty string = clear it. */
  auth_token?: string
  enabled?: boolean
  /** Opt-in: let the AI call mutating tools (create/update) on this server. */
  allow_write?: boolean
  auth_mode?: 'none' | 'bearer' | 'oauth'
}

const StartOAuthResponseSchema = z.object({
  success: z.boolean().optional(),
  authorize_url: z.string().optional(),
  error: z.string().optional(),
})

const DisconnectOAuthResponseSchema = z.object({
  success: z.boolean().optional(),
})

export const mcpServersApi = {
  async list(): Promise<{
    clientEnabled: boolean
    oauthConnectorsEnabled: boolean
    servers: McpServer[]
  }> {
    const data = await httpClient('/api/v1/mcp-servers', {
      method: 'GET',
      schema: GetApiMcpServersListResponseSchema,
    })
    return {
      clientEnabled: data.client_enabled ?? false,
      oauthConnectorsEnabled: data.oauth_connectors_enabled === true,
      servers: data.servers ?? [],
    }
  },

  async create(payload: McpServerPayload): Promise<McpServer> {
    const data = await httpClient('/api/v1/mcp-servers', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: PostApiMcpServersCreateResponseSchema,
    })
    if (!data.server) throw new Error('Malformed create response')
    return data.server
  },

  async update(id: number, payload: McpServerPayload): Promise<McpServer> {
    const data = await httpClient(`/api/v1/mcp-servers/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
      schema: PatchApiMcpServersUpdateResponseSchema,
    })
    if (!data.server) throw new Error('Malformed update response')
    return data.server
  },

  async remove(id: number): Promise<void> {
    await httpClient(`/api/v1/mcp-servers/${id}`, {
      method: 'DELETE',
      schema: DeleteApiMcpServersDeleteResponseSchema,
    })
  },

  /** Live connection test: initialize + tool discovery. */
  async test(id: number): Promise<{ success: boolean; tools: McpTool[]; error?: string }> {
    const data = await httpClient(`/api/v1/mcp-servers/${id}/test`, {
      method: 'POST',
      schema: PostApiMcpServersTestResponseSchema,
    })
    return {
      success: data.success ?? false,
      tools: data.tools ?? [],
      error: data.error ?? undefined,
    }
  },

  async tools(id: number): Promise<McpTool[]> {
    const data = await httpClient(`/api/v1/mcp-servers/${id}/tools`, {
      method: 'GET',
      schema: GetApiMcpServersToolsResponseSchema,
    })
    return data.tools ?? []
  },

  async startOAuth(id: number): Promise<string> {
    const data = await httpClient(`/api/v1/mcp-servers/${id}/oauth/start`, {
      method: 'POST',
      schema: StartOAuthResponseSchema,
    })
    if (!data.authorize_url) {
      throw new Error(data.error || 'Could not start sign-in')
    }
    return data.authorize_url
  },

  async disconnectOAuth(id: number): Promise<void> {
    await httpClient(`/api/v1/mcp-servers/${id}/oauth/disconnect`, {
      method: 'POST',
      schema: DisconnectOAuthResponseSchema,
    })
  },
}
