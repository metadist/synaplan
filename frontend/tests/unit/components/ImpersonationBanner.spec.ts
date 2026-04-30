import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
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
    // Allow the awaited stop + refreshUser() chain to flush.
    await new Promise((resolve) => setTimeout(resolve, 0))

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
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(errorMock).toHaveBeenCalledWith('Impersonation session expired. Please sign in again.')
    expect(successMock).not.toHaveBeenCalled()
  })
})
