import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatInput from '@/components/ChatInput.vue'
import { ApiError } from '@/services/api/httpClient'
import { useChatsStore } from '@/stores/chats'

type DesktopRow = {
  id: number
  name: string
  status: string
  enabledSkills: string[]
  skillsReported: boolean
  lastSeen: number
  created: number
  presence: 'online' | 'away' | 'never' | 'revoked'
  capabilities: string[]
  keyPrefix: string | null
}

const harness = vi.hoisted(() => ({
  devices: { value: [] as DesktopRow[] },
  reload: vi.fn(async () => {}),
  prompt: vi.fn(async () => null as string | null),
  error: vi.fn(),
  success: vi.fn(),
  warning: vi.fn(),
  enqueueJob: vi.fn(),
  listJobs: vi.fn(async () => []),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, fullPath: '/chat' }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, string | number>) => {
      const translations: Record<string, string> = {
        'common.cancel': 'Cancel',
        'config.desktop.run.inactive':
          '{name} is disconnected. Connect it again, then try once more.',
        'config.desktop.run.enqueueFailed': 'The job could not be sent to {name}.',
        'config.desktop.run.noSkills':
          '{name} has not reported its skills yet. Type the skill name.',
        'config.desktop.run.noRunnableSkills':
          '{name} has no skill that may run while you are away. In Synaplan Desktop, turn on "Run when I am away" for a skill.',
        'config.desktop.run.pickSkill': 'Choose a skill for {name}.',
        'config.desktop.run.sent': 'Sent to "{name}".',
        'config.desktop.run.needPrompt': 'Type what the computer should do first.',
        'config.desktop.run.invalidSkill': 'Use only lowercase letters, numbers and hyphens.',
        'config.desktop.run.skillTitle': 'Run on this computer',
        'config.desktop.run.skillPlaceholder': 'e.g. pptx',
        'config.desktop.run.action': 'Run on this computer',
      }
      const template = translations[key] ?? key
      if (!params) return template
      return template.replace(/\{(\w+)\}/g, (_, name: string) =>
        String(params[name] ?? `{${name}}`)
      )
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

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: harness.success,
    error: harness.error,
    warning: harness.warning,
    info: vi.fn(),
  }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({
    prompt: harness.prompt,
    confirm: vi.fn(),
    alert: vi.fn(),
    choose: vi.fn(),
    close: vi.fn(),
  }),
}))

vi.mock('@/composables/useDesktopAgentFeature', () => ({
  isDesktopAgentEnabled: () => true,
}))

vi.mock('@/composables/useDesktopDevices', () => ({
  useDesktopDevices: () => ({
    devices: harness.devices,
    activeDevices: harness.devices,
    hasActiveDevices: harness.devices,
    ensureLoaded: vi.fn(),
    reload: harness.reload,
    loading: harness.devices,
    error: harness.devices,
  }),
}))

vi.mock('@/services/api/desktopApi', () => ({
  desktopApi: {
    enqueueJob: harness.enqueueJob,
    listJobs: harness.listJobs,
    listDevices: vi.fn(async () => []),
  },
}))

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    uploadChatFile: vi.fn(),
    transcribeAudio: vi.fn(),
  },
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
}

const ToolsDropdownStub = {
  name: 'ToolsDropdown',
  emits: ['runOnDevice'],
  template: '<div data-testid="tools-dropdown-stub" />',
}

function device(overrides: Partial<DesktopRow> = {}): DesktopRow {
  return {
    id: 1,
    name: 'tower',
    status: 'active',
    enabledSkills: ['pptx'],
    skillsReported: false,
    lastSeen: 1,
    created: 1,
    presence: 'online',
    capabilities: ['skill.run'],
    keyPrefix: null,
    ...overrides,
  }
}

async function mountInput(): Promise<VueWrapper> {
  const pinia = createPinia()
  setActivePinia(pinia)
  const wrapper = mount(ChatInput, {
    attachTo: document.body,
    global: {
      plugins: [pinia],
      mocks: {
        $t: (key: string, params?: Record<string, string>) =>
          params?.name ? `${key}|${params.name}` : key,
      },
      stubs: {
        Icon: true,
        Textarea: TextareaStub,
        CommandPalette: true,
        FileMentionPalette: true,
        ToolsDropdown: ToolsDropdownStub,
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
  const chats = useChatsStore()
  chats.activeChatId = 10
  await flushPromises()
  return wrapper
}

async function chooseComputer(wrapper: VueWrapper, row: DesktopRow = device()) {
  await wrapper.get('[data-testid="input-chat-message"]').setValue('Make three slides')
  await wrapper.get('[data-testid="btn-chat-plus"]').trigger('click')
  wrapper.findComponent(ToolsDropdownStub).vm.$emit('runOnDevice', {
    id: row.id,
    name: row.name,
    enabledSkills: row.enabledSkills,
  })
  await flushPromises()
}

describe('ChatInput run on this computer', () => {
  beforeEach(() => {
    localStorage.clear()
    document.body.innerHTML = ''
    harness.devices.value = [device()]
    harness.reload.mockReset()
    harness.reload.mockImplementation(async () => {})
    harness.prompt.mockReset()
    harness.prompt.mockResolvedValue(null)
    harness.error.mockReset()
    harness.success.mockReset()
    harness.warning.mockReset()
    harness.enqueueJob.mockReset()
    harness.enqueueJob.mockResolvedValue({ jobId: 7, status: 'queued', chatTitle: null })
    harness.listJobs.mockReset()
    harness.listJobs.mockResolvedValue([])
  })

  it('sends a reported skill to the chat that was open when the picker started', async () => {
    const wrapper = await mountInput()
    await chooseComputer(wrapper)

    expect(wrapper.find('[data-testid="dropdown-plus-panel"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="desktop-skill-picker"]').text()).toContain('pptx')
    harness.enqueueJob.mockImplementation(async () => {
      useChatsStore().activeChatId = 99
      return { jobId: 7, status: 'queued', chatTitle: null }
    })
    await wrapper.get('[data-testid="btn-desktop-skill-pptx"]').trigger('click')
    await flushPromises()

    expect(harness.enqueueJob).toHaveBeenCalledWith({
      deviceId: 1,
      skill: 'pptx',
      prompt: 'Make three slides',
      chatId: 10,
    })
    expect(harness.success).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="desktop-skill-picker"]').exists()).toBe(false)
  })

  it('drops the skill picker when the open chat changes', async () => {
    const wrapper = await mountInput()
    await chooseComputer(wrapper)
    expect(wrapper.find('[data-testid="desktop-skill-picker"]').exists()).toBe(true)

    useChatsStore().activeChatId = 20
    await flushPromises()

    expect(wrapper.find('[data-testid="desktop-skill-picker"]').exists()).toBe(false)
    expect(harness.enqueueJob).not.toHaveBeenCalled()
  })

  it('does not offer a computer that disappeared while the list reloaded', async () => {
    harness.reload.mockImplementation(async () => {
      harness.devices.value = [device({ status: 'revoked', enabledSkills: [] })]
    })
    const wrapper = await mountInput()
    await chooseComputer(wrapper)

    expect(wrapper.find('[data-testid="desktop-skill-picker"]').exists()).toBe(false)
    expect(harness.enqueueJob).not.toHaveBeenCalled()
    expect(harness.error).toHaveBeenCalledWith(
      'tower is disconnected. Connect it again, then try once more.'
    )
  })

  it('closes the plus panel when Escape is pressed', async () => {
    const wrapper = await mountInput()
    await wrapper.get('[data-testid="btn-chat-plus"]').trigger('click')
    expect(wrapper.find('[data-testid="dropdown-plus-panel"]').exists()).toBe(true)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(wrapper.find('[data-testid="dropdown-plus-panel"]').exists()).toBe(false)
  })

  it('shows the empty-skill sentence when the computer reported none', async () => {
    harness.devices.value = [device({ enabledSkills: [], skillsReported: true })]
    const wrapper = await mountInput()
    await chooseComputer(wrapper, device({ enabledSkills: [], skillsReported: true }))

    expect(wrapper.find('[data-testid="dropdown-plus-panel"]').exists()).toBe(false)
    const picker = wrapper.get('[data-testid="desktop-skill-picker"]').text()
    expect(picker).toContain('config.desktop.run.noRunnableSkills|tower')
    expect(picker).not.toContain('config.desktop.run.noSkills')
    expect(wrapper.find('[data-testid="btn-desktop-skill-pptx"]').exists()).toBe(false)
    expect(harness.prompt).not.toHaveBeenCalled()
  })

  it('asks for a skill name, with a translated cancel, when none were reported', async () => {
    harness.devices.value = [device({ enabledSkills: [] })]
    harness.prompt.mockResolvedValue('docx')
    const wrapper = await mountInput()
    await chooseComputer(wrapper, device({ enabledSkills: [] }))

    expect(harness.prompt).toHaveBeenCalledWith(
      expect.objectContaining({
        cancelText: 'Cancel',
        message: 'tower has not reported its skills yet. Type the skill name.',
      })
    )
    expect(harness.enqueueJob).toHaveBeenCalledWith(
      expect.objectContaining({ skill: 'docx', chatId: 10 })
    )
  })

  it('does not send when the skill prompt is cancelled', async () => {
    harness.devices.value = [device({ enabledSkills: [] })]
    harness.prompt.mockResolvedValue(null)
    const wrapper = await mountInput()
    await chooseComputer(wrapper, device({ enabledSkills: [] }))

    expect(harness.enqueueJob).not.toHaveBeenCalled()
  })

  it('names the computer when it is disconnected at send time', async () => {
    harness.devices.value = [device({ enabledSkills: [] })]
    harness.prompt.mockResolvedValue('pptx')
    harness.enqueueJob.mockRejectedValue(
      new ApiError(400, 'Device is not active.', 'device_inactive')
    )
    const wrapper = await mountInput()
    await chooseComputer(wrapper, device({ enabledSkills: [] }))

    expect(harness.error).toHaveBeenCalledWith(
      'tower is disconnected. Connect it again, then try once more.'
    )
    expect(harness.error).not.toHaveBeenCalledWith('Device is not active.')
  })
})
