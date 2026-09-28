import { describe, it, expect, beforeEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'
import { useAiConfigStore } from '@/stores/aiConfig'
import type { AIModel } from '@/types/ai-models'

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, fullPath: '/chat' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
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
    uploadChatFile: vi.fn(),
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
  deleteFile: vi.fn(),
}))

const TextareaStub = {
  props: ['modelValue'],
  emits: ['update:modelValue'],
  template:
    '<textarea data-testid="input-chat-message" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
  methods: {
    focus() {},
  },
}

const chatModel = (overrides: Partial<AIModel> = {}): AIModel => ({
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
  features: ['reasoning'],
  reasoningLevels: ['none', 'low', 'medium', 'high', 'xhigh'],
  reasoningEffortDefault: 'medium',
  ...overrides,
})

const mountInput = () =>
  mount(ChatInput, {
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
        DesktopJobCard: true,
      },
    },
  })

describe('ChatInput reasoning level', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('shows the model default on the open composer and sends that level', async () => {
    const store = useAiConfigStore()
    store.models.CHAT = [chatModel()]
    store.defaults.CHAT = 55

    const wrapper = mountInput()
    await flushPromises()

    const select = wrapper.get('[data-testid="select-reasoning-effort"]')
    expect((select.element as HTMLSelectElement).value).toBe('medium')
    expect(wrapper.text()).toContain('chatInput.reasoningLevel.medium')

    await wrapper.get('[data-testid="input-chat-message"]').setValue('Explain this')
    await select.setValue('xhigh')
    await wrapper.get('[data-testid="btn-chat-send"]').trigger('click')

    const sent = wrapper.emitted('send')?.[0] as [
      string,
      { includeReasoning?: boolean; reasoningEffort?: string },
    ]
    expect(sent[1]).toMatchObject({ includeReasoning: true, reasoningEffort: 'xhigh' })
  })

  it('sends reasoning off when the chosen level is none', async () => {
    const store = useAiConfigStore()
    store.models.CHAT = [chatModel()]
    store.defaults.CHAT = 55

    const wrapper = mountInput()
    await flushPromises()
    await wrapper.get('[data-testid="input-chat-message"]').setValue('Hi')
    await wrapper.get('[data-testid="select-reasoning-effort"]').setValue('none')
    await wrapper.get('[data-testid="btn-chat-send"]').trigger('click')

    const sent = wrapper.emitted('send')?.[0] as [
      string,
      { includeReasoning?: boolean; reasoningEffort?: string },
    ]
    expect(sent[1]).toMatchObject({ includeReasoning: false, reasoningEffort: 'none' })
  })

  it('keeps the level control hidden when the model has no levels', async () => {
    const store = useAiConfigStore()
    store.models.CHAT = [
      chatModel({
        id: 7,
        features: ['reasoning'],
        reasoningLevels: undefined,
        reasoningEffortDefault: undefined,
      }),
    ]
    store.defaults.CHAT = 7

    const wrapper = mountInput()
    await flushPromises()

    expect(wrapper.find('[data-testid="select-reasoning-effort"]').exists()).toBe(false)
  })
})
