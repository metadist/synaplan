/**
 * Visitor-side WebSocket subscription for an embedded widget session.
 *
 * Used exclusively by the embedded chat widget bundle, which:
 *   * runs cross-origin and therefore cannot read the dashboard auth cookie,
 *   * does not load Pinia, so it cannot reuse the operator-side store,
 *   * mints its own short-lived visitor token from the widget endpoint.
 *
 * The chat widget calls this once per session. When `runtime.enabled` is
 * `false` (master kill-switch from `/api/v1/widget/{id}/config`) the helper
 * returns a no-op subscription — there is intentionally no fallback
 * transport, the widget falls back to its REST endpoints (no live updates).
 *
 * The published envelope is flattened back to `{ type, ...payload }` so the
 * widget's existing event handler treats every realtime event as a plain
 * JS object with a discriminator field.
 */

import { RealtimeClient } from './RealtimeClient'
import type { ConnectionState, RealtimeRuntimeConfig } from './types'
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
  /**
   * Open a client-published typing channel for THIS session, sharing the
   * same Centrifuge WS connection as the durable session events. The
   * returned handle is a no-op when realtime is disabled or while the
   * underlying subscription is still negotiating.
   */
  openTypingChannel: (onTyping: (frame: TypingFrame) => void) => WidgetTypingHandle
}

export interface SubscribeWidgetSessionOptions {
  widgetId: string
  sessionId: string
  apiBaseUrl: string
  runtime: RealtimeRuntimeConfig
  onEvent: (event: WidgetEvent) => void
  onError?: (message: string) => void
  onState?: (state: ConnectionState) => void
}

const NOOP_SUBSCRIPTION: WidgetSubscription = {
  unsubscribe: () => undefined,
  openTypingChannel: () => NOOP_TYPING_HANDLE,
}

export function subscribeToWidgetSessionRealtime(
  opts: SubscribeWidgetSessionOptions
): WidgetSubscription {
  if (!opts.runtime.enabled) {
    return NOOP_SUBSCRIPTION
  }

  const client = new RealtimeClient({
    runtime: opts.runtime,
    identity: {
      kind: 'visitor',
      widgetId: opts.widgetId,
      sessionId: opts.sessionId,
      apiBaseUrl: opts.apiBaseUrl,
    },
    onStateChange: (state, error) => {
      opts.onState?.(state)
      if ('error' === state && error) opts.onError?.(error)
    },
  })

  const channel = `widget:session.${opts.widgetId}.${opts.sessionId}`

  let handle: { unsubscribe: () => void } | null = null
  let typingHandle: WidgetTypingHandle | null = null
  let cancelled = false

  void client
    .subscribe(channel, {
      onPublication: (envelope) => {
        // Flatten `{ type, ts, data: { ...payload } }` back to the original
        // event shape `{ type, ...payload }` so existing widget handlers
        // do not have to be rewritten.
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
      try {
        typingHandle?.close()
        typingHandle = null
        handle?.unsubscribe()
      } finally {
        void client.disconnect()
      }
    },
    openTypingChannel: (onTyping) => {
      // Idempotent: if the caller asks twice (e.g. session reopened), the
      // previous handle is closed first to avoid stacking subscriptions.
      typingHandle?.close()
      typingHandle = openWidgetTypingChannel({
        client,
        widgetId: opts.widgetId,
        sessionId: opts.sessionId,
        from: 'visitor',
        onTyping,
        onError: opts.onError,
      })
      return typingHandle
    },
  }
}
