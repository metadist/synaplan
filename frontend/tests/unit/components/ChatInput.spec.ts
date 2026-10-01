import { describe, it, expect, beforeEach, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'
import { chatApi } from '@/services/api/chatApi'
import { deleteFile } from '@/services/filesService'
import { useAiConfigStore } from '@/stores/aiConfig'
import { useModelMixStore } from '@/stores/modelMix'
import type { AIModel } from '@/types/ai-models'

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, fullPath: '/chat' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, string>) => {
      const translations: Record<string, string> = {
        'chatInput.tools.summarizeInstruction':
          'Summarize the attached document. Length: {length}. Answer in {language}.',
        'chatInput.tools.summarizeLengthValue.short': 'short',
        'chatInput.tools.summarizeLengthValue.medium': 'medium',
        'chatInput.tools.summarizeLengthValue.long': 'long',
        'chatInput.tools.summarizeLang.de': 'German',
        'chatInput.tools.summarizeLang.en': 'English',
        'chatInput.tools.summarizeLang.es': 'Spanish',
        'chatInput.tools.summarizeLang.fr': 'French',
        'chatInput.tools.summarizeLang.tr': 'Turkish',
      }
      const template = translations[key] ?? key
      if (!params) {
        return template
      }
      return template.replace(/\{(\w+)\}/g, (_, name: string) => params[name] ?? `{${name}}`)
    },
    locale: { value: 'en' },
  }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    speech: {
      webSpeechEnabled: false,
      whisperEnabled: false,
      speechToTextAvailable: false,
    },
  }),
}))

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    uploadChatFile: vi.fn().mockResolvedValue({
      success: true,
      file_id: 1,
      filename: 'doc.pdf',
      size: 3,
      mime: 'application/pdf',
      file_type: 'pdf',
      status: 'ready',
      extracted_text_length: 10,
    }),
    transcribeAudio: vi.fn(),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
  }),
}))

vi.mock('@/services/filesService', () => ({
  getFileGroups: vi.fn().mockResolvedValue([]),
  deleteFile: vi.fn().mockResolvedValue({ success: true, message: 'ok' }),
}))

vi.mock('@/services/api/configApi', () => ({
  configApi: {
    getModels: vi.fn().mockResolvedValue({ success: true, models: {}, providers: [] }),
    getDefaultModels: vi.fn().mockResolvedValue({ success: true, defaults: {} }),
    saveDefaultModels: vi.fn().mockResolvedValue({ success: true, message: 'ok' }),
    resetDefaultModels: vi.fn().mockResolvedValue({ success: true, message: 'ok', defaults: {} }),
  },
}))

const TextareaStub = {
  props: ['modelValue', 'placeholder', 'rows'],
  emits: ['update:modelValue', 'focus', 'blur'],
  template:
    '<textarea data-testid="input-chat-message" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  methods: {
    focus(this: { $el: HTMLTextAreaElement }) {
      this.$el.focus()
    },
  },
}

type ChatInputExposed = {
  armSummarize: () => void
  uploadFiles: (files: File[]) => Promise<void>
}

const mountInput = (props: Record<string, unknown> = {}): VueWrapper =>
  mount(ChatInput, {
    attachTo: document.body,
    props,
    global: {
      mocks: { $t: (key: string) => key },
      stubs: {
        Icon: true,
        Textarea: TextareaStub,
        CommandPalette: true,
        FileMentionPalette: true,
        ToolsDropdown: true,
        ToolBadge: true,
        ModelDropdown: true,
        KnowledgeFolderPicker: true,
        FileSelectionModal: true,
        PastedTextModal: true,
        QuoteChip: true,
      },
    },
  })

const attachPdf = async (wrapper: VueWrapper) => {
  await (wrapper.vm as unknown as ChatInputExposed).uploadFiles([
    new File(['hi'], 'doc.pdf', { type: 'application/pdf' }),
  ])
  await flushPromises()
}

describe('ChatInput summarize tool', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('shows options when armed and hides them on send', async () => {
    const wrapper = mountInput()
    expect(wrapper.find('[data-testid="summarize-options"]').exists()).toBe(false)

    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="summarize-options"]').exists()).toBe(true)

    await attachPdf(wrapper)
    await wrapper.get('[data-testid="btn-chat-send"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('send')).toBeTruthy()
    expect(wrapper.find('[data-testid="summarize-options"]').exists()).toBe(false)
  })

  it('hides options when the last attachment is removed', async () => {
    const wrapper = mountInput()
    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await attachPdf(wrapper)
    expect(wrapper.find('[data-testid="summarize-options"]').exists()).toBe(true)

    await wrapper.get('[data-testid="btn-remove-chat-file"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="summarize-options"]').exists()).toBe(false)
  })

  it('prefills the composer only when it is empty', async () => {
    const wrapper = mountInput()
    const textarea = wrapper.get('[data-testid="input-chat-message"]')

    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await attachPdf(wrapper)
    expect((textarea.element as HTMLTextAreaElement).value).toBe(
      'Summarize the attached document. Length: medium. Answer in English.'
    )

    const typed = mountInput()
    await typed.get('[data-testid="input-chat-message"]').setValue('keep my wording')
    ;(typed.vm as unknown as ChatInputExposed).armSummarize()
    await attachPdf(typed)
    expect(
      (typed.get('[data-testid="input-chat-message"]').element as HTMLTextAreaElement).value
    ).toBe('keep my wording')
  })

  it('prefills when a file is already attached', async () => {
    const wrapper = mountInput()
    await attachPdf(wrapper)
    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await wrapper.vm.$nextTick()

    expect(
      (wrapper.get('[data-testid="input-chat-message"]').element as HTMLTextAreaElement).value
    ).toBe('Summarize the attached document. Length: medium. Answer in English.')
  })

  it('rewrites the generated instruction when length or language changes', async () => {
    const wrapper = mountInput()
    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await attachPdf(wrapper)

    const textarea = wrapper.get('[data-testid="input-chat-message"]')
    await wrapper.get('[data-testid="select-summarize-length"]').setValue('short')
    await wrapper.vm.$nextTick()
    expect((textarea.element as HTMLTextAreaElement).value).toBe(
      'Summarize the attached document. Length: short. Answer in English.'
    )

    await wrapper.get('[data-testid="select-summarize-language"]').setValue('fr')
    await wrapper.vm.$nextTick()
    expect((textarea.element as HTMLTextAreaElement).value).toBe(
      'Summarize the attached document. Length: short. Answer in French.'
    )
  })

  it('does not overwrite a manually edited instruction', async () => {
    const wrapper = mountInput()
    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await attachPdf(wrapper)

    await wrapper.get('[data-testid="input-chat-message"]').setValue('keep my wording')
    await wrapper.get('[data-testid="select-summarize-length"]').setValue('long')
    await wrapper.get('[data-testid="select-summarize-language"]').setValue('de')
    await wrapper.vm.$nextTick()

    expect(
      (wrapper.get('[data-testid="input-chat-message"]').element as HTMLTextAreaElement).value
    ).toBe('keep my wording')
  })

  it('sends the selected summarize language with the attachment', async () => {
    const wrapper = mountInput()
    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await attachPdf(wrapper)
    await wrapper.get('[data-testid="select-summarize-language"]').setValue('fr')
    await wrapper.vm.$nextTick()
    await wrapper.get('[data-testid="btn-chat-send"]').trigger('click')
    await wrapper.vm.$nextTick()

    const sent = wrapper.emitted('send')?.[0] as [string, { language?: string; fileIds?: number[] }]
    expect(sent[1]).toMatchObject({ language: 'fr', fileIds: [1] })
  })

  it('does not send while summarize is armed without a file', async () => {
    const wrapper = mountInput()
    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await wrapper.vm.$nextTick()
    await wrapper.get('[data-testid="input-chat-message"]').setValue('summarize this')
    await wrapper.get('[data-testid="btn-chat-send"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('send')).toBeFalsy()
    expect(wrapper.find('[data-testid="summarize-options"]').exists()).toBe(true)
  })

  it('does not arm for guests', async () => {
    const wrapper = mountInput({ isGuestMode: true })
    ;(wrapper.vm as unknown as ChatInputExposed).armSummarize()
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="summarize-options"]').exists()).toBe(false)
    expect(wrapper.emitted('guestFeatureGate')).toEqual([['attach']])
  })
})

describe('ChatInput staged attachments', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    document.body.innerHTML = ''
    vi.mocked(deleteFile).mockClear()
    vi.mocked(chatApi.uploadChatFile).mockReset()
    vi.mocked(chatApi.uploadChatFile).mockResolvedValue({
      success: true,
      file_id: 1,
      filename: 'doc.pdf',
      size: 3,
      mime: 'application/pdf',
      file_type: 'pdf',
      status: 'ready',
      extracted_text_length: 10,
    })
  })

  it('deletes a staged upload when the chip is removed', async () => {
    const wrapper = mountInput()
    await attachPdf(wrapper)

    await wrapper.get('[data-testid="btn-remove-chat-file"]').trigger('click')
    await flushPromises()

    expect(deleteFile).toHaveBeenCalledWith(1)
    expect(wrapper.find('[data-testid="btn-remove-chat-file"]').exists()).toBe(false)
  })

  it('deletes the staged row when extraction fails', async () => {
    vi.mocked(chatApi.uploadChatFile).mockResolvedValueOnce({
      success: true,
      file_id: 9,
      filename: 'bad.pdf',
      size: 3,
      mime: 'application/pdf',
      file_type: 'pdf',
      status: 'error',
      extracted_text_length: 0,
      extraction_error: 'document_extraction_failed',
    })

    const wrapper = mountInput()
    await (wrapper.vm as unknown as ChatInputExposed).uploadFiles([
      new File(['x'], 'bad.pdf', { type: 'application/pdf' }),
    ])
    await flushPromises()

    expect(deleteFile).toHaveBeenCalledWith(9)
    expect(wrapper.find('[data-testid="btn-remove-chat-file"]').exists()).toBe(false)
  })
})

const pickedChatModel = (): AIModel => ({
  id: 55,
  service: 'OpenAI',
  name: 'GPT-5.4',
  tag: 'CHAT',
  providerId: 'gpt-5.4',
  quality: 9,
  rating: 1,
  priceIn: 1,
  priceOut: 1,
  description: null,
  isSystemModel: false,
  features: [],
})

describe('ChatInput explicit model pick', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('drops the caption and modelId after a model mix is applied', async () => {
    const aiConfig = useAiConfigStore()
    aiConfig.models.CHAT = [pickedChatModel()]

    const wrapper = mount(ChatInput, {
      attachTo: document.body,
      global: {
        mocks: { $t: (key: string) => key },
        stubs: {
          Icon: true,
          Textarea: TextareaStub,
          CommandPalette: true,
          FileMentionPalette: true,
          ToolsDropdown: true,
          ToolBadge: true,
          ModelDropdown: {
            name: 'ModelDropdown',
            props: ['modelValue'],
            emits: ['update:modelValue'],
            template:
              '<button type="button" data-testid="stub-pick-model" @click="$emit(\'update:modelValue\', 55)">pick</button>',
          },
          KnowledgeFolderPicker: true,
          FileSelectionModal: true,
          PastedTextModal: true,
          QuoteChip: true,
        },
      },
    })

    expect(wrapper.find('[data-testid="chat-model-caption"]').exists()).toBe(false)

    await wrapper.get('[data-testid="btn-chat-plus"]').trigger('click')
    await wrapper.get('[data-testid="stub-pick-model"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="chat-model-caption"]').exists()).toBe(true)

    expect(await useModelMixStore().applyMix('default')).toBe(true)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="chat-model-caption"]').exists()).toBe(false)

    await wrapper.get('[data-testid="input-chat-message"]').setValue('Hello')
    await wrapper.get('[data-testid="btn-chat-send"]').trigger('click')

    const sent = wrapper.emitted('send')?.[0] as [string, { modelId?: number }]
    expect(sent[0]).toBe('Hello')
    expect(sent[1].modelId).toBeUndefined()

    wrapper.unmount()
  })
})
