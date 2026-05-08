/**
 * Operator-side WebSocket subscriptions for widget channels.
 *
 * Used by the dashboard (`WidgetSessionsView`, `LiveSupportView`, …). All
 * helpers reuse the singleton {@link RealtimeClient} held by the realtime
 * Pinia store so that one dashboard tab shares a single Centrifuge
 * connection across many channel subscriptions.
 *
 * Two channels are exposed today:
 *
 *   * `widget:session.{widgetId}.{sessionId}` — per-session events
 *     (visitor messages, AI replies, takeover, handback, typing).
 *   * `widget:operators.{widgetId}` — fan-out notifications for the
 *     widget owner ("new message in waiting session", …).
 *
 * Both helpers return a subscription handle compatible with the imperative
 * usage pattern of the operator views (subscribe in `onMounted`, drop in
 * `onBeforeUnmount`). Components that prefer a Vue-friendly composable
 * should use {@link useRealtimeChannel} instead.
 *
 * If `runtime.realtime.enabled` is `false` the helpers degrade to no-ops —
 * there is intentionally no fallback transport.
 */

import { useRealtimeStore } from '@/stores/realtime'
import { getConfigSync } from '@/services/api/httpClient'
import type { RealtimeRuntimeConfig } from './types'
import {
  NOOP_TYPING_HANDLE,
  openWidgetTypingChannel,
  type TypingFrame,
  type WidgetTypingHandle,
} from './widgetTypingChannel'

export interface WidgetEvent {
  type: string
  [key: string]: unknown
}

export interface WidgetSubscription {
  unsubscribe: () => void
}

const NOOP_SUBSCRIPTION: WidgetSubscription = { unsubscribe: () => undefined }

function isRealtimeEnabled(): boolean {
  const cfg = (getConfigSync() as { realtime?: Partial<RealtimeRuntimeConfig> }).realtime
  return cfg?.enabled ?? false
}

interface SubscribeOperatorChannelOptions {
  channel: string
  onEvent: (event: WidgetEvent) => void
  onError?: (message: string) => void
}

function subscribeOperatorChannel(opts: SubscribeOperatorChannelOptions): WidgetSubscription {
  if (!isRealtimeEnabled()) {
    return NOOP_SUBSCRIPTION
  }

  let handle: { unsubscribe: () => void } | null = null
  let cancelled = false

  const store = useRealtimeStore()
  const client = store.getOrCreateClient()

  void client
    .subscribe(opts.channel, {
      onPublication: (envelope) => {
        // Mirror the visitor-side flatten so consumers see one stable shape.
        opts.onEvent({ type: envelope.type, ...envelope.data })
      },
      onError: (msg) => opts.onError?.(msg),
    })
    .then((h) => {
      if (cancelled) {
        h.unsubscribe()
        return
      }
      handle = h
    })
    .catch((err: unknown) => {
      const msg = err instanceof Error ? err.message : String(err)
      opts.onError?.(msg)
    })

  return {
    unsubscribe: () => {
      cancelled = true
      handle?.unsubscribe()
    },
  }
}

/**
 * Subscribe to the per-session channel from the operator dashboard.
 *
 * The operator already has cookie-based auth, so the subscription token
 * issuer recognises them and runs the operator branch of
 * `WidgetSessionAuthorizer` (widget ownership check).
 */
export function subscribeToWidgetSessionAsOperator(
  widgetId: string,
  sessionId: string,
  onEvent: (event: WidgetEvent) => void,
  onError?: (message: string) => void
): WidgetSubscription {
  return subscribeOperatorChannel({
    channel: `widget:session.${widgetId}.${sessionId}`,
    onEvent,
    onError,
  })
}

/**
 * Subscribe to the widget owner's notifications channel.
 *
 * Replaces the legacy 3-second polling loop on `LiveSupportView`.
 */
export function subscribeToWidgetOperatorChannel(
  widgetId: string,
  onEvent: (event: WidgetEvent) => void,
  onError?: (message: string) => void
): WidgetSubscription {
  return subscribeOperatorChannel({
    channel: `widget:operators.${widgetId}`,
    onEvent,
    onError,
  })
}

/**
 * Open the operator-side typing channel for one widget session.
 *
 * Reuses the dashboard's singleton {@link RealtimeClient} so a single
 * Centrifuge connection multiplexes the durable session events AND the
 * client-published typing frames. Returns a no-op handle when realtime
 * is feature-flagged off — the caller never has to special-case it.
 */
export function openOperatorTypingChannel(
  widgetId: string,
  sessionId: string,
  onTyping: (frame: TypingFrame) => void,
  onError?: (message: string) => void
): WidgetTypingHandle {
  if (!isRealtimeEnabled()) return NOOP_TYPING_HANDLE
  const client = useRealtimeStore().getOrCreateClient()
  return openWidgetTypingChannel({
    client,
    widgetId,
    sessionId,
    from: 'operator',
    onTyping,
    onError,
  })
}
