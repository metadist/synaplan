import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import AssistantBuilder from '@/components/assistants/AssistantBuilder.vue'
import { useAgentsStore } from '@/stores/agents'
import en from '@/i18n/en.json'

vi.mock('@/services/api/agentsApi', () => ({
  agentsApi: {
    gallery: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    clone: vi.fn(),
    remove: vi.fn(),
    list: vi.fn(),
  },
  agentFieldPath: () => null,
}))

vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    getPrompt: vi.fn().mockResolvedValue({ prompt: 'Be helpful' }),
    updatePrompt: vi.fn(),
    getPromptFiles: vi.fn().mockResolvedValue([]),
    uploadPromptFile: vi.fn(),
  },
}))

vi.mock('@/stores/aiConfig', () => ({
  useAiConfigStore: () => ({
    models: {},
    loadModels: vi.fn(),
  }),
}))

vi.mock('@/services/api/chatApi', () => ({
  chatApi: { streamMessage: vi.fn() },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

function mountBuilder() {
  setActivePinia(createPinia())
  const store = useAgentsStore()
  store.current = {
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
    draft: { schema: 'agent.v1', models: { chat: null } },
    createdAt: 1,
    updatedAt: 1,
  }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(AssistantBuilder, {
    global: {
      plugins: [i18n],
      stubs: { Icon: true },
    },
  })
  return { wrapper, store }
}

describe('AssistantBuilder', () => {
  it('disables Save while clean', () => {
    const { wrapper, store } = mountBuilder()
    expect(store.dirty).toBe(false)
    expect(wrapper.get('[data-testid="btn-save-assistant"]').attributes('disabled')).toBeDefined()
  })

  it('shows a field-level validation error', async () => {
    const { wrapper, store } = mountBuilder()
    store.fieldErrors = { name: 'name must not be empty' }
    await wrapper.vm.$nextTick()
    expect(wrapper.get('[data-testid="error-name"]').text()).toContain('name must not be empty')
  })
})
