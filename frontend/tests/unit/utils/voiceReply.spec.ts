import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Message } from '@/stores/history'
import { AudioStreamer } from '@/utils/AudioStreamer'
import {
  applyVoiceReplyFailed,
  attachVoiceReplyAudio,
  isTaskPlanSuppressedMediaStatus,
} from '@/utils/voiceReply'

function makeMessage(overrides: Partial<Message> = {}): Message {
  return {
    id: 'm1',
    role: 'assistant',
    parts: [{ type: 'text', content: 'Hello' }],
    timestamp: new Date(),
    ...overrides,
  }
}

describe('voiceReply utils (#2282)', () => {
  it('applyVoiceReplyFailed clears tts_loading and stores the reason', () => {
    const message = makeMessage({
      parts: [
        { type: 'text', content: 'Hello' },
        { type: 'tts_loading' },
      ],
    })

    applyVoiceReplyFailed(message, 'provider_error')

    expect(message.parts.some((p) => p.type === 'tts_loading')).toBe(false)
    expect(message.voiceReplyFailed).toBe('provider_error')
  })

  it('applyVoiceReplyFailed maps each reason code without leaving a spinner', () => {
    for (const reason of ['provider_error', 'empty_text', 'rate_limited'] as const) {
      const message = makeMessage({ parts: [{ type: 'tts_loading' }] })
      applyVoiceReplyFailed(message, reason)
      expect(message.voiceReplyFailed).toBe(reason)
      expect(message.parts).toEqual([])
    }
  })

  it('attachVoiceReplyAudio during an active task plan attaches audio and clears loading', () => {
    const message = makeMessage({
      parts: [
        { type: 'text', content: 'Summary' },
        { type: 'tts_loading' },
      ],
      taskPlan: {
        active: true,
        replyNode: 'n2',
        cards: [
          {
            nodeId: 'n1',
            capability: 'web_search',
            kind: 'search',
            state: 'done',
          },
        ],
      },
      wasMultitask: true,
    })

    // Voice reply must not be suppressed while taskPlan.active (#2282).
    expect(isTaskPlanSuppressedMediaStatus('audio')).toBe(false)
    expect(isTaskPlanSuppressedMediaStatus('tts_generating')).toBe(false)
    expect(isTaskPlanSuppressedMediaStatus('voice_reply_failed')).toBe(false)
    expect(isTaskPlanSuppressedMediaStatus('file')).toBe(true)

    attachVoiceReplyAudio(message, 'http://localhost:8000/api/v1/files/uploads/tts.mp3', {
      autoplay: true,
    })

    expect(message.parts.some((p) => p.type === 'tts_loading')).toBe(false)
    expect(message.voiceReplyFailed).toBeUndefined()
    const audio = message.parts.find((p) => p.type === 'audio')
    expect(audio?.url).toContain('tts.mp3')
    expect(audio?.autoplay).toBe(true)
  })
})

describe('AudioStreamer chunk failure (#2282)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('calls the failure callback and fetches no further chunks', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response('fail', { status: 500 }))
      .mockResolvedValue(new Response('should-not-run', { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)

    const streamer = new AudioStreamer()
    const onFailure = vi.fn()
    streamer.setOnFailure(onFailure)

    streamer.streamText('First sentence.', undefined, 'en')

    await vi.waitFor(() => {
      expect(onFailure).toHaveBeenCalledWith('provider_error')
    })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(streamer.active).toBe(false)

    // After a terminal failure, later sentences must not hit the network.
    streamer.streamText('Second sentence.', undefined, 'en')
    streamer.markComplete()
    await Promise.resolve()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })
})
