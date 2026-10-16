import { describe, expect, it, vi } from 'vitest'
import { VoiceReplyStream } from '@/services/chat/stream/voiceReplyStream'
import type { AudioStreamer } from '@/utils/AudioStreamer'

function streamer() {
  return { streamText: vi.fn(), markComplete: vi.fn(), stop: vi.fn() }
}

describe('VoiceReplyStream', () => {
  it('speaks whole sentences as they arrive, in the reply language', () => {
    const audio = streamer()
    const voice = new VoiceReplyStream(() => audio as unknown as AudioStreamer, vi.fn(), 'en')
    voice.setLanguage('de')

    voice.feed('Hallo Welt. Wie ')
    voice.feed('geht es? Gut')

    expect(audio.streamText.mock.calls).toEqual([
      ['Hallo Welt. ', undefined, 'de'],
      ['Wie geht es? ', undefined, 'de'],
    ])
  })

  it('never speaks a think block, even one split across chunks', () => {
    const audio = streamer()
    const voice = new VoiceReplyStream(() => audio as unknown as AudioStreamer, vi.fn(), 'en')

    voice.feed('<think>plan the answer')
    voice.feed(' carefully</think>Done.')

    expect(voice.heardText()).toBe('Done.')
    expect(audio.streamText).toHaveBeenCalledWith('Done.', undefined, 'en')
  })

  it('speaks the rest at the end and marks the stream complete', () => {
    const audio = streamer()
    const voice = new VoiceReplyStream(() => audio as unknown as AudioStreamer, vi.fn(), 'en')

    voice.feed('No full stop yet')
    voice.finish()

    expect(audio.streamText).toHaveBeenCalledWith('No full stop yet', undefined, 'en')
    expect(audio.markComplete).toHaveBeenCalled()
  })

  it('hears nothing without a streamer and stops only a running one', () => {
    const onStopped = vi.fn()
    const voice = new VoiceReplyStream(() => null, onStopped, 'en')

    voice.feed('Hello.')
    voice.stop()

    expect(voice.heardText()).toBe('')
    expect(onStopped).not.toHaveBeenCalled()
  })

  it('stops a running streamer and reports it', () => {
    const audio = streamer()
    const onStopped = vi.fn()

    new VoiceReplyStream(() => audio as unknown as AudioStreamer, onStopped, 'en').stop()

    expect(audio.stop).toHaveBeenCalled()
    expect(onStopped).toHaveBeenCalled()
  })
})
