import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import ImpersonationBanner from '@/components/ImpersonationBanner.vue'
import { useAuthStore } from '@/stores/auth'

const successMock = vi.fn()
const errorMock = vi.fn()
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: successMock,
    error: errorMock,
    warning: vi.fn(),
    info: vi.fn(),
  }),
}))

const stopImpersonationMock = vi.fn()
vi.mock('@/services/api/impersonationApi', () => ({
  impersonationApi: {
    start: vi.fn(),
    stop: (...args: unknown[]) => stopImpersonationMock(...args),
  },
}))

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://localhost:8000',
  refreshAccessToken: vi.fn().mockResolvedValue(true),
}))

vi.mock('@/services/authService', async () => {
  const { ref } = await import('vue')
  const userRef = ref<unknown>(null)
  const impersonatorRef = ref<unknown>(null)
  return {
    authService: {
      getUser: () => userRef,
      getImpersonator: () => impersonatorRef,
      getCurrentUser: vi.fn().mockResolvedValue(null),
      logout: vi.fn(),
    },
  }
})

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    reload: vi.fn().mockResolvedValue(undefined),
    billing: { enabled: false },
  }),
}))

// auth.ts dynamically imports chats + history stores on every principal
// swap (#999) to wipe user-scoped client state. The full modules pull in a
// lot of unrelated utilities; this banner spec only cares that the reset is
// invoked and does not throw, so we stub them out to keep the test fast and
// hermetic.
vi.mock('@/stores/chats', () => ({
  useChatsStore: () => ({ $reset: vi.fn() }),
}))
vi.mock('@/stores/history', () => ({
  useHistoryStore: () => ({ clear: vi.fn() }),
}))
vi.mock('@/services/api/chatApi', () => ({
  clearSseToken: vi.fn(),
}))

const buildRouter = () =>
  createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/admin', name: 'admin', component: { template: '<div />' } },
    ],
  })

// i18n is provided globally via `tests/unit/setup.ts` (which registers the
// real `@/i18n` plugin on `config.global.plugins`). All other specs in this
// suite rely on the same global setup — building a local stub here would
// duplicate that wiring and risk drifting from the real translation keys.
const mountBanner = async () => {
  const router = buildRouter()
  await router.push('/')
  await router.isReady()

  return mount(ImpersonationBanner, {
    global: {
      plugins: [router],
      stubs: { Icon: true },
    },
  })
}

describe('ImpersonationBanner', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    successMock.mockClear()
    errorMock.mockClear()
    stopImpersonationMock.mockReset()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('does not render when no impersonation is active', async () => {
    const wrapper = await mountBanner()
    expect(wrapper.find('[data-testid="banner-impersonation"]').exists()).toBe(false)
  })

  it('renders the impersonated user and the original admin when active', async () => {
    const store = useAuthStore()
    store.user = {
      id: 99,
      email: 'normal-user@example.com',
      level: 'PRO',
    }
    store.impersonator = {
      id: 1,
      email: 'admin@example.com',
      level: 'ADMIN',
    }

    const wrapper = await mountBanner()

    expect(wrapper.find('[data-testid="banner-impersonation"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="banner-impersonation-target"]').text()).toContain(
      'normal-user@example.com'
    )
    expect(wrapper.find('[data-testid="banner-impersonation-admin"]').text()).toContain(
      'admin@example.com'
    )
  })

  it('clicking Exit calls stopImpersonation and notifies on success', async () => {
    const store = useAuthStore()
    store.user = { id: 99, email: 'normal-user@example.com', level: 'PRO' }
    store.impersonator = { id: 1, email: 'admin@example.com', level: 'ADMIN' }

    stopImpersonationMock.mockResolvedValueOnce({
      success: true,
      data: { success: true, user: { id: 1, email: 'admin@example.com', level: 'ADMIN' } },
      status: 200,
    })

    const wrapper = await mountBanner()
    await wrapper.find('[data-testid="btn-impersonation-exit"]').trigger('click')
    // Allow the awaited stop + resetUserScopedClientState (dynamic imports of
    // chats/history) + refreshUser() chain to flush before asserting.
    await flushPromises()

    expect(stopImpersonationMock).toHaveBeenCalledTimes(1)
    expect(successMock).toHaveBeenCalledWith('Admin session restored.')
  })

  it('shows the server-supplied error when Exit fails', async () => {
    const store = useAuthStore()
    store.user = { id: 99, email: 'normal-user@example.com', level: 'PRO' }
    store.impersonator = { id: 1, email: 'admin@example.com', level: 'ADMIN' }

    stopImpersonationMock.mockResolvedValueOnce({
      success: false,
      error: 'Impersonation session expired. Please sign in again.',
      status: 403,
    })

    const wrapper = await mountBanner()
    await wrapper.find('[data-testid="btn-impersonation-exit"]').trigger('click')
    await flushPromises()

    expect(errorMock).toHaveBeenCalledWith('Impersonation session expired. Please sign in again.')
    expect(successMock).not.toHaveBeenCalled()
  })
})
