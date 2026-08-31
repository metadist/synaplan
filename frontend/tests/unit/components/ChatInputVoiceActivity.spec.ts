import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import ChatInput from '@/components/ChatInput.vue'

// The record-then-transcribe path hands the user nothing until the upload
// finishes: no interim words, no partial text. That is the only path the
// native app ever takes (the Web Speech seam is off in the app shell), so
// without an activity strip a live microphone looks exactly like a dead one.
// These specs pin the strip to that path and to that path only.

const VOICE_ACTIVITY = '[data-testid="chat-voice-activity"]'
const MIC_BUTTON = '[data-testid="btn-chat-voice"]'

let webSpeechSupported = false
let recorderOptions: {
  onStart?: () => void
  onStop?: () => void
  onDataAvailable?: (blob: Blob) => void
  onError?: (error: { messageKey: string }) => void
} = {}

/** Resolver for the pending transcription, so specs can hold it mid-flight. */
let resolveTranscription: (value: { text: string; file_id: number }) => void = () => {}

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, fullPath: '/chat' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    speech: {
      webSpeechEnabled: true,
      whisperEnabled: true,
      speechToTextAvailable: true,
    },
  }),
}))

vi.mock('@/services/webSpeechService', () => ({
  isWebSpeechSupported: () => webSpeechSupported,
  WebSpeechService: class {
    start = vi.fn().mockResolvedValue(undefined)
    stop = vi.fn()
    abort = vi.fn()
  },
}))

vi.mock('@/services/audioRecorder', () => ({
  AudioRecorder: class {
    constructor(options: typeof recorderOptions) {
      recorderOptions = options
    }
    checkSupport = vi.fn().mockResolvedValue({ supported: true, hasDevices: true })
    startRecording = vi.fn().mockImplementation(async () => {
      recorderOptions.onStart?.()
    })
    stopRecording = vi.fn()
  },
}))

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    transcribeAudio: vi.fn().mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveTranscription = resolve
        })
    ),
  },
}))

const mountInput = (): VueWrapper =>
  mount(ChatInput, {
    global: {
      mocks: { $t: (key: string) => key },
      stubs: {
        Icon: true,
        // Needs a real `focus`: the component focuses the textarea after a
        // transcript lands, and a bare stub turns that into an unhandled
        // rejection that has nothing to do with the behaviour under test.
        Textarea: { template: '<textarea />', methods: { focus() {} } },
        CommandPalette: true,
        FileMentionPalette: true,
        ToolsDropdown: true,
        ToolBadge: true,
        ModelDropdown: true,
        KnowledgeFolderPicker: true,
        FileSelectionModal: true,
        QuoteChip: true,
      },
    },
  })

describe('ChatInput voice activity strip', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    webSpeechSupported = false
    recorderOptions = {}
  })

  it('appears while the recorder is capturing', async () => {
    const wrapper = mountInput()

    await wrapper.get(MIC_BUTTON).trigger('click')
    await nextTick()

    expect(wrapper.find(VOICE_ACTIVITY).exists()).toBe(true)
    expect(wrapper.get(VOICE_ACTIVITY).text()).toContain('chatInput.voiceStatus.recording')
  })

  it('stays visible between tapping stop and the recorder handing over the audio', async () => {
    // MediaRecorder's onstop fires asynchronously, so there is a real window
    // where the user has tapped stop but no audio has arrived yet. The strip
    // must not blink out during that window (regression test: the stop tap
    // used to clear `isRecording` synchronously).
    const wrapper = mountInput()

    await wrapper.get(MIC_BUTTON).trigger('click')
    await wrapper.get(MIC_BUTTON).trigger('click')
    await nextTick()

    expect(wrapper.find(VOICE_ACTIVITY).exists()).toBe(true)
    expect(wrapper.get(VOICE_ACTIVITY).text()).toContain('chatInput.voiceStatus.recording')
  })

  it('switches to the transcribing label while the upload is in flight', async () => {
    const wrapper = mountInput()

    await wrapper.get(MIC_BUTTON).trigger('click')
    await wrapper.get(MIC_BUTTON).trigger('click')
    // Real recorder order on stop: onDataAvailable first, then onStop.
    recorderOptions.onDataAvailable?.(new Blob(['audio']))
    recorderOptions.onStop?.()
    await nextTick()

    expect(wrapper.get(VOICE_ACTIVITY).text()).toContain('chatInput.voiceStatus.transcribing')
  })

  it('disappears once the transcript arrives', async () => {
    const wrapper = mountInput()

    await wrapper.get(MIC_BUTTON).trigger('click')
    await wrapper.get(MIC_BUTTON).trigger('click')
    recorderOptions.onDataAvailable?.(new Blob(['audio']))
    recorderOptions.onStop?.()
    await nextTick()

    resolveTranscription({ text: 'hello', file_id: 1 })
    await new Promise((resolve) => setTimeout(resolve, 0))
    await nextTick()

    expect(wrapper.find(VOICE_ACTIVITY).exists()).toBe(false)
  })

  it('stays hidden on the Web Speech path, which streams words into the textarea itself', async () => {
    webSpeechSupported = true
    const wrapper = mountInput()

    await wrapper.get(MIC_BUTTON).trigger('click')
    await nextTick()

    expect(wrapper.find(VOICE_ACTIVITY).exists()).toBe(false)
  })

  it('is hidden before the microphone is used', () => {
    expect(mountInput().find(VOICE_ACTIVITY).exists()).toBe(false)
  })
})
