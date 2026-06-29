/**
 * Centrifugo client wrapper.
 *
 * Responsibilities:
 *   1. Hold a single Centrifuge instance per identity (operator / visitor).
 *   2. Drive token refresh both for the connection AND per-channel subs.
 *   3. Surface lifecycle events (connect / reconnect / error / disconnect)
 *      as a small callback API so the Pinia store can mirror them into
 *      reactive state.
 *
 * The class is intentionally framework-agnostic — Vue-specific glue lives
 * in the realtime Pinia store and `widgetOperatorRealtime.ts`. That makes
 * it trivial to reuse from the embedded widget bundle (which doesn't load
 * Pinia).
 */

import { Centrifuge, type PublicationContext, type SubscriptionErrorContext } from 'centrifuge'
import { isNativeApp, getNativeApiBaseUrl } from '@/services/api/nativeRuntime'
import {
  fetchOperatorConnectionToken,
  fetchVisitorConnectionToken,
  fetchSubscriptionToken,
} from './tokenApi'
import {
  RealtimeEnvelopeSchema,
  type ConnectionState,
  type RealtimeEnvelope,
  type RealtimeRuntimeConfig,
  type SubscribeOptions,
} from './types'

export interface RealtimeClientOptions {
  runtime: RealtimeRuntimeConfig
  /**
   * Identity used for the connection token call. `operator` reuses the
   * dashboard auth cookie, `visitor` requires (widgetId, sessionId).
   */
  identity:
    | { kind: 'operator' }
    | { kind: 'visitor'; widgetId: string; sessionId: string; apiBaseUrl?: string }

  onStateChange?: (state: ConnectionState, error?: string) => void
}

export interface ChannelHandle {
  unsubscribe: () => void
  /**
   * Publish a payload to this channel from the browser.
   *
   * Only succeeds for namespaces configured with
   * `allow_publish_for_subscriber: true` in `_docker/centrifugo/config.json`
   * — at the time of writing, that is exclusively `widgettyping`. Any
   * other channel will reject the publish at the Centrifugo edge.
   *
   * The promise resolves once Centrifugo has acked the publication; it
   * rejects on transport errors and on permission denials. Callers MUST
   * NOT pass anything that is unsafe to fan out to all subscribers — the
   * backend never sees these payloads (no validation proxy), so the
   * caller is fully responsible for sanitisation.
   */
  publish: (data: Record<string, unknown>) => Promise<void>
}

export class RealtimeClient {
  private centrifuge: Centrifuge | null = null
  private state: ConnectionState = 'disconnected'
  private destroyed = false

  /** Subscriptions we re-establish across reconnects. Key = channel name. */
  private readonly subscriptions = new Map<string, SubscribeOptions<Record<string, unknown>>>()

  constructor(private readonly options: RealtimeClientOptions) {}

  /**
   * Resolves the WebSocket endpoint.
   *
   * Order of preference:
   *   1. Explicit `runtime.wsUrl` from public runtime config (operator UI)
   *      or widget config (embedded widget). Set this in production when
   *      the WS gateway lives on a different host (e.g. `wss://rt.app...`).
   *   2. Visitor identity: derive from `apiBaseUrl` so the embedded widget
   *      connects to the Synaplan backend, NOT the customer's own host
   *      (`window.location` is the customer's site for cross-origin embeds).
   *   3. Operator identity / fallback: same-origin (`window.location.host`)
   *      because Caddy reverse-proxies `/connection/websocket` to Centrifugo.
   *
   * Dev note: in `npm run dev` the operator UI is served by Vite on :5173
   * while Caddy + Centrifugo live behind :8000, so option 3 only works
   * because `vite.config.ts` proxies `/connection/*` (with `ws: true`) to
   * the backend. If you ever see the dashboard's ConnectionStatusBadge
   * stuck on "Connection Error" while embedded widgets keep working, the
   * Vite proxy is the first thing to check.
   */
  private resolveWsUrl(): string {
    const configured = this.options.runtime.wsUrl?.trim()
    if (configured) return configured

    if ('visitor' === this.options.identity.kind && this.options.identity.apiBaseUrl) {
      const wsBase = this.options.identity.apiBaseUrl
        .replace(/^http:/i, 'ws:')
        .replace(/^https:/i, 'wss:')
      return `${wsBase.replace(/\/$/, '')}/connection/websocket`
    }

    // Native shell: window.location.host is the in-app localhost origin, not the
    // backend. Derive the WS endpoint from the configured native API base so the
    // operator connection targets the real Centrifugo gateway (Epic 3).
    if (isNativeApp()) {
      const wsBase = getNativeApiBaseUrl()
        .replace(/^http:/i, 'ws:')
        .replace(/^https:/i, 'wss:')
      return `${wsBase.replace(/\/$/, '')}/connection/websocket`
    }

    const proto = 'https:' === window.location.protocol ? 'wss' : 'ws'
    return `${proto}://${window.location.host}/connection/websocket`
  }

  /**
   * Lazily build the Centrifuge instance. We don't connect in the
   * constructor so the caller can wire the state callback first.
   */
  private async ensureCentrifuge(): Promise<Centrifuge | null> {
    if (this.destroyed) return null
    if (this.centrifuge) return this.centrifuge

    if (!this.options.runtime.enabled) {
      // Surface the "feature flag off" case as its own state so the UX
      // layer (badge / tooltip) can distinguish a deliberate kill-switch
      // from a transport fault. Returning `null` means every subscribe()
      // call below silently no-ops — see the early return there.
      this.setState('disabled', 'realtime-disabled')
      return null
    }

    this.centrifuge = new Centrifuge(this.resolveWsUrl(), {
      // Token is refreshed on every (re)connect.
      getToken: async () => {
        return this.fetchConnectionToken()
      },
    })

    this.centrifuge.on('connecting', () => this.setState('connecting'))
    this.centrifuge.on('connected', () => this.setState('connected'))
    this.centrifuge.on('disconnected', (ctx) => {
      // Centrifuge emits disconnected for both transient and terminal cases
      // — `ctx.code` < 3500 is a normal close.
      if (this.destroyed) {
        this.setState('disconnected')
      } else {
        this.setState('reconnecting', `disconnect:${ctx?.code ?? 'unknown'}`)
      }
    })
    this.centrifuge.on('error', (ctx) => {
      this.setState('error', ctx?.error?.message ?? 'unknown error')
    })

    return this.centrifuge
  }

  private async fetchConnectionToken(): Promise<string> {
    if (this.options.identity.kind === 'operator') {
      const result = await fetchOperatorConnectionToken()
      return result.token
    }
    const result = await fetchVisitorConnectionToken(
      this.options.identity.widgetId,
      this.options.identity.sessionId,
      this.options.identity.apiBaseUrl
    )
    return result.token
  }

  /** Open the WS upgrade if not already open. */
  async connect(): Promise<void> {
    const c = await this.ensureCentrifuge()
    if (!c) return
    if (this.state === 'connected' || this.state === 'connecting') return
    c.connect()
  }

  async disconnect(): Promise<void> {
    this.destroyed = true
    this.subscriptions.clear()
    if (this.centrifuge) {
      this.centrifuge.disconnect()
      this.centrifuge.removeAllListeners()
      this.centrifuge = null
    }
    this.setState('disconnected')
  }

  getState(): ConnectionState {
    return this.state
  }

  /**
   * Subscribe to a channel. The subscription persists across reconnects
   * (centrifuge-js handles the recovery — see `force_recovery` in
   * `_docker/centrifugo/config.json`).
   */
  async subscribe<TPayload extends Record<string, unknown> = Record<string, unknown>>(
    channel: string,
    handlers: SubscribeOptions<TPayload>
  ): Promise<ChannelHandle> {
    const c = await this.ensureCentrifuge()
    if (!c) {
      // Realtime disabled — silently no-op so callers can still wire up
      // their UI without conditionals everywhere.
      return { unsubscribe: () => undefined, publish: async () => undefined }
    }

    this.subscriptions.set(channel, handlers as SubscribeOptions<Record<string, unknown>>)

    const sub = c.newSubscription(channel, {
      getToken: async () => {
        const result = await fetchSubscriptionToken(channel, {
          anonymous: this.options.identity.kind === 'visitor',
          widgetId:
            this.options.identity.kind === 'visitor' ? this.options.identity.widgetId : undefined,
          sessionId:
            this.options.identity.kind === 'visitor' ? this.options.identity.sessionId : undefined,
          apiBaseUrl:
            this.options.identity.kind === 'visitor' ? this.options.identity.apiBaseUrl : undefined,
        })
        return result.token
      },
    })

    sub.on('publication', (ctx: PublicationContext) => {
      // Validate the wire envelope (type/ts/data) before handing it to app
      // code; the payload type itself stays a compile-time contract.
      const parsed = RealtimeEnvelopeSchema.safeParse(ctx.data)
      if (!parsed.success) {
        handlers.onError?.(`malformed realtime envelope on ${channel}`)
        return
      }
      handlers.onPublication(parsed.data as RealtimeEnvelope<TPayload>)
    })
    if (handlers.onJoin)
      sub.on('join', (ctx) =>
        handlers.onJoin?.({
          user: String(ctx.info?.user ?? ''),
          client: String(ctx.info?.client ?? ''),
        })
      )
    if (handlers.onLeave)
      sub.on('leave', (ctx) =>
        handlers.onLeave?.({
          user: String(ctx.info?.user ?? ''),
          client: String(ctx.info?.client ?? ''),
        })
      )
    sub.on('subscribed', () => this.setState('connected'))
    sub.on('error', (ctx: SubscriptionErrorContext) => {
      handlers.onError?.(ctx?.error?.message ?? 'subscription error')
    })

    sub.subscribe()

    // Connect lazily on first subscription so callers don't have to remember.
    if (this.state === 'disconnected') {
      c.connect()
    }

    return {
      unsubscribe: () => {
        try {
          sub.unsubscribe()
          sub.removeAllListeners()
          c.removeSubscription(sub)
        } catch {
          // ignore — channel may have been torn down already
        } finally {
          this.subscriptions.delete(channel)
        }
      },
      publish: async (data) => {
        // Direct client-publish only succeeds on namespaces configured for
        // it — see the docblock on ChannelHandle.publish for the security
        // contract. We deliberately surface the centrifuge-js error to
        // the caller (typing indicators are best-effort, but a permission
        // failure should not be silently swallowed during development).
        await sub.publish(data)
      },
    }
  }

  private setState(state: ConnectionState, error?: string): void {
    if (this.state === state) return
    this.state = state
    this.options.onStateChange?.(state, error)
  }
}
