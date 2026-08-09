/**
 * Messages Gateway API (Channels → AI Agents)
 *
 * Backend endpoints under /api/v1/messages-gateway.
 */

import type { z } from 'zod'
import {
  DeleteApiMessagesGatewayDeleteKeyResponseSchema,
  GetApiMessagesGatewayStatusResponseSchema,
  PutApiMessagesGatewayPutAliasesResponseSchema,
  PutApiMessagesGatewayPutFlagsResponseSchema,
  PutApiMessagesGatewayPutKeyResponseSchema,
  PutApiMessagesGatewayPutUpstreamResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type MessagesGatewayStatus = z.infer<typeof GetApiMessagesGatewayStatusResponseSchema>
export type MessagesGatewayProvider = 'anthropic' | 'openai' | 'google'

const BASE = '/api/v1/messages-gateway'

export async function getMessagesGatewayStatus(): Promise<MessagesGatewayStatus> {
  return httpClient(BASE, {
    method: 'GET',
    schema: GetApiMessagesGatewayStatusResponseSchema,
  })
}

export async function saveMessagesGatewayKey(
  provider: MessagesGatewayProvider,
  apiKey: string
): Promise<z.infer<typeof PutApiMessagesGatewayPutKeyResponseSchema>> {
  return httpClient(`${BASE}/keys/${provider}`, {
    method: 'PUT',
    body: JSON.stringify({ api_key: apiKey }),
    schema: PutApiMessagesGatewayPutKeyResponseSchema,
  })
}

export async function clearMessagesGatewayKey(
  provider: MessagesGatewayProvider
): Promise<z.infer<typeof DeleteApiMessagesGatewayDeleteKeyResponseSchema>> {
  return httpClient(`${BASE}/keys/${provider}`, {
    method: 'DELETE',
    schema: DeleteApiMessagesGatewayDeleteKeyResponseSchema,
  })
}

export async function saveMessagesGatewayUpstream(
  upstreamUrl: string
): Promise<z.infer<typeof PutApiMessagesGatewayPutUpstreamResponseSchema>> {
  return httpClient(`${BASE}/upstream`, {
    method: 'PUT',
    body: JSON.stringify({ upstream_url: upstreamUrl }),
    schema: PutApiMessagesGatewayPutUpstreamResponseSchema,
  })
}

export async function saveMessagesGatewayAliases(
  aliases: Record<string, string>
): Promise<z.infer<typeof PutApiMessagesGatewayPutAliasesResponseSchema>> {
  return httpClient(`${BASE}/aliases`, {
    method: 'PUT',
    body: JSON.stringify({ model_aliases: aliases }),
    schema: PutApiMessagesGatewayPutAliasesResponseSchema,
  })
}

/** How the gateway answers a client's Anthropic `web_search` declaration. */
export type WebSearchMode = 'auto' | 'synaplan' | 'passthrough' | 'off'

/** How the gateway handles image turns for Claude Code. */
export type VisionMode = 'auto' | 'synaplan' | 'passthrough' | 'off'

export async function saveMessagesGatewayFlags(
  flags: Partial<{
    enabled: boolean
    allow_operator_key: boolean
    mcp_tools_enabled: boolean
    context_injection_enabled: boolean
    budget_notice_enabled: boolean
    web_search_mode: WebSearchMode
    vision_mode: VisionMode
  }>
): Promise<z.infer<typeof PutApiMessagesGatewayPutFlagsResponseSchema>> {
  return httpClient(`${BASE}/flags`, {
    method: 'PUT',
    body: JSON.stringify(flags),
    schema: PutApiMessagesGatewayPutFlagsResponseSchema,
  })
}
