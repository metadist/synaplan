import type { Message, Part, PartType } from '@/stores/history'

/**
 * Issue #625: Generated media (image / video / audio) from MEDIAMAKER
 * was sometimes missing from the live SSE bubble — the player would
 * only appear after a page reload. The root cause was a fragile
 * interaction between three SSE events arriving back-to-back:
 *
 *   data (text chunk)  →  file (media url)  →  complete
 *
 * The original `data` handler reassigned `message.parts` wholesale on
 * every chunk, so a file event landing between two data events lost
 * the appended media. A later refactor (May 2026 "Performance" pass)
 * fixed the obvious case by keeping a per-render `existingMedia`
 * partition, but the media parts themselves still relied on:
 *
 *   1) `Array.prototype.push` on the reactive proxy (works only as
 *      long as the proxy reference stays attached — a reassignment
 *      elsewhere can silently detach it), and
 *   2) Vue's fallback `${type}-${index}` key (index changes whenever
 *      structural parts split mid-stream, causing the audio element
 *      to remount and reset its playback state).
 *
 * This module centralizes media-part handling so:
 *   - every media part gets a stable {@link Part.partId},
 *   - additions go through an explicit array reassignment (proxy-safe),
 *   - structural wipes can still salvage in-flight media via
 *     {@link extractMediaParts}.
 *
 * Keep this module side-effect free so it can be unit-tested without
 * mounting the chat view.
 */

/**
 * Returns a process-wide unique part id. Used as the stable `:key`
 * for `<MessagePart v-for>` so mid-stream re-renders don't unmount
 * the underlying `<audio>` / `<video>` element (which would drop any
 * pending playback or autoplay state).
 */
export function generatePartId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `p_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`
}

const MEDIA_PART_TYPES: ReadonlySet<PartType> = new Set(['image', 'video', 'audio'])

export type MediaPartType = 'image' | 'video' | 'audio'

export interface PushMediaPartOptions {
  /** Voice-reply autoplay flag (only meaningful for `audio`). */
  autoplay?: boolean
  /** Pre-generated part id (use only in tests). */
  partId?: string
}

/**
 * Append a media part (image / video / audio) to `message.parts` in a
 * Vue-3-reactivity-safe way.
 *
 * Why not just `message.parts.push(...)`? Two reasons:
 *
 *   - **Proxy reattachment.** Other handlers in `ChatView.vue` swap
 *     `message.parts` for a brand-new array (see the `tts_loading`
 *     filter and the `data.generatedFile` reactivity workaround).
 *     Holding a captured reference to the *old* array and pushing
 *     to it would silently update an orphaned proxy that no longer
 *     drives the DOM. Reassigning the array on every media add
 *     ensures we always mutate the current reactive slot.
 *   - **Stable keys.** Vue keys media parts by `partId ?? type-index`.
 *     Without a `partId`, an index shift (e.g. a `<think>` part being
 *     inserted at the top during streaming) remounts the `<audio>`
 *     element and resets the player to the empty state — exactly
 *     the "missing player" symptom from issue #625.
 */
export function pushMediaPart(
  message: Pick<Message, 'parts'>,
  type: MediaPartType,
  url: string,
  options: PushMediaPartOptions = {}
): Part {
  const existing = message.parts.find((p) => p.type === type && p.url === url)
  if (existing) {
    return existing
  }

  const part: Part = {
    partId: options.partId ?? generatePartId(),
    type,
    url,
  }

  if (type === 'audio' && options.autoplay !== undefined) {
    part.autoplay = options.autoplay
  }

  message.parts = [...message.parts, part]

  return part
}

/**
 * Returns the subset of parts that represent generated media so a
 * structural wipe (see `renderStreamingContent`'s
 * `looksLikeFileGeneration` branch, the `BFILEPATH` reactivity
 * workaround, etc.) can re-append them and avoid the regression
 * called out in issue #625:
 *
 * > "This is a fundamental issue with how generated media parts
 * > survive the SSE streaming lifecycle. The same pattern (parts
 * > overwritten by data chunks) could potentially affect other
 * > media types too, and should be addressed holistically."
 */
export function extractMediaParts(parts: readonly Part[]): Part[] {
  return parts.filter((p) => MEDIA_PART_TYPES.has(p.type))
}

/**
 * True iff `type` is a supported generated-media part type. Mirrors
 * {@link MEDIA_PART_TYPES} so consumers don't have to construct a
 * fresh set.
 */
export function isMediaPartType(type: PartType): type is MediaPartType {
  return MEDIA_PART_TYPES.has(type)
}
