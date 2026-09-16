import { describe, expect, it, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import AssistantGallery from '@/components/assistants/AssistantGallery.vue'
import { useAgentsStore } from '@/stores/agents'
import type { GalleryCard } from '@/services/api/agentsApi'
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

const card: GalleryCard = {
  id: 7,
  slug: 'contract-review',
  name: 'Contract review',
  description: 'Reviews NDAs',
  icon: '',
  status: 'draft',
  origin: 'mine',
  ownerName: 'Ada',
  version: null,
  updatedAt: 1,
  starterPrompts: ['Review this NDA'],
}

function mountGallery() {
  setActivePinia(createPinia())
  const store = useAgentsStore()
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(AssistantGallery, {
    global: {
      plugins: [i18n],
      stubs: { Icon: true },
    },
  })
  return { wrapper, store }
}

describe('AssistantGallery', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('shows the empty state and Create assistant', () => {
    const { wrapper } = mountGallery()

    expect(wrapper.get('[data-testid="state-gallery-empty"]').text()).toContain(
      'You have not created an assistant yet.'
    )
    expect(wrapper.get('[data-testid="btn-create-assistant"]').text()).toBe('Create assistant')
  })

  it('emits start-chat with the card id', async () => {
    const { wrapper, store } = mountGallery()
    store.gallery = [card]
    await wrapper.vm.$nextTick()

    await wrapper.get('[data-testid="btn-assistant-start-chat"]').trigger('click')
    expect(wrapper.emitted('start-chat')).toEqual([[7]])
  })

  it('emits delete for an assistant you own', async () => {
    const { wrapper, store } = mountGallery()
    store.gallery = [card]
    await wrapper.vm.$nextTick()

    await wrapper.get('[data-testid="btn-assistant-delete"]').trigger('click')
    expect(wrapper.emitted('delete')).toEqual([[7]])
  })

  it('hides archived cards on Mine and shows them on Archived', async () => {
    const { wrapper, store } = mountGallery()
    store.gallery = [{ ...card, status: 'archived' }]
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="list-assistant-cards"]').exists()).toBe(false)

    await wrapper.get('[data-testid="chip-gallery-archived"]').trigger('click')
    expect(wrapper.get('[data-testid="badge-card-archived"]').text()).toContain('Archived')
  })
})
