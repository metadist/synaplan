import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import AIModelsConfiguration from '@/components/config/AIModelsConfiguration.vue'
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

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
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

  it('keeps the model menu when only the saved choices fail to load', async () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    getDefaultModels.mockRejectedValueOnce(new Error('defaults'))

    await mountPage()

    expect(wrapper!.find('[data-testid="section-models-load-error"]').exists()).toBe(false)
    expect(wrapper!.find('[data-testid="btn-model-dropdown"]').exists()).toBe(true)
    errorSpy.mockRestore()
  })
})
