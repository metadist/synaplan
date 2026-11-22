import type { z } from 'zod'
import {
  DeleteAdminEgressAllowRemoveResponseSchema,
  GetAdminCapacityAiCallsResponseSchema,
  GetAdminCapacityOutboundResponseSchema,
  GetAdminCapacityRequestsResponseSchema,
  GetAdminCapacityServersResponseSchema,
  GetAdminCapacityToolsResponseSchema,
  PostAdminEgressAllowAddResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

/**
 * Capacity of every server, as its watchdog reported it (architecture update
 * AU7). One short request that reads Redis; it never asks a server.
 */
export type CapacityReport = z.infer<typeof GetAdminCapacityServersResponseSchema>
export type CapacityServer = CapacityReport['servers'][number]

/** Request timeline summaries (AU33); counts and timings only, no content. */
export type RequestsReport = z.infer<typeof GetAdminCapacityRequestsResponseSchema>
export type RouteClassRow = RequestsReport['route_classes'][number]
export type SlowRequest = RequestsReport['slowest'][number]
export type AiCallsReport = z.infer<typeof GetAdminCapacityAiCallsResponseSchema>
export type AiModelRow = AiCallsReport['models'][number]
export type AiSourceRow = AiCallsReport['sources'][number]
export type ToolsReport = z.infer<typeof GetAdminCapacityToolsResponseSchema>
export type ToolRow = ToolsReport['tools'][number]
export type OutboundReport = z.infer<typeof GetAdminCapacityOutboundResponseSchema>
export type OutboundRow = OutboundReport['destinations'][number]
export type EgressAllowResult = z.infer<typeof PostAdminEgressAllowAddResponseSchema>

const allowRequest = (method: 'POST' | 'DELETE', rule: string) => ({
  method,
  body: JSON.stringify({ rule }),
})

export const capacityApi = {
  servers: (): Promise<CapacityReport> =>
    httpClient('/api/v1/admin/capacity/servers', {
      schema: GetAdminCapacityServersResponseSchema,
    }),
  requests: (): Promise<RequestsReport> =>
    httpClient('/api/v1/admin/capacity/requests', {
      schema: GetAdminCapacityRequestsResponseSchema,
    }),
  aiCalls: (): Promise<AiCallsReport> =>
    httpClient('/api/v1/admin/capacity/ai-calls', {
      schema: GetAdminCapacityAiCallsResponseSchema,
    }),
  tools: (): Promise<ToolsReport> =>
    httpClient('/api/v1/admin/capacity/tools', {
      schema: GetAdminCapacityToolsResponseSchema,
    }),
  outbound: (): Promise<OutboundReport> =>
    httpClient('/api/v1/admin/capacity/outbound', {
      schema: GetAdminCapacityOutboundResponseSchema,
    }),
  /** Adds one rule to EGRESS.ALLOW; the outbound filter picks it up on the next request. */
  allow: (rule: string): Promise<EgressAllowResult> =>
    httpClient('/api/v1/admin/egress/allow', {
      ...allowRequest('POST', rule),
      schema: PostAdminEgressAllowAddResponseSchema,
    }),
  removeAllow: (rule: string): Promise<EgressAllowResult> =>
    httpClient('/api/v1/admin/egress/allow', {
      ...allowRequest('DELETE', rule),
      schema: DeleteAdminEgressAllowRemoveResponseSchema,
    }),
}
