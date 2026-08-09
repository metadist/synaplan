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
  put_api_messages_gateway_put_flags_Body,
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

/** Any subset of the gateway settings; omitted ones keep their current value. */
export type MessagesGatewaySettings = z.infer<typeof put_api_messages_gateway_put_flags_Body>

/** How the gateway answers a client's Anthropic `web_search` declaration. */
export type WebSearchMode = NonNullable<MessagesGatewaySettings['web_search_mode']>
/** Which model reads an image turn that reaches the gateway. */
export type VisionMode = NonNullable<MessagesGatewaySettings['vision_mode']>
/** Resolution hint forwarded to upstreams that support it. */
export type ImageDetail = NonNullable<MessagesGatewaySettings['vision_image_detail']>

export async function saveMessagesGatewayFlags(
  flags: MessagesGatewaySettings
): Promise<z.infer<typeof PutApiMessagesGatewayPutFlagsResponseSchema>> {
  return httpClient(`${BASE}/flags`, {
    method: 'PUT',
    body: JSON.stringify(flags),
    schema: PutApiMessagesGatewayPutFlagsResponseSchema,
  })
}
