import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import BuilderModels from '@/components/assistants/BuilderModels.vue'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import en from '@/i18n/en.json'

vi.mock('@/stores/aiConfig', () => ({
  useAiConfigStore: () => ({
    models: {},
    loadModels: vi.fn(),
  }),
}))

function mountModels() {
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
    draft: emptyAgentDraft(),
    createdAt: 1,
    updatedAt: 1,
  }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(BuilderModels, { global: { plugins: [i18n] } })
  return { wrapper, store }
}

describe('BuilderModels advanced settings', () => {
  it('keeps advanced settings collapsed by default', () => {
    const { wrapper } = mountModels()
    const details = wrapper.get('[data-testid="details-models-advanced"]')
    expect((details.element as HTMLDetailsElement).open).toBe(false)
  })

  it('blocks invalid JSON schema', async () => {
    const { wrapper, store } = mountModels()
    await wrapper.get('[data-testid="input-response-schema"]').setValue('[1,2]')
    expect(wrapper.text()).toContain('JSON object')
    expect(store.current?.draft?.parameters.responseSchema).toBeNull()
  })

  it('saves a JSON object schema', async () => {
    const { wrapper, store } = mountModels()
    await wrapper.get('[data-testid="input-response-schema"]').setValue('{"type":"object"}')
    expect(store.current?.draft?.parameters.responseSchema).toEqual({ type: 'object' })
  })
})
