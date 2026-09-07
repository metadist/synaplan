import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import AssistantPublishSection from '@/components/assistants/AssistantPublishSection.vue'
import { useAgentsStore } from '@/stores/agents'
import en from '@/i18n/en.json'

vi.mock('@/services/api/agentsApi', () => ({
  agentsApi: {
    versions: vi.fn().mockResolvedValue([]),
    usage: vi.fn().mockResolvedValue({ byVersion: [], byDay: [] }),
    publish: vi.fn(),
    update: vi.fn(),
    get: vi.fn(),
  },
  agentFieldPath: () => null,
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn().mockResolvedValue(false) }),
}))

describe('AssistantPublishSection', () => {
  it('renders Publish and Share', () => {
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
      draft: { schema: 'agent.v1' },
      createdAt: 1,
      updatedAt: 1,
    }
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
    const wrapper = mount(AssistantPublishSection, {
      global: {
        plugins: [i18n],
        stubs: { ShareDialog: true, AssistantUsagePanel: true },
      },
    })

    expect(wrapper.get('[data-testid="btn-publish-assistant"]').text()).toBe('Publish')
    const share = wrapper.get('[data-testid="btn-share-assistant"]')
    expect(share.text()).toBe('Share')
    expect((share.element as HTMLButtonElement).disabled).toBe(true)
  })
})
