import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const config = {
  auth: { registrationEnabled: true, registrationMode: 'open', signupPaused: false },
  branding: {
    name: 'Synaplan',
    homepageUrl: 'https://synaplan.com',
    termsUrl: 'https://synaplan.com/terms',
    privacyUrl: 'https://synaplan.com/privacy',
    showPoweredBy: false,
  },
  billing: { enabled: false },
  appBaseUrl: '',
}

const loadSocialProviders = vi.fn()
const loadAdmissionPlans = vi.fn(async () => undefined)

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
vi.mock('@/composables/useSignupChallenge', () => ({
  useSignupChallenge: () => ({ getToken: vi.fn(async () => ''), isEnabled: false, provider: null }),
}))
vi.mock('@/composables/useAuth', () => ({
  useAuth: () => ({
    register: vi.fn(),
    error: { value: '' },
    errorCode: { value: null },
    loading: { value: false },
    clearError: vi.fn(),
  }),
}))
vi.mock('@/composables/useSocialAuth', () => ({
  useSocialAuth: () => ({
    providers: { value: [] },
    loadProviders: loadSocialProviders,
    signInWith: vi.fn(),
    error: { value: '' },
  }),
}))
vi.mock('@/composables/useRegisterAdmission', () => ({
  useRegisterAdmission: () => ({
    admissionMode: { value: false },
    admissionChoice: { value: null },
    admissionPlans: { value: [] },
    showIdentity: { value: true },
    initialPlan: { value: null },
    admissionChoiceLabel: { value: '' },
    onAdmissionContinue: vi.fn(),
    loadAdmissionPlans,
  }),
}))
vi.mock('@/composables/useTheme', () => ({
  useTheme: () => ({ isDark: { value: false }, cycleTheme: vi.fn() }),
}))
vi.mock('@/composables/useBrandLogo', () => ({
  useBrandLogo: () => ({ logoSrc: '', iconSrc: '' }),
}))

import RegisterView from '@/views/RegisterView.vue'

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/register', name: 'register', component: { template: '<div />' } },
    ],
  })
  await router.push('/register')
  await router.isReady()
  return mount(RegisterView, {
    global: {
      plugins: [router],
      stubs: { Icon: true },
    },
  })
}

describe('RegisterView closed and paused states', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    config.auth.registrationEnabled = true
    config.auth.signupPaused = false
    loadSocialProviders.mockClear()
    loadAdmissionPlans.mockClear()
  })

  it('shows the registration form when self-sign-up is open', async () => {
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="form-register"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="register-closed"]').exists()).toBe(false)
    expect(loadSocialProviders).toHaveBeenCalledOnce()
  })

  it('shows the closed panel without fetching providers when registration is disabled', async () => {
    config.auth.registrationEnabled = false
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="register-closed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="form-register"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="register-closed"]').text()).toContain(
      'Registration is turned off.'
    )
    expect(loadSocialProviders).not.toHaveBeenCalled()
    expect(loadAdmissionPlans).not.toHaveBeenCalled()
  })

  it('shows the paused message when registration is enabled but paused', async () => {
    config.auth.registrationEnabled = true
    config.auth.signupPaused = true
    const wrapper = await mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="register-closed"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="form-register"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="register-closed"]').text()).toContain(
      'Sign-up is paused for a short while.'
    )
    expect(loadSocialProviders).not.toHaveBeenCalled()
  })
})
