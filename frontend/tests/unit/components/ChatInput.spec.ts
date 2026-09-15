import { describe, it, expect, beforeEach, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'

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
