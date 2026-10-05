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
  useRoute: () => ({ query: {}, path: '/ai/models' }),
  useRouter: () => ({ replace: vi.fn() }),
}))

const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }))
vi.mock('@/composables/useNotification', () => ({ useNotification: () => notify }))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => ({ confirm: vi.fn() }) }))
vi.mock('@/services/api/adminEmbeddingApi', () => ({
  adminEmbeddingApi: { getStatus: vi.fn().mockResolvedValue({ guard: null }) },
}))
vi.mock('@/services/api/configApi', () => ({
  getModels,
  getDefaultModels,
  saveDefaultModels,
  checkModelAvailability,
  resetDefaultModels: vi.fn(),
}))

const gptOss: AIModel = {
  id: 385,
  service: 'Cerebras',
  name: 'GPT OSS 120B',
  tag: 'chat',
  providerId: 'gpt-oss-120b',
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

const stubs = {
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
}

describe('AIModelsConfiguration defaults scope', () => {
  let wrapper: VueWrapper | null = null

  beforeEach(() => {
    localStorage.clear()
    getModels.mockResolvedValue({ success: true, models: { CHAT: [gptOss] }, providers: [] })
    getDefaultModels.mockResolvedValue({ success: true, defaults: { ...emptyDefaults } })
    checkModelAvailability.mockResolvedValue({
      available: true,
      provider_type: 'external',
      model_name: 'GPT OSS 120B',
      service: 'Cerebras',
    })
    saveDefaultModels.mockResolvedValue({ success: true, message: 'saved' })
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.clearAllMocks()
  })

  const mountAs = async (level: 'ADMIN' | 'NEW') => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = {
      id: 1,
      email: 'person@example.com',
      level,
      isAdmin: level === 'ADMIN',
    }
    wrapper = mount(AIModelsConfiguration, { global: { plugins: [pinia], stubs } })
    await flushPromises()
    return wrapper
  }

  const pickChatModel = async () => {
    const row = wrapper!
      .findAll('[data-testid="item-capability"]')
      .find((item) => item.text().includes('Chat / General AI'))!
    await row.get('[data-testid="btn-model-dropdown"]').trigger('click')
    await row
      .findAll('[data-testid="btn-model-option"]')
      .find((option) => option.text().includes('GPT OSS 120B'))!
      .trigger('click')
    await flushPromises()
  }

  it('lets an admin set the model guests and members without a choice get', async () => {
    await mountAs('ADMIN')

    expect(getDefaultModels).toHaveBeenCalledWith('instance')
    expect(
      wrapper!.get('[data-testid="btn-defaults-scope-instance"]').attributes('aria-pressed')
    ).toBe('true')
    expect(wrapper!.get('[data-testid="text-defaults-scope-hint"]').text()).toContain('Guests')

    await pickChatModel()

    expect(saveDefaultModels).toHaveBeenCalledWith({ defaults: { CHAT: 385 }, global: true })
    expect(notify.success).toHaveBeenCalledWith(expect.stringContaining('Saved for everyone'))
  })

  it('saves only the admin’s own choice under "Just me" and remembers it', async () => {
    await mountAs('ADMIN')
    await wrapper!.get('[data-testid="btn-defaults-scope-user"]').trigger('click')
    await flushPromises()

    expect(getDefaultModels).toHaveBeenLastCalledWith('user')
    expect(localStorage.getItem('ai-models-defaults-scope')).toBe('user')

    await pickChatModel()
    expect(saveDefaultModels).toHaveBeenCalledWith({ defaults: { CHAT: 385 } })
  })

  it('saves where the model was picked even if the scope changes during the check', async () => {
    let resolveCheck: (value: unknown) => void = () => {}
    checkModelAvailability.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveCheck = resolve
        })
    )
    await mountAs('ADMIN')

    await pickChatModel()
    await wrapper!.get('[data-testid="btn-defaults-scope-user"]').trigger('click')
    await flushPromises()
    resolveCheck({
      available: true,
      provider_type: 'external',
      model_name: 'GPT OSS 120B',
      service: 'Cerebras',
    })
    await flushPromises()

    expect(saveDefaultModels).toHaveBeenCalledTimes(1)
    expect(saveDefaultModels).toHaveBeenCalledWith({ defaults: { CHAT: 385 }, global: true })
  })

  it('shows members no scope choice and keeps saving their own defaults', async () => {
    await mountAs('NEW')

    expect(wrapper!.find('[data-testid="section-defaults-scope"]').exists()).toBe(false)
    expect(getDefaultModels).toHaveBeenCalledWith('user')

    await pickChatModel()
    expect(saveDefaultModels).toHaveBeenCalledWith({ defaults: { CHAT: 385 } })
  })
})
