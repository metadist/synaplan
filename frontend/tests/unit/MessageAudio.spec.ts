import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import MessageAudio from '@/components/MessageAudio.vue'

/**
 * Stub out the parts of HTMLMediaElement that JSDOM omits. The `<audio>`
 * element in tests needs `load()`, `play()`, and `pause()` to exist as
 * spy-friendly functions so we can drive the load/error/playback lifecycle
 * by hand without needing a real audio decoder.
 *
 * The original prototype descriptors are captured once and restored in
 * `afterEach` so the stubs don't leak into other test files (Copilot
 * review on PR #986). `vi.restoreAllMocks()` alone doesn't undo
 * `Object.defineProperty` on a shared prototype, so order-dependent
 * runs would otherwise pick up our spies and behave unpredictably.
 */
const STUBBED_AUDIO_PROPS = ['play', 'pause', 'load'] as const
type StubbableAudioProp = (typeof STUBBED_AUDIO_PROPS)[number]
const originalAudioDescriptors: Partial<
  Record<StubbableAudioProp, PropertyDescriptor | undefined>
> = {}

function stubAudioElement(overrides: Partial<HTMLAudioElement> = {}) {
  const proto = HTMLMediaElement.prototype
  for (const prop of STUBBED_AUDIO_PROPS) {
    if (!(prop in originalAudioDescriptors)) {
      originalAudioDescriptors[prop] = Object.getOwnPropertyDescriptor(proto, prop)
    }
  }
  Object.defineProperty(proto, 'play', {
    configurable: true,
    writable: true,
    value: overrides.play ?? vi.fn().mockResolvedValue(undefined),
  })
  Object.defineProperty(proto, 'pause', {
    configurable: true,
    writable: true,
    value: overrides.pause ?? vi.fn(),
  })
  Object.defineProperty(proto, 'load', {
    configurable: true,
    writable: true,
    value: overrides.load ?? vi.fn(),
  })
}

function restoreAudioElement(): void {
  const proto = HTMLMediaElement.prototype
  for (const prop of STUBBED_AUDIO_PROPS) {
    const original = originalAudioDescriptors[prop]
    if (original) {
      Object.defineProperty(proto, prop, original)
    } else {
      // JSDOM didn't define this prop natively — remove the stub
      // so the next test starts from a clean slate.
      delete (proto as unknown as Record<string, unknown>)[prop]
    }
  }
}

describe('MessageAudio', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    stubAudioElement()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    restoreAudioElement()
  })

  it('renders the audio element with the provided URL', () => {
    const wrapper = mount(MessageAudio, {
      props: {
        url: 'https://example.com/voice.ogg',
      },
    })

    const audio = wrapper.find('[data-testid="media-audio-player"]')
    expect(audio.exists()).toBe(true)
    expect(audio.attributes('src')).toBe('https://example.com/voice.ogg')
  })

  it('retries with a cache-busted URL when the audio element fires an error', async () => {
    const wrapper = mount(MessageAudio, {
      props: {
        url: '/api/v1/files/uploads/13/voice.ogg',
      },
    })

    const audio = wrapper.find('[data-testid="media-audio-player"]')
    await audio.trigger('error')

    // First retry waits 1s before reloading.
    vi.advanceTimersByTime(1000)
    await nextTick()

    const retried = wrapper.find('[data-testid="media-audio-player"]')
    expect(retried.attributes('src')).toMatch(/_retry=\d+/)
  })

  it('shows the unavailable error UI after all retries are exhausted', async () => {
    const wrapper = mount(MessageAudio, {
      props: {
        url: '/api/v1/files/uploads/13/voice.ogg',
      },
    })

    // Three failed loads → still retrying, no error state yet.
    for (let i = 0; i < 3; i++) {
      await wrapper.find('[data-testid="media-audio-player"]').trigger('error')
      vi.advanceTimersByTime(5000)
      await nextTick()
    }

    // Fourth failure exhausts the retry budget and surfaces the error state.
    await wrapper.find('[data-testid="media-audio-player"]').trigger('error')
    await nextTick()

    expect(wrapper.find('[data-testid="audio-load-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="media-audio-player"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-audio-download-fallback"]').exists()).toBe(true)
  })

  it('does not let a play() rejection escape as an unhandled promise rejection', async () => {
    // Simulate the exact failure path from issue #976: the audio file 404s,
    // so calling play() rejects with NotSupportedError. The component must
    // catch this so the global error handler does NOT show the full-screen
    // crash view.
    const rejection = Object.assign(new Error('The element has no supported sources.'), {
      name: 'NotSupportedError',
    })
    stubAudioElement({
      play: vi.fn().mockRejectedValue(rejection),
    })

    const unhandledHandler = vi.fn()
    window.addEventListener('unhandledrejection', unhandledHandler)

    try {
      const wrapper = mount(MessageAudio, {
        props: {
          url: '/api/v1/files/uploads/13/voice.ogg',
        },
      })

      await wrapper.find('[data-testid="btn-audio-play"]').trigger('click')
      await flushPromises()

      expect(unhandledHandler).not.toHaveBeenCalled()
    } finally {
      // Guarantee removal even if an assertion above throws — otherwise
      // the global listener would leak into later tests and turn an
      // unrelated promise rejection into a failure (Copilot review #986).
      window.removeEventListener('unhandledrejection', unhandledHandler)
    }
  })

  it('escalates a play() NotSupportedError to the load-error retry flow', async () => {
    const rejection = Object.assign(new Error('The element has no supported sources.'), {
      name: 'NotSupportedError',
    })
    stubAudioElement({
      play: vi.fn().mockRejectedValue(rejection),
    })

    const wrapper = mount(MessageAudio, {
      props: {
        url: '/api/v1/files/uploads/13/voice.ogg',
      },
    })

    await wrapper.find('[data-testid="btn-audio-play"]').trigger('click')
    await flushPromises()

    // Disabled spinner button confirms the retry flow was entered.
    const playBtn = wrapper.find<HTMLButtonElement>('[data-testid="btn-audio-play"]')
    expect(playBtn.attributes('disabled')).toBeDefined()
  })
})
