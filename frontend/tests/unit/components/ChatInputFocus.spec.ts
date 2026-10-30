import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'

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
      webSpeechEnabled: false,
      whisperEnabled: false,
      speechToTextAvailable: false,
    },
  }),
}))

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    uploadChatFile: vi.fn(),
    transcribeAudio: vi.fn(),
  },
}))

// Mirrors the real Textarea component: v-model plus an exposed focus() that
// forwards to the underlying element, so document.activeElement is meaningful.
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

const mountInput = (): VueWrapper =>
  mount(ChatInput, {
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
        ModelDropdown: true,
        KnowledgeFolderPicker: true,
        FileSelectionModal: true,
        PastedTextModal: true,
        QuoteChip: true,
      },
    },
  })

describe('ChatInput focus handling', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('keeps the textarea focused after sending via the send button', async () => {
    const wrapper = mountInput()
    const textarea = wrapper.get('[data-testid="input-chat-message"]')
    await textarea.setValue('hello there')

    await wrapper.get('[data-testid="btn-chat-send"]').trigger('click')

    expect(wrapper.emitted('send')).toBeTruthy()
    expect(document.activeElement).toBe(textarea.element)
  })

  it('keeps the textarea focused after sending with Enter', async () => {
    const wrapper = mountInput()
    const textarea = wrapper.get('[data-testid="input-chat-message"]')
    await textarea.setValue('hello there')

    await textarea.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('send')).toBeTruthy()
    expect(document.activeElement).toBe(textarea.element)
  })

  it('focuses the composer when the chat opens', async () => {
    const wrapper = mountInput()
    const textarea = wrapper.get('[data-testid="input-chat-message"]')
    expect(document.activeElement).toBe(textarea.element)
    wrapper.unmount()
  })

  it('types into the composer when a letter is pressed outside it', async () => {
    const wrapper = mountInput()
    const textarea = wrapper.get('[data-testid="input-chat-message"]')
    const button = document.createElement('button')
    document.body.appendChild(button)
    button.focus()

    button.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'h', bubbles: true, cancelable: true })
    )

    expect((textarea.element as HTMLTextAreaElement).value).toBe('h')
    expect(document.activeElement).toBe(textarea.element)
    button.remove()
    wrapper.unmount()
  })

  it('leaves a letter typed in another field where it is', async () => {
    const wrapper = mountInput()
    const other = document.createElement('input')
    document.body.appendChild(other)
    other.focus()

    other.dispatchEvent(new KeyboardEvent('keydown', { key: 'h', bubbles: true, cancelable: true }))

    const textarea = wrapper.get('[data-testid="input-chat-message"]')
    expect((textarea.element as HTMLTextAreaElement).value).toBe('')
    expect(document.activeElement).toBe(other)
    other.remove()
    wrapper.unmount()
  })
})
