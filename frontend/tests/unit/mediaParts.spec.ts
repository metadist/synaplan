import { describe, expect, it } from 'vitest'

import { reactive } from 'vue'

import type { Message, Part } from '@/stores/history'
import { extractMediaParts, generatePartId, pushMediaPart } from '@/utils/mediaParts'

/**
 * Issue #625 regression coverage.
 *
 * The live MEDIAMAKER audio bubble used to lose its `<audio>` player
 * because the SSE `file` event raced the streaming text reconciler.
 * These tests pin the three guarantees of {@link pushMediaPart} so a
 * future refactor can't reintroduce the bug:
 *
 *   1. Every appended media part carries a stable `partId` (so Vue's
 *      keyed v-for doesn't remount the player on the next render).
 *   2. The append reassigns `message.parts` instead of mutating in
 *      place (so a sibling handler holding a stale reference can't
 *      detach the reactive proxy and silently drop the push).
 *   3. {@link extractMediaParts} returns every image/video/audio
 *      part — and *only* those — so the structural wipe in
 *      `renderStreamingContent`'s `looksLikeFileGeneration` branch
 *      can salvage them without preserving thinking / text noise.
 */
describe('generatePartId', () => {
  it('returns a unique id on every call', () => {
    const ids = new Set<string>()
    for (let i = 0; i < 50; i++) {
      ids.add(generatePartId())
    }
    expect(ids.size).toBe(50)
  })
})

describe('pushMediaPart', () => {
  const buildMessage = (parts: Part[] = []): Pick<Message, 'parts'> => ({ parts })

  it('appends an audio part with a stable partId (issue #625)', () => {
    const message = buildMessage([{ type: 'text', content: 'Generated audio: hi' }])

    pushMediaPart(message, 'audio', '/api/v1/files/uploads/abc/tts.mp3')

    expect(message.parts).toHaveLength(2)
    const audio = message.parts[1]
    expect(audio.type).toBe('audio')
    expect(audio.url).toBe('/api/v1/files/uploads/abc/tts.mp3')
    expect(audio.partId).toMatch(/.+/)
  })

  it('replaces the parts array reference so Vue 3 reactivity always fires', () => {
    // pinia/reactive() proxies mutate-in-place AND detect reassignment,
    // but only reassignment survives a stale-closure scenario like the
    // one that bit live MEDIAMAKER audio. Verify the reference flip.
    const message = buildMessage([{ type: 'text', content: 'Generated audio: hi' }])
    const originalParts = message.parts

    pushMediaPart(message, 'audio', '/api/v1/files/uploads/x.mp3')

    expect(message.parts).not.toBe(originalParts)
    expect(originalParts).toHaveLength(1) // old reference left untouched
  })

  it('omits autoplay unless explicitly requested', () => {
    const message = buildMessage()

    pushMediaPart(message, 'audio', '/x.mp3')

    expect(message.parts[0].autoplay).toBeUndefined()
  })

  it('forwards the autoplay flag for voice replies', () => {
    const message = buildMessage()

    pushMediaPart(message, 'audio', '/voice.mp3', { autoplay: true })

    expect(message.parts[0].autoplay).toBe(true)
  })

  it('supports image and video parts with the same contract', () => {
    const message = buildMessage()

    pushMediaPart(message, 'image', '/img.png')
    pushMediaPart(message, 'video', '/clip.mp4')

    expect(message.parts.map((p) => p.type)).toEqual(['image', 'video'])
    expect(message.parts[0].partId).toBeTruthy()
    expect(message.parts[1].partId).toBeTruthy()
  })

  it('works on a Vue reactive() proxy (real ChatView usage)', () => {
    // Smoke-test that `message.parts = [...]` triggers a reactive
    // re-read even when the message is a Vue 3 Proxy — the path
    // ChatView.vue actually exercises via the history store.
    const message = reactive({ parts: [] as Part[] })

    pushMediaPart(message, 'audio', '/r.mp3')

    expect(message.parts).toHaveLength(1)
    expect(message.parts[0].type).toBe('audio')
    expect(message.parts[0].partId).toBeTruthy()
  })

  it('survives a data → file → complete SSE replay (issue #625 scenario)', () => {
    // Reproduces the exact ChatView ordering: the streaming text
    // reconciler runs (splitting into text + the new desired list)
    // BEFORE and AFTER the `file` event lands. Audio must survive
    // both passes regardless of whether the reconciler was kicked by
    // a `data` chunk or by the synchronous flush in the `complete`
    // handler.
    const message: Pick<Message, 'parts'> = {
      parts: [{ partId: 't1', type: 'text', content: '' }],
    }

    // 1) `data` chunk arrives → reconciler mutates the text in place.
    message.parts[0].content = "Generated audio: 'grüße an dich'"

    // 2) `file` event lands while the rAF reconciler is still pending.
    pushMediaPart(message, 'audio', '/api/v1/files/uploads/tts.mp3')

    // 3) `complete` event runs the final reconciliation. The streaming
    //    text reconciler in ChatView.vue uses `extractMediaParts(...)`
    //    to salvage media before reassigning the array, so the audio
    //    part MUST appear after the reconciled text.
    const structural = message.parts.filter(
      (p) => p.type === 'thinking' || p.type === 'text' || p.type === 'code' || p.type === 'links'
    )
    const media = extractMediaParts(message.parts)
    message.parts = [...structural, ...media]

    expect(message.parts.map((p) => p.type)).toEqual(['text', 'audio'])
    expect(message.parts[1].url).toBe('/api/v1/files/uploads/tts.mp3')
    expect(message.parts[1].partId).toBeTruthy()
  })
})

describe('extractMediaParts', () => {
  it('returns only image / video / audio parts in original order', () => {
    const parts: Part[] = [
      { type: 'text', content: 'hi' },
      { type: 'thinking', content: 'reasoning…' },
      { type: 'image', url: '/img.png' },
      { type: 'code', content: 'print(1)', language: 'python' },
      { type: 'audio', url: '/a.mp3' },
      { type: 'video', url: '/v.mp4' },
    ]

    const media = extractMediaParts(parts)

    expect(media).toEqual([
      { type: 'image', url: '/img.png' },
      { type: 'audio', url: '/a.mp3' },
      { type: 'video', url: '/v.mp4' },
    ])
  })

  it('returns an empty array when no media is present', () => {
    expect(extractMediaParts([{ type: 'text', content: 'hi' }])).toEqual([])
  })
})
