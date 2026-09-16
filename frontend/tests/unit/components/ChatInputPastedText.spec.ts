import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import ChatInput from '@/components/ChatInput.vue'
import { PASTE_BLOCK_MIN_CHARS } from '@/utils/pastedContent'

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

const longPaste = 'L'.repeat(PASTE_BLOCK_MIN_CHARS)

const mountInput = (): VueWrapper =>
  mount(ChatInput, {
    attachTo: document.body,
    global: {
      mocks: { $t: (key: string) => key },
      stubs: {
        Icon: true,
        Textarea: {
          template: '<textarea data-testid="input-chat-message" />',
          methods: { focus() {} },
        },
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

const pasteIntoComposer = async (wrapper: VueWrapper, text: string) => {
  const textarea = wrapper.get('[data-testid="input-chat-message"]').element as HTMLTextAreaElement
  textarea.focus()
  await wrapper.get('[data-testid="comp-chat-input"]').trigger('paste', {
    clipboardData: {
      items: [],
      getData: (type: string) => (type === 'text/plain' ? text : ''),
    },
  })
  await nextTick()
  return textarea
}

describe('ChatInput pasted text blocks', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('leaves a short paste in the textarea', async () => {
    const wrapper = mountInput()
    const textarea = await pasteIntoComposer(wrapper, 'short note')

    expect(wrapper.find('[data-testid="chip-pasted-text"]').exists()).toBe(false)
    expect(document.activeElement).toBe(textarea)
  })

  it('turns a long paste into a card and keeps the textarea focused', async () => {
    const wrapper = mountInput()
    const textarea = await pasteIntoComposer(wrapper, longPaste)

    expect(wrapper.find('[data-testid="chip-pasted-text"]').exists()).toBe(true)
    expect(document.activeElement).toBe(textarea)
  })

  it('removes the card with the X button', async () => {
    const wrapper = mountInput()
    await pasteIntoComposer(wrapper, longPaste)

    await wrapper.get('[data-testid="btn-pasted-text-remove"]').trigger('click')
    await nextTick()

    expect(wrapper.find('[data-testid="chip-pasted-text"]').exists()).toBe(false)
  })

  it('enables send from a pasted block alone and wraps the text on send', async () => {
    const wrapper = mountInput()
    await pasteIntoComposer(wrapper, longPaste)

    const send = wrapper.get('[data-testid="btn-chat-send"]')
    expect((send.element as HTMLButtonElement).disabled).toBe(false)

    await send.trigger('click')
    const emitted = wrapper.emitted('send')
    expect(emitted).toBeTruthy()
    expect(String(emitted?.[0]?.[0])).toContain('<pasted-content>')
    expect(String(emitted?.[0]?.[0])).toContain(longPaste)
    expect(wrapper.find('[data-testid="chip-pasted-text"]').exists()).toBe(false)
  })
})
