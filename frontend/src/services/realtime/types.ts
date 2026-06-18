/**
 * Shared types for the realtime / Centrifugo client.
 *
 * The envelope shape (`type`, `ts`, `data`) is dictated by the backend
 * `App\Realtime\Publisher\CentrifugoPublisher` — keep both ends in sync.
 */

import { z } from 'zod'

export const RealtimeEnvelopeSchema = z.object({
  type: z.string(),
  ts: z.number(),
  data: z.record(z.string(), z.unknown()),
})
export type RealtimeEnvelope<TData = Record<string, unknown>> = {
  type: string
  ts: number
  data: TData
}

/** Connection lifecycle, exposed by the realtime store + ConnectionStatusBadge. */
export type ConnectionState =
  | 'disabled' // realtime feature flag is off — distinct from a fault
  | 'disconnected' // never connected, or after explicit disconnect()
  | 'connecting' // initial connect or reconnect attempt
  | 'connected' // healthy WS
  | 'reconnecting' // transient drop, retrying
  | 'error' // unrecoverable (e.g. token refresh failed permanently)

export interface RealtimeRuntimeConfig {
  /**
   * Master kill-switch. When `false`, no Centrifuge connection is opened
   * and every `subscribe…` helper degrades to a no-op subscription.
   */
  enabled: boolean
  /** Empty string = same-origin (Caddy reverse-proxies `/connection/websocket`). */
  wsUrl: string
}

export interface SubscribeOptions<TPayload = Record<string, unknown>> {
  /**
   * Called once for every published event on this channel.
   *
   * Note: keep this synchronous and side-effect-cheap — the centrifuge-js
   * library invokes it in the WS event loop.
   */
  onPublication: (event: RealtimeEnvelope<TPayload>) => void

  /** Optional: presence join/leave. Only fires for namespaces with `presence: true`. */
  onJoin?: (info: { user: string; client: string }) => void
  onLeave?: (info: { user: string; client: string }) => void

  /** Optional: subscribe-time error reporter (auth failure etc.). */
  onError?: (message: string) => void
}

/** Minimal HTTP API contract used by `tokenApi.ts`. */
export const ConnectionTokenResponseSchema = z.object({
  token: z.string(),
  expiresIn: z.number(),
  subject: z.string(),
})
export type ConnectionTokenResponse = z.infer<typeof ConnectionTokenResponseSchema>

export const SubscriptionTokenResponseSchema = z.object({
  token: z.string(),
  channel: z.string(),
  expiresIn: z.number(),
})
export type SubscriptionTokenResponse = z.infer<typeof SubscriptionTokenResponseSchema>
