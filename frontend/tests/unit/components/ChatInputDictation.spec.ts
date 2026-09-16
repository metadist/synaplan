import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'

// MOBILE-APP SEAM: `startDictation()` is exposed for the iOS "Start dictation"
// App Shortcut, so it is reached from outside the component and never through
// the microphone button these specs otherwise exercise. It has to stay safe on
// a server without speech-to-text and on a second tap while already recording.

let speechToTextAvailable = true
let recorderOptions: { onStart?: () => void } = {}
const startRecording = vi.fn(async () => {
  recorderOptions.onStart?.()
})
const showError = vi.fn()

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, fullPath: '/chat' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    error: showError,
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
      webSpeechEnabled: false,
      whisperEnabled: speechToTextAvailable,
      speechToTextAvailable,
    },
  }),
}))

vi.mock('@/services/webSpeechService', () => ({
  isWebSpeechSupported: () => false,
  WebSpeechService: class {
    start = vi.fn().mockResolvedValue(undefined)
    stop = vi.fn()
    abort = vi.fn()
  },
}))

vi.mock('@/services/audioRecorder', () => ({
  AudioRecorder: class {
    startRecording: () => Promise<void>
    constructor(options: { onStart?: () => void }) {
      recorderOptions = options
      // Assigned here rather than as a class field: the mock factory runs
      // while the outer `const` is still in its temporal dead zone.
      this.startRecording = startRecording
    }
    checkSupport = vi.fn().mockResolvedValue({ supported: true, hasDevices: true })
    stopRecording = vi.fn()
  },
}))

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    uploadChatFile: vi.fn(),
    transcribeAudio: vi.fn().mockResolvedValue({ text: '', file_id: 1 }),
  },
}))

interface DictationInput {
  startDictation: () => Promise<boolean>
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

describe('ChatInput.startDictation', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    speechToTextAvailable = true
    recorderOptions = {}
    startRecording.mockClear()
    showError.mockClear()
  })

  it('starts recording when the server can transcribe', async () => {
    const wrapper = mountInput()

    await expect(dictationOf(wrapper).startDictation()).resolves.toBe(true)
    expect(startRecording).toHaveBeenCalledTimes(1)
    expect(showError).not.toHaveBeenCalled()
  })

  it('leaves a running recording alone instead of stopping it', async () => {
    // The shortcut can be fired again while the microphone is already live.
    // Forwarding that to `toggleRecording()` would end the dictation the user
    // just asked for, so the second call has to be a no-op.
    const wrapper = mountInput()
    await dictationOf(wrapper).startDictation()

    await expect(dictationOf(wrapper).startDictation()).resolves.toBe(true)
    expect(startRecording).toHaveBeenCalledTimes(1)
  })

  it('explains itself instead of opening a dead microphone without a transcription path', async () => {
    speechToTextAvailable = false
    const wrapper = mountInput()

    await expect(dictationOf(wrapper).startDictation()).resolves.toBe(false)
    expect(showError).toHaveBeenCalledWith('chatInput.dictationUnavailable')
    expect(startRecording).not.toHaveBeenCalled()
  })
})
