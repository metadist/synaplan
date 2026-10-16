import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'
import { useCommandsStore } from '@/stores/commands'

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, fullPath: '/chat' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'en' } }),
}))

vi.mock('@/stores/config', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/stores/config')>()),
  useConfigStore: () => ({
    speech: { webSpeechEnabled: false, whisperEnabled: false, speechToTextAvailable: false },
  }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))

vi.mock('@/services/filesService', () => ({
  getFileGroups: vi.fn().mockResolvedValue([]),
  deleteFile: vi.fn().mockResolvedValue({ success: true, message: 'ok' }),
}))

vi.mock('@/services/api/configApi', () => ({
  configApi: {
    getModels: vi.fn().mockResolvedValue({ success: true, models: {}, providers: [] }),
    getDefaultModels: vi.fn().mockResolvedValue({ success: true, defaults: {} }),
  },
}))

const TextareaStub = {
  props: ['modelValue', 'placeholder', 'rows'],
  emits: ['update:modelValue', 'focus', 'blur'],
  template:
    '<textarea data-testid="input-chat-message" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
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

describe('ChatInput slash menu', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    document.body.innerHTML = ''
  })

  it('loads the saved prompts each time the menu opens, not on every keystroke', async () => {
    const commands = useCommandsStore()
    const load = vi.spyOn(commands, 'loadSavedPrompts').mockResolvedValue()
    const wrapper = mountInput()
    const input = wrapper.find('[data-testid="input-chat-message"]')

    await input.setValue('/')
    await input.setValue('/no')
    await input.setValue('/notiz')
    await flushPromises()
    expect(load).toHaveBeenCalledTimes(1)

    await input.setValue('')
    await flushPromises()
    await input.setValue('/')
    await flushPromises()
    expect(load).toHaveBeenCalledTimes(2)

    wrapper.unmount()
  })

  it('does not load while the text does not start with a slash', async () => {
    const commands = useCommandsStore()
    const load = vi.spyOn(commands, 'loadSavedPrompts').mockResolvedValue()
    const wrapper = mountInput()

    await wrapper.find('[data-testid="input-chat-message"]').setValue('hello /notiz')
    await flushPromises()

    expect(load).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
