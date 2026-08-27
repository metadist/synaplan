import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const config = {
  auth: { registrationEnabled: true },
  branding: { name: 'Synaplan', homepageUrl: 'https://synaplan.com', showPoweredBy: false },
  setup: { demoLoginHint: false },
  billing: { enabled: false },
  appBaseUrl: '',
}

vi.mock('@/stores/config', () => ({ useConfigStore: () => config }))
vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    isNativeApp: () => false,
    getNativePlatform: () => 'web',
  }
})
vi.mock('@/services/api/nativeServer', () => ({
  isNativeServerControlAvailable: () => false,
  openNativeServerOverlay: vi.fn(),
}))
vi.mock('@/services/api/nativeOAuth', () => ({ startNativeOAuth: vi.fn() }))
vi.mock('@/services/api/nativeAppleAuth', () => ({ startNativeAppleSignIn: vi.fn() }))
vi.mock('@/composables/useRecaptcha', () => ({
  useRecaptcha: () => ({ getToken: vi.fn() }),
}))
vi.mock('@/composables/useAuth', () => ({
  useAuth: () => ({
    login: vi.fn(),
    error: { value: '' },
    loading: { value: false },
    clearError: vi.fn(),
  }),
}))
vi.mock('@/composables/useTheme', () => ({
  useTheme: () => ({ theme: { value: 'light' }, setTheme: vi.fn() }),
}))
vi.mock('@/composables/useBrandLogo', () => ({
  useBrandLogo: () => ({ logoSrc: '', iconSrc: '' }),
}))

import LoginView from '@/views/LoginView.vue'

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/register', name: 'register', component: { template: '<div />' } },
    ],
  })
  await router.push('/login')
  await router.isReady()
  return mount(LoginView, {
    global: {
      plugins: [router],
      stubs: {
        Icon: true,
        DemoLoginHint: true,
        RegistrationClosedHint: {
          template: '<p data-testid="login-registration-closed">closed</p>',
        },
      },
    },
  })
}

describe('LoginView registration closed', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    config.auth.registrationEnabled = true
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ json: async () => ({ providers: [] }) }))
  })

  it('offers sign-up while self-registration is on', async () => {
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="link-signup"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="login-registration-closed"]').exists()).toBe(false)
  })

  it('replaces sign-up with the closed notice when registration is off', async () => {
    config.auth.registrationEnabled = false
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="link-signup"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="login-registration-closed"]').exists()).toBe(true)
  })
})
