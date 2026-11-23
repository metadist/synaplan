import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import AssistantPublishSection from '@/components/assistants/AssistantPublishSection.vue'
import { agentsApi, emptyAgentDraft, type Agent } from '@/services/api/agentsApi'
import { ApiError } from '@/services/api/httpClient'
import { useAgentsStore } from '@/stores/agents'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

const errorMock = vi.fn()
const successMock = vi.fn()
const warningMock = vi.fn()
const confirmMock = vi.fn()

vi.mock('@/services/api/agentsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/agentsApi')>()
  return {
    ...actual,
    agentsApi: {
      versions: vi.fn().mockResolvedValue([]),
      usage: vi.fn().mockResolvedValue({ byVersion: [], byDay: [] }),
      publish: vi.fn(),
      update: vi.fn(),
      get: vi.fn(),
      remove: vi.fn(),
    },
  }
})

const getPromptMock = vi.fn()

vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    getPrompt: (...args: unknown[]) => getPromptMock(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    error: errorMock,
    success: successMock,
    warning: warningMock,
  }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: confirmMock }),
}))

function agent(overrides: Partial<Agent> = {}): Agent {
  return {
    id: 3,
    slug: 'contract-review',
    name: 'Contract review',
    description: null,
    icon: '',
    status: 'draft',
    promptId: 9,
    parentId: null,
    source: 'manual',
    routable: false,
    publishedVersionId: null,
    draft: emptyAgentDraft(),
    createdAt: 1,
    updatedAt: 1,
    ...overrides,
  }
}

function mountSection() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })
  return mount(AssistantPublishSection, {
    global: {
      plugins: [i18n],
      stubs: { ShareDialog: true, AssistantUsagePanel: true },
    },
  })
}

describe('AssistantPublishSection', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    errorMock.mockReset()
    successMock.mockReset()
    warningMock.mockReset()
    confirmMock.mockReset()
    confirmMock.mockResolvedValue(false)
    getPromptMock.mockReset()
    getPromptMock.mockResolvedValue({ prompt: '' })
    vi.mocked(agentsApi.publish).mockReset()
    vi.mocked(agentsApi.update).mockReset()
    vi.mocked(agentsApi.get).mockReset()
    vi.mocked(agentsApi.versions).mockResolvedValue([])
    vi.mocked(agentsApi.usage).mockResolvedValue({ byVersion: [], byDay: [] })
    useAgentsStore().current = agent()
  })

  it('renders Publish and Share', () => {
    const wrapper = mountSection()

    expect(wrapper.get('[data-testid="btn-publish-assistant"]').text()).toBe('Publish')
    const share = wrapper.get('[data-testid="btn-share-assistant"]')
    expect(share.text()).toBe('Share')
    expect((share.element as HTMLButtonElement).disabled).toBe(false)
    expect(wrapper.get('[data-testid="hint-share-draft"]').text()).toContain('draft')
    expect(wrapper.get('[data-testid="btn-delete-assistant"]').text()).toBe('Delete')
  })

  it('disables share on an archived assistant', () => {
    useAgentsStore().current = agent({ status: 'archived' })
    const wrapper = mountSection()
    expect(
      (wrapper.get('[data-testid="btn-share-assistant"]').element as HTMLButtonElement).disabled
    ).toBe(true)
  })

  it('does not toast publishFailed when saving the draft fails', async () => {
    const store = useAgentsStore()
    store.current = agent()
    store.dirty = true
    vi.mocked(agentsApi.update).mockRejectedValue(new Error('models.chat must be a catalog key'))
    confirmMock.mockResolvedValue(true)

    const wrapper = mountSection()
    await wrapper.get('[data-testid="btn-publish-assistant"]').trigger('click')
    await vi.waitFor(() => {
      expect(agentsApi.update).toHaveBeenCalled()
    })

    expect(agentsApi.publish).not.toHaveBeenCalled()
    expect(errorMock).not.toHaveBeenCalledWith('Could not publish the assistant')
  })

  it('offers the derived description once before sharing an agent that has none', async () => {
    getPromptMock.mockResolvedValue({
      prompt: 'Write an offer from the price list and cite every price. Use the current list only.',
    })
    vi.mocked(agentsApi.update).mockResolvedValue(agent())
    const wrapper = mountSection()

    await wrapper.get('[data-testid="btn-share-assistant"]').trigger('click')
    const field = await vi.waitFor(() => wrapper.get('[data-testid="input-share-description"]'))
    expect((field.element as HTMLTextAreaElement).value).toBe(
      'Write an offer from the price list and cite every price.'
    )

    await wrapper.get('[data-testid="btn-share-description-save"]').trigger('click')
    await vi.waitFor(() => {
      expect(agentsApi.update).toHaveBeenCalledWith(3, {
        description: 'Write an offer from the price list and cite every price.',
      })
    })
    expect(wrapper.find('[data-testid="section-share-description"]').exists()).toBe(false)
    expect(useAgentsStore().current?.description).toBe(
      'Write an offer from the price list and cite every price.'
    )
  })

  it('shares straight away when a description already exists', async () => {
    useAgentsStore().current = agent({ description: 'Already described.' })
    const wrapper = mountSection()

    await wrapper.get('[data-testid="btn-share-assistant"]').trigger('click')

    expect(wrapper.find('[data-testid="section-share-description"]').exists()).toBe(false)
    expect(getPromptMock).not.toHaveBeenCalled()
  })

  it('reloads the assistant when publish reports nothing_changed', async () => {
    const published = agent({ status: 'published', publishedVersionId: 1 })
    vi.mocked(agentsApi.publish).mockRejectedValue(
      new ApiError(409, 'nothing_changed', 'nothing_changed')
    )
    vi.mocked(agentsApi.get).mockResolvedValue(published)
    confirmMock.mockResolvedValue(true)

    const wrapper = mountSection()
    await wrapper.get('[data-testid="btn-publish-assistant"]').trigger('click')
    await vi.waitFor(() => {
      expect(warningMock).toHaveBeenCalledWith('Nothing changed since the last version')
    })

    expect(errorMock).not.toHaveBeenCalled()
    expect(agentsApi.get).toHaveBeenCalledWith(3)
    expect(useAgentsStore().current?.status).toBe('published')
  })
})
