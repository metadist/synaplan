/**
 * Browser-published typing indicators for one widget chat session.
 *
 * Both the operator dashboard AND the embedded widget call this helper —
 * the only difference is the `RealtimeClient` instance they hand in. The
 * helper exposes one subscribe-and-publish handle that:
 *
 *   * delivers remote typing frames via `onTyping`,
 *   * filters echoes (the publisher always receives its own publication),
 *   * and exposes `publish()` / `clear()` to send the local typing state.
 *
 * Architectural rationale:
 *   * Typing is high-frequency and ephemeral — round-tripping through the
 *     PHP backend just to forward each frame to Centrifugo would cost a
 *     full HTTP request per keystroke. We move it to the dedicated
 *     `widgettyping:*` namespace where Centrifugo enforces
 *     `allow_publish_for_subscriber: true` (publishing requires having
 *     passed the {@link WidgetTypingAuthorizer} on subscribe). The trust
 *     boundary therefore lives in ONE place — the subscription token —
 *     instead of being re-checked on every HTTP typing request.
 *   * Sender identity (`from`) is a UI label, NOT a trust signal. A
 *     malicious visitor could publish `from: 'operator'` — the receiver
 *     simply renders a "support is typing" hint, which is harmless. No
 *     authorisation decision is made on this field anywhere.
 *
 * Payload contract is validated client-side with Zod so a misbehaving peer
 * cannot corrupt local state with garbage frames.
 */

import { z } from 'zod'
import type { ChannelHandle, RealtimeClient } from './RealtimeClient'

/**
 * Maximum length of the live preview text we forward over the wire.
 * Mirrors the cap previously enforced by the deprecated PHP HTTP endpoint
 * (see `WidgetPublicController::typing()`), so the operator dashboard
 * cannot be flooded with arbitrarily large typing previews.
 */
export const TYPING_TEXT_MAX_CHARS = 500

export const TypingFromSchema = z.enum(['visitor', 'operator'])
export type TypingFrom = z.infer<typeof TypingFromSchema>

export const TypingFrameSchema = z.object({
  from: TypingFromSchema,
  /** Live preview text. Empty string clears the indicator. */
  text: z.string().max(TYPING_TEXT_MAX_CHARS).default(''),
  /** Wall-clock millis at the publisher; receivers may use it to age out. */
  ts: z.number().int().nonnegative(),
  /** Random per-subscription id used to drop echoes of our own publications. */
  cid: z.string().min(1).max(64),
})
export type TypingFrame = z.infer<typeof TypingFrameSchema>

export interface WidgetTypingChannelOptions {
  client: RealtimeClient
  widgetId: string
  sessionId: string
  /** Identity tag attached to OUR outgoing publications. */
  from: TypingFrom
  /** Called for every REMOTE (i.e. non-echo) typing frame received. */
  onTyping: (frame: TypingFrame) => void
  /**
   * Optional error reporter. Subscribe failures (e.g. expired token) and
   * publish failures (e.g. permission denied because the namespace is
   * misconfigured) end up here. Defaults to a no-op because typing is
   * best-effort UX, not a critical signal.
   */
  onError?: (message: string) => void
}

export interface WidgetTypingHandle {
  /** Publish a typing preview. Pass an empty string to clear. */
  publish: (text: string) => void
  /** Convenience for `publish('')` — also flushes any pending publication. */
  clear: () => void
  /** Tear down the underlying subscription. */
  close: () => void
}

const NOOP_HANDLE: WidgetTypingHandle = {
  publish: () => undefined,
  clear: () => undefined,
  close: () => undefined,
}

function buildChannelName(widgetId: string, sessionId: string): string {
  // NB: namespace is `widgettyping` (no hyphen) — see WidgetTypingChannel.php
  // for why; in short, hyphenated namespace names tripped Centrifugo's
  // channel parser in v6 even though the docs say they should work.
  return `widgettyping:${widgetId}.${sessionId}`
}

function newClientId(): string {
  // Cryptographically-strong when available (modern browsers, JSDOM via
  // `globalThis.crypto`); falls back to Math.random in the unlikely case
  // crypto is unavailable. We only need uniqueness within a single tab,
  // not unguessability — the server never trusts this id.
  const c =
    typeof globalThis !== 'undefined' && 'crypto' in globalThis
      ? (globalThis.crypto as Crypto | undefined)
      : undefined
  if (c?.randomUUID) return c.randomUUID()
  return `cid_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`
}

/**
 * Open the typing channel for one widget session.
 *
 * Returns immediately with a handle that callers may use even before the
 * underlying subscription has finished negotiating — publishes that fire
 * during the subscribe handshake are silently dropped (typing is
 * best-effort, not a delivery guarantee).
 */
export function openWidgetTypingChannel(opts: WidgetTypingChannelOptions): WidgetTypingHandle {
  const channel = buildChannelName(opts.widgetId, opts.sessionId)
  const cid = newClientId()

  let handle: ChannelHandle | null = null
  let cancelled = false

  void opts.client
    .subscribe<Record<string, unknown>>(channel, {
      onPublication: (envelope) => {
        const parsed = TypingFrameSchema.safeParse(envelope.data)
        if (!parsed.success) {
          // Drop malformed frames — never let an unparsed payload reach
          // the consumer. A misbehaving peer must not be able to crash
          // the receiver by publishing a non-conforming object.
          return
        }
        if (parsed.data.cid === cid) {
          // Echo of our own publish — ignore so the publisher does not
          // see itself "typing".
          return
        }
        opts.onTyping(parsed.data)
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

  function publish(text: string): void {
    if (cancelled || !handle) return
    const frame: TypingFrame = {
      from: opts.from,
      text: text.slice(0, TYPING_TEXT_MAX_CHARS),
      ts: Date.now(),
      cid,
    }
    // Wrap in the same `{ type, ts, data }` envelope shape that the PHP
    // RealtimePublisher uses on every server-side fan-out. The receiver
    // (both visitor and operator) reads `envelope.data` to get the frame
    // — publishing the bare frame would land it under `envelope` itself,
    // and Zod would silently drop every publication as malformed.
    const envelope: Record<string, unknown> = {
      type: 'typing',
      ts: frame.ts,
      data: frame as unknown as Record<string, unknown>,
    }
    void handle.publish(envelope).catch((err: unknown) => {
      const msg = err instanceof Error ? err.message : String(err)
      opts.onError?.(msg)
    })
  }

  return {
    publish,
    clear: () => publish(''),
    close: () => {
      cancelled = true
      handle?.unsubscribe()
      handle = null
    },
  }
}

/** Useful for callers that want to opt out without an `if` everywhere. */
export const NOOP_TYPING_HANDLE = NOOP_HANDLE
