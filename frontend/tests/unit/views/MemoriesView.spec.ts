import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { UserMemory } from '@/services/api/userMemoriesApi'

const memories: UserMemory[] = [
  {
    id: 42,
    category: 'preferences',
    key: 'favorite_color',
    value: 'teal',
    source: 'user_created',
    messageId: null,
    created: 1,
    updated: 1,
  },
]
const init = vi.fn().mockResolvedValue(undefined)

vi.mock('@/stores/userMemories', () => ({
  useMemoriesStore: () => ({
    loading: false,
    error: null,
    memories,
    init,
    addMemory: vi.fn(),
    editMemory: vi.fn(),
    removeMemory: vi.fn(),
    fetchMemories: vi.fn(),
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { memoriesEnabled: true },
  }),
}))

vi.mock('@/services/api/userMemoriesApi', () => ({
  getCategories: vi.fn().mockResolvedValue([{ category: 'preferences', count: 1 }]),
}))

vi.mock('@/services/api', () => ({
  profileApi: { updateProfile: vi.fn() },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn() }),
}))

vi.mock('@/components/MemoryGraphView.vue', () => ({
  default: { template: '<div />' },
}))
vi.mock('@/components/MemoryGraph3DView.vue', () => ({
  default: { template: '<div />' },
}))
vi.mock('@/components/MemoryFormDialog.vue', () => ({
  default: { template: '<div />' },
}))

import MemoriesView from '@/views/MemoriesView.vue'

async function mountView(path = '/memories') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/memories', name: 'memories', component: { template: '<div />' } },
    ],
  })
  router.back = vi.fn()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(MemoriesView, {
    global: {
      plugins: [router],
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
        PageHeader: { template: '<div><slot name="actions" /></div>' },
        Teleport: true,
      },
    },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('MemoriesView', () => {
  beforeEach(() => {
    init.mockClear()
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: (query: string) => ({
        matches: false,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      }),
    })
  })

  it('marks the card from ?highlight=', async () => {
    const { wrapper } = await mountView('/memories?highlight=42')

    const highlighted = wrapper.findAll('[data-memory-id="42"][data-memory-highlighted="true"]')
    expect(highlighted.length).toBeGreaterThan(0)
  })

  it('shows Back to chat and calls router.back', async () => {
    Object.defineProperty(window.history, 'length', { configurable: true, get: () => 2 })
    const { wrapper, router } = await mountView('/memories?highlight=42')

    const back = wrapper.get('[data-testid="btn-memories-back"]')
    await back.trigger('click')
    expect(router.back).toHaveBeenCalledTimes(1)
  })
})
