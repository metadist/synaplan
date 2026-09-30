import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AudioStreamer } from '@/utils/AudioStreamer'

describe('AudioStreamer', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        ok: true,
        blob: async () => new Blob(['audio'], { type: 'audio/webm' }),
      })
    )
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn(() => 'blob:mock'),
      revokeObjectURL: vi.fn(),
    })
    vi.stubGlobal(
      'Audio',
      class {
        addEventListener() {}
        play() {
          return Promise.resolve()
        }
        pause() {}
      }
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('requires a non-blank language and never invents English', async () => {
    const streamer = new AudioStreamer()
    const onFailure = vi.fn()
    streamer.setOnFailure(onFailure)

    streamer.streamText('Hello world.', undefined, undefined)
    streamer.streamText('Hello world.', undefined, '  ')

    await Promise.resolve()

    expect(fetch).not.toHaveBeenCalled()
    expect(onFailure).toHaveBeenCalledWith('missing_language')
  })

  it('forwards the given language unchanged in the stream URL', async () => {
    const streamer = new AudioStreamer()
    streamer.streamText('Guten Tag.', undefined, 'de')

    await vi.waitFor(() => {
      expect(fetch).toHaveBeenCalled()
    })

    const url = String(vi.mocked(fetch).mock.calls[0]?.[0] ?? '')
    expect(url).toContain('language=de')
    expect(url).not.toContain('language=en')
    expect(url).toContain('text=Guten+Tag.')
  })
})
