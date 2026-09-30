import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'
import { chatApi } from '@/services/api/chatApi'

let browserSpeech = false
let speechOptions: {
  onStart?: () => void
  onResult?: (snapshot: { final: string; interim: string }) => void
  onEnd?: () => void
} = {}
let recorderOptions: {
  onStart?: () => void
  onDataAvailable?: (blob: Blob) => Promise<void>
} = {}
const abortRecognition = vi.fn()
const stopRecording = vi.fn()
let holdStart = false
let releaseStart: (() => void) | null = null

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, fullPath: '/chat' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    error: vi.fn(),
    success: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
    push: vi.fn(),
    remove: vi.fn(),
    notifications: { value: [] },
  }),
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
  isWebSpeechSupported: () => browserSpeech,
  WebSpeechService: class {
    start: () => Promise<void>
    abort = abortRecognition
    stop = vi.fn()
    constructor(options: typeof speechOptions) {
      speechOptions = options
      this.start = vi.fn(async () => {
        speechOptions.onStart?.()
      })
    }
  },
}))

vi.mock('@/services/audioRecorder', () => ({
  AudioRecorder: class {
    startRecording: () => Promise<void>
    stopRecording: () => void
    checkSupport = vi.fn().mockResolvedValue({ supported: true, hasDevices: true })
    constructor(options: typeof recorderOptions) {
      recorderOptions = options
      this.stopRecording = stopRecording
      this.startRecording = vi.fn(
        () =>
          new Promise<void>((resolve) => {
            const begin = () => {
              recorderOptions.onStart?.()
              resolve()
            }
            if (!holdStart) {
              begin()
              return
            }
            releaseStart = begin
          })
      )
    }
  },
}))

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    uploadChatFile: vi.fn(),
    transcribeAudio: vi.fn().mockResolvedValue({ text: 'transcribed', file_id: 1 }),
  },
}))

interface DictationInput {
  startDictation: () => Promise<boolean>
  message: string
}

const mountInput = (): VueWrapper =>
  mount(ChatInput, {
    global: {
      mocks: { $t: (key: string) => key },
      stubs: {
        Icon: true,
        Textarea: { template: '<textarea />', methods: { focus() {} } },
        CommandPalette: true,
        FileMentionPalette: true,
        ToolsDropdown: true,
        ToolBadge: true,
        ModelDropdown: true,
        KnowledgeFolderPicker: true,
        FileSelectionModal: true,
        PastedTextCard: true,
        PastedTextModal: true,
        QuoteChip: true,
      },
    },
  })

const dictationOf = (wrapper: VueWrapper): DictationInput => wrapper.vm as unknown as DictationInput

describe('ChatInput dictation unmount', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    browserSpeech = false
    holdStart = false
    releaseStart = null
    speechOptions = {}
    recorderOptions = {}
    abortRecognition.mockClear()
    stopRecording.mockClear()
    vi.mocked(chatApi.transcribeAudio).mockClear()
  })

  it('aborts web speech on unmount and ignores a late result', async () => {
    browserSpeech = true
    const wrapper = mountInput()
    await expect(dictationOf(wrapper).startDictation()).resolves.toBe(true)

    speechOptions.onResult?.({ final: 'hello', interim: 'there' })
    expect(dictationOf(wrapper).message).toBe('hello there')

    wrapper.unmount()

    expect(abortRecognition).toHaveBeenCalledTimes(1)
    speechOptions.onResult?.({ final: 'goodbye', interim: '' })
    expect(dictationOf(wrapper).message).toBe('hello there')
  })

  it('stops the recorder on unmount and does not upload the recording', async () => {
    const wrapper = mountInput()
    await expect(dictationOf(wrapper).startDictation()).resolves.toBe(true)

    wrapper.unmount()

    expect(stopRecording).toHaveBeenCalledTimes(1)
    await recorderOptions.onDataAvailable?.(new Blob(['audio']))
    expect(chatApi.transcribeAudio).not.toHaveBeenCalled()
  })

  it('stops the microphone when recording starts after the composer unmounts', async () => {
    holdStart = true
    const wrapper = mountInput()
    const pending = dictationOf(wrapper).startDictation()
    await vi.waitFor(() => {
      expect(releaseStart).toBeTypeOf('function')
    })

    wrapper.unmount()
    const stopsAtUnmount = stopRecording.mock.calls.length
    expect(stopsAtUnmount).toBeGreaterThan(0)

    releaseStart?.()
    await pending

    expect(stopRecording.mock.calls.length).toBeGreaterThan(stopsAtUnmount)
    expect(chatApi.transcribeAudio).not.toHaveBeenCalled()
  })
})
