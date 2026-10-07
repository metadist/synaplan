import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import AIModelsConfiguration from '@/components/config/AIModelsConfiguration.vue'
import { useAuthStore } from '@/stores/auth'
import type { AIModel } from '@/types/ai-models'

const { getModels, getDefaultModels, saveDefaultModels, checkModelAvailability } = vi.hoisted(
  () => ({
    getModels: vi.fn(),
    getDefaultModels: vi.fn(),
    saveDefaultModels: vi.fn(),
    checkModelAvailability: vi.fn(),
  })
)

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {}, path: '/config/ai-models' }),
  useRouter: () => ({ replace: vi.fn() }),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => notify,
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

vi.mock('@/services/api/adminEmbeddingApi', () => ({
  adminEmbeddingApi: {
    getStatus: vi.fn().mockResolvedValue({ guard: null }),
  },
}))

vi.mock('@/services/api/configApi', () => ({
  getModels,
  getDefaultModels,
  saveDefaultModels,
  checkModelAvailability,
  resetDefaultModels: vi.fn(),
}))

const chatModel: AIModel = {
  id: 42,
  service: 'groq',
  name: 'Llama',
  tag: 'chat',
  providerId: 'llama',
  quality: 1,
  rating: 1,
  priceIn: 0,
  priceOut: 0,
  description: null,
  isSystemModel: false,
  features: [],
}

const emptyDefaults = {
  SORT: null,
  CHAT: null,
  MEM: null,
  ANALYZE: null,
  VECTORIZE: null,
  PIC2TEXT: null,
  TEXT2PIC: null,
  PIC2PIC: null,
  TEXT2VID: null,
  IMG2VID: null,
  SOUND2TEXT: null,
  TEXT2SOUND: null,
}

describe('AIModelsConfiguration empty model row', () => {
  let wrapper: VueWrapper | null = null

  beforeEach(() => {
    setActivePinia(createPinia())
    getModels.mockResolvedValue({ success: true, models: { CHAT: [chatModel] }, providers: [] })
    getDefaultModels.mockResolvedValue({ success: true, defaults: { ...emptyDefaults } })
    checkModelAvailability.mockResolvedValue({
      available: true,
      provider_type: 'external',
      model_name: 'Llama',
      service: 'groq',
    })
    saveDefaultModels.mockResolvedValue({ success: true, message: 'saved' })
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.clearAllMocks()
  })

  const mountPage = async () => {
    wrapper = mount(AIModelsConfiguration, {
      global: {
        plugins: [createPinia()],
        stubs: {
          PageHeader: { template: '<div><slot /><slot name="actions" /></div>' },
          TabNav: { template: '<div />' },
          ServiceIcon: { template: '<span />' },
          ModelCostBadge: { template: '<span />' },
          EmbeddingSwitchModal: { template: '<div />' },
          EmbeddingRunsPanel: { template: '<div />' },
          AIModelsAdminPanel: { template: '<div />' },
          AddModelForm: { template: '<div />' },
          OpenAiCompatibleEndpointsPanel: { template: '<div />' },
          AccordionStack: { template: '<div><slot /></div>' },
          AccordionSection: { template: '<div><slot /></div>' },
          SectionJumpNav: { template: '<div />' },
        },
      },
    })
    await flushPromises()
    return wrapper
  }

  const chatTriggerLabel = () => {
    const row = wrapper!
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Chat / General AI'))
    expect(row).toBeTruthy()
    const spans = row!.get('[data-testid="btn-model-dropdown"]').findAll('span.truncate')
    return spans[spans.length - 1]
  }

  it('keeps the closed trigger quieter until a model is chosen', async () => {
    await mountPage()

    expect(chatTriggerLabel().classes()).toContain('txt-model-placeholder')
    expect(chatTriggerLabel().text()).toBe('-- Select Model --')

    const row = wrapper!
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Chat / General AI'))!
    await row.get('[data-testid="btn-model-dropdown"]').trigger('click')
    const options = row.findAll('[data-testid="btn-model-option"]')
    const modelOption = options.find((option) => option.text().includes('Llama'))
    expect(modelOption).toBeTruthy()
    await modelOption!.trigger('click')
    await flushPromises()

    expect(chatTriggerLabel().classes()).not.toContain('txt-model-placeholder')
    expect(chatTriggerLabel().text()).toBe('Llama')
  })

  it('shows the model that applies and says why when a saved choice cannot be used', async () => {
    const claude: AIModel = { ...chatModel, id: 249, service: 'anthropic', name: 'Claude' }
    const cerebras: AIModel = { ...chatModel, id: 385, service: 'Cerebras', name: 'GPT OSS 120B' }
    const sortModel: AIModel = { ...chatModel, id: 77, name: 'Sorter' }
    getModels.mockResolvedValue({
      success: true,
      models: { CHAT: [claude, cerebras], SORT: [sortModel] },
      providers: [],
    })
    getDefaultModels.mockResolvedValue({ success: true, defaults: { ...emptyDefaults, CHAT: 249 } })
    saveDefaultModels.mockResolvedValue({
      success: true,
      message: 'saved',
      defaults: { CHAT: 249 },
      replaced: { CHAT: 249 },
    })

    await mountPage()
    const row = wrapper!
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Chat / General AI'))!
    await row.get('[data-testid="btn-model-dropdown"]').trigger('click')
    await row
      .findAll('[data-testid="btn-model-option"]')
      .find((option) => option.text().includes('GPT OSS 120B'))!
      .trigger('click')
    await flushPromises()

    expect(chatTriggerLabel().text()).toBe('Claude')
    expect(notify.success).not.toHaveBeenCalled()
    expect(notify.warning).toHaveBeenCalledWith(
      "GPT OSS 120B is saved but can't be used right now, so Claude is used instead. Check its provider under Admin → AI Providers."
    )

    saveDefaultModels.mockClear()
    const sortRow = wrapper!
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Message Sorting'))!
    await sortRow.get('[data-testid="btn-model-dropdown"]').trigger('click')
    await sortRow
      .findAll('[data-testid="btn-model-option"]')
      .find((option) => option.text().includes('Sorter'))!
      .trigger('click')
    await flushPromises()

    expect(saveDefaultModels).toHaveBeenCalledTimes(1)
    expect(saveDefaultModels).toHaveBeenCalledWith({ defaults: { SORT: 77 } })
  })

  it('says when a saved choice cannot be used and nothing else can take over', async () => {
    const cerebras: AIModel = { ...chatModel, id: 385, service: 'Cerebras', name: 'GPT OSS 120B' }
    getModels.mockResolvedValue({ success: true, models: { CHAT: [cerebras] }, providers: [] })
    getDefaultModels.mockResolvedValue({ success: true, defaults: { ...emptyDefaults } })
    saveDefaultModels.mockResolvedValue({
      success: true,
      message: 'saved',
      defaults: { CHAT: 385 },
      replaced: { CHAT: null },
    })

    await mountPage()
    const row = wrapper!
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Chat / General AI'))!
    await row.get('[data-testid="btn-model-dropdown"]').trigger('click')
    await row
      .findAll('[data-testid="btn-model-option"]')
      .find((option) => option.text().includes('GPT OSS 120B'))!
      .trigger('click')
    await flushPromises()

    expect(notify.success).not.toHaveBeenCalled()
    expect(notify.warning).toHaveBeenCalledWith(
      "GPT OSS 120B is saved but can't be used right now, and no other model can take over. Check its provider under Admin → AI Providers."
    )
    expect(chatTriggerLabel().text()).toBe('-- Select Model --')
  })

  it('shows a retry instead of an empty menu when the model list fails', async () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    getModels.mockRejectedValueOnce(new Error('network'))

    await mountPage()

    expect(wrapper!.find('[data-testid="section-capabilities"]').exists()).toBe(false)
    expect(wrapper!.find('[data-testid="btn-model-dropdown"]').exists()).toBe(false)
    expect(wrapper!.get('[data-testid="section-models-load-error"]').text()).toContain(
      'The model list could not be loaded.'
    )

    getModels.mockResolvedValue({ success: true, models: { CHAT: [chatModel] }, providers: [] })
    await wrapper!.get('[data-testid="btn-retry-models"]').trigger('click')
    await flushPromises()

    expect(getModels).toHaveBeenCalledTimes(2)
    expect(wrapper!.find('[data-testid="section-models-load-error"]').exists()).toBe(false)
    expect(wrapper!.find('[data-testid="btn-model-dropdown"]').exists()).toBe(true)
    errorSpy.mockRestore()
  })

  it('filters a long model menu by name the way the chat menu does', async () => {
    const chatModels = [
      chatModel,
      { ...chatModel, id: 43, name: 'Gemini Flash', providerId: 'gemini-flash', service: 'google' },
      { ...chatModel, id: 44, name: 'Claude Sonnet', providerId: 'claude', service: 'anthropic' },
      { ...chatModel, id: 45, name: 'GPT', providerId: 'gpt-4o', service: 'openai' },
      { ...chatModel, id: 46, name: 'Mistral', providerId: 'mistral', service: 'mistral' },
      { ...chatModel, id: 47, name: 'Qwen', providerId: 'qwen', service: 'groq' },
    ]
    getModels.mockResolvedValue({ success: true, models: { CHAT: chatModels }, providers: [] })

    await mountPage()
    const row = wrapper!
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Chat / General AI'))!
    await row.get('[data-testid="btn-model-dropdown"]').trigger('click')

    const filter = row.get('[data-testid="input-model-choice-filter"]')
    await filter.setValue('claude')

    const names = row
      .findAll('[data-testid="btn-model-option"]')
      .map((option) => option.text())
      .filter((text) => !text.includes('Select Model'))
    expect(names).toHaveLength(1)
    expect(names[0]).toContain('Claude Sonnet')
    expect(names[0]).not.toContain('Gemini')

    await filter.setValue('no-such-model')
    expect(row.get('[data-testid="text-model-choice-filter-empty"]').text()).toContain(
      'no-such-model'
    )
  })

  it('shows a retry on the full list when the model list fails', async () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    getModels.mockRejectedValueOnce(new Error('network'))

    await mountPage()
    ;(wrapper!.vm as unknown as { activeTab: string }).activeTab = 'list'
    await wrapper!.vm.$nextTick()

    const listError = wrapper!.get('[data-testid="section-models-load-error-list"]')
    expect(listError.isVisible()).toBe(true)
    expect(listError.text()).toContain('The model list could not be loaded.')
    expect(wrapper!.find('[data-testid="section-models-empty"]').exists()).toBe(false)
    expect(wrapper!.find('table').exists()).toBe(false)

    getModels.mockResolvedValue({ success: true, models: { CHAT: [chatModel] }, providers: [] })
    await wrapper!.get('[data-testid="btn-retry-models-list"]').trigger('click')
    await flushPromises()

    expect(getModels).toHaveBeenCalledTimes(2)
    expect(wrapper!.find('[data-testid="section-models-load-error-list"]').exists()).toBe(false)
    expect(wrapper!.get('table').text()).toContain('Llama')
    errorSpy.mockRestore()
  })

  it('says the full list is still loading instead of saying there are no models', async () => {
    let resolveModels: (value: unknown) => void = () => {}
    getModels.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveModels = resolve
        })
    )

    wrapper = mount(AIModelsConfiguration, {
      global: {
        plugins: [createPinia()],
        stubs: {
          PageHeader: { template: '<div><slot /><slot name="actions" /></div>' },
          TabNav: { template: '<div />' },
          ServiceIcon: { template: '<span />' },
          ModelCostBadge: { template: '<span />' },
          EmbeddingSwitchModal: { template: '<div />' },
          EmbeddingRunsPanel: { template: '<div />' },
          AIModelsAdminPanel: { template: '<div />' },
          AddModelForm: { template: '<div />' },
          OpenAiCompatibleEndpointsPanel: { template: '<div />' },
          AccordionStack: { template: '<div><slot /></div>' },
          AccordionSection: { template: '<div><slot /></div>' },
          SectionJumpNav: { template: '<div />' },
        },
      },
    })
    ;(wrapper.vm as unknown as { activeTab: string }).activeTab = 'list'
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="section-models-list-loading"]').isVisible()).toBe(true)
    expect(wrapper.get('[data-testid="section-models-list-loading"]').text()).toContain(
      'Loading models...'
    )
    expect(wrapper.find('[data-testid="section-models-empty"]').exists()).toBe(false)
    expect(wrapper.find('table').exists()).toBe(false)

    resolveModels({ success: true, models: { CHAT: [chatModel] }, providers: [] })
    await flushPromises()

    expect(wrapper.get('table').text()).toContain('Llama')
  })

  it('keeps the model menu when only the saved choices fail to load', async () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    getDefaultModels.mockRejectedValueOnce(new Error('defaults'))

    await mountPage()

    expect(wrapper!.find('[data-testid="section-models-load-error"]').exists()).toBe(false)
    expect(wrapper!.find('[data-testid="btn-model-dropdown"]').exists()).toBe(true)
    errorSpy.mockRestore()
  })

  it('keeps a model choice that is still being saved when the picker refreshes', async () => {
    let resolveCheck: (value: unknown) => void = () => {}
    checkModelAvailability.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveCheck = resolve
        })
    )

    const pinia = createPinia()
    setActivePinia(pinia)
    wrapper = mount(AIModelsConfiguration, {
      global: {
        plugins: [pinia],
        stubs: {
          PageHeader: { template: '<div><slot /><slot name="actions" /></div>' },
          TabNav: {
            props: ['modelValue'],
            emits: ['update:modelValue'],
            template:
              '<div><button type="button" data-testid="stub-tab-list" @click="$emit(\'update:modelValue\', \'list\')">list</button><button type="button" data-testid="stub-tab-choice" @click="$emit(\'update:modelValue\', \'choice\')">choice</button></div>',
          },
          ServiceIcon: { template: '<span />' },
          ModelCostBadge: { template: '<span />' },
          EmbeddingSwitchModal: { template: '<div />' },
          EmbeddingRunsPanel: { template: '<div />' },
          AIModelsAdminPanel: { template: '<div />' },
          AddModelForm: { template: '<div />' },
          OpenAiCompatibleEndpointsPanel: { template: '<div />' },
          AccordionStack: { template: '<div><slot /></div>' },
          AccordionSection: { template: '<div><slot /></div>' },
          SectionJumpNav: { template: '<div />' },
        },
      },
    })
    await flushPromises()

    const row = () =>
      wrapper!
        .findAll('[data-testid="item-capability"]')
        .find((item) => item.text().includes('Chat / General AI'))!

    await row().get('[data-testid="btn-model-dropdown"]').trigger('click')
    const modelOption = row()
      .findAll('[data-testid="btn-model-option"]')
      .find((option) => option.text().includes('Llama'))
    expect(modelOption).toBeTruthy()
    await modelOption!.trigger('click')

    getDefaultModels.mockResolvedValue({
      success: true,
      defaults: { ...emptyDefaults, CHAT: 7 },
    })
    await wrapper!.get('[data-testid="stub-tab-list"]').trigger('click')
    await wrapper!.get('[data-testid="stub-tab-choice"]').trigger('click')
    await flushPromises()

    expect(row().text()).toContain('Llama')
    expect(saveDefaultModels).not.toHaveBeenCalled()

    resolveCheck({
      available: true,
      provider_type: 'external',
      model_name: 'Llama',
      service: 'groq',
    })
    await flushPromises()

    expect(saveDefaultModels).toHaveBeenCalledWith({
      defaults: expect.objectContaining({ CHAT: 42 }),
    })
    const saved = saveDefaultModels.mock.calls[0]?.[0] as { defaults: Record<string, number> }
    expect(saved.defaults.CHAT).toBe(42)
  })

  it('reloads the picker when an endpoint changes and when the choice tab is opened again', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = {
      id: 1,
      email: 'admin@example.com',
      level: 'ADMIN',
      isAdmin: true,
    }

    wrapper = mount(AIModelsConfiguration, {
      global: {
        plugins: [pinia],
        stubs: {
          PageHeader: { template: '<div><slot /><slot name="actions" /></div>' },
          TabNav: {
            props: ['modelValue'],
            emits: ['update:modelValue'],
            template:
              '<div><button type="button" data-testid="stub-tab-list" @click="$emit(\'update:modelValue\', \'list\')">list</button><button type="button" data-testid="stub-tab-choice" @click="$emit(\'update:modelValue\', \'choice\')">choice</button><button type="button" data-testid="stub-tab-edit" @click="$emit(\'update:modelValue\', \'edit\')">edit</button></div>',
          },
          ServiceIcon: { template: '<span />' },
          ModelCostBadge: { template: '<span />' },
          EmbeddingSwitchModal: { template: '<div />' },
          EmbeddingRunsPanel: { template: '<div />' },
          AIModelsAdminPanel: {
            emits: ['changed'],
            template:
              '<button type="button" data-testid="stub-catalog-changed" @click="$emit(\'changed\')">changed</button>',
          },
          AddModelForm: { template: '<div />' },
          OpenAiCompatibleEndpointsPanel: {
            emits: ['changed'],
            template:
              '<button type="button" data-testid="stub-endpoint-changed" @click="$emit(\'changed\')">changed</button>',
          },
          AccordionStack: { template: '<div><slot /></div>' },
          AccordionSection: { template: '<div><slot /></div>' },
          SectionJumpNav: { template: '<div />' },
        },
      },
    })
    await flushPromises()
    expect(getModels).toHaveBeenCalledTimes(1)

    await wrapper.get('[data-testid="stub-tab-edit"]').trigger('click')
    await flushPromises()
    expect(getModels).toHaveBeenCalledTimes(1)

    const qwen: AIModel = {
      ...chatModel,
      id: 381,
      service: 'OpenAICompatible',
      name: 'qwen3.6:27b',
      providerId: 'qwen3.6:27b',
    }
    getModels.mockResolvedValue({
      success: true,
      models: { CHAT: [qwen] },
      providers: [],
    })

    await wrapper.get('[data-testid="stub-endpoint-changed"]').trigger('click')
    await flushPromises()
    expect(getModels).toHaveBeenCalledTimes(2)

    await wrapper.get('[data-testid="stub-catalog-changed"]').trigger('click')
    await flushPromises()
    expect(getModels).toHaveBeenCalledTimes(3)

    await wrapper.get('[data-testid="stub-tab-choice"]').trigger('click')
    await flushPromises()
    expect(getModels).toHaveBeenCalledTimes(4)

    const row = wrapper
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Chat / General AI'))
    expect(row).toBeTruthy()
    await row!.get('[data-testid="btn-model-dropdown"]').trigger('click')
    expect(row!.text()).toContain('qwen3.6:27b')
    expect(wrapper.find('[data-testid="section-loading"]').exists()).toBe(false)
  })
})
