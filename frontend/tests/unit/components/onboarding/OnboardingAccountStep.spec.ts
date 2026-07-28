import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

/**
 * MOBILE-APP SEAM (first-run onboarding), page 3 (auth-first): the account
 * step, shown either BEFORE the purchase (purchase context, neutral copy) or
 * after a signed-out restore re-delivered an unlinked purchase (redeem
 * context, success copy). Providers lead (Apple first on iOS — the native
 * sheet), e-mail registration and sign-in stay available, and a successful
 * in-place provider sign-in emits `authenticated`.
 */

const mockSignInWith = vi.fn()
const mockLoadProviders = vi.fn()
const mockGetNativePlatform = vi.fn(() => 'ios')

const providersRef = ref<Array<{ id: string; name: string; enabled: boolean; icon: string }>>([])
const errorRef = ref('')
const busyRef = ref(false)

vi.mock('@/composables/useSocialAuth', () => ({
  useSocialAuth: () => ({
    providers: providersRef,
    loadProviders: mockLoadProviders,
    signInWith: (...args: unknown[]) => mockSignInWith(...args),
    error: errorRef,
    busy: busyRef,
  }),
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  getNativePlatform: () => mockGetNativePlatform(),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import OnboardingAccountStep from '@/components/onboarding/OnboardingAccountStep.vue'

describe('OnboardingAccountStep', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    providersRef.value = [
      { id: 'google', name: 'Google', enabled: true, icon: 'google' },
      { id: 'apple', name: 'Apple', enabled: true, icon: 'apple' },
    ]
    errorRef.value = ''
    busyRef.value = false
    mockGetNativePlatform.mockReturnValue('ios')
  })

  it('loads the provider catalogue and renders provider + e-mail options', async () => {
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    expect(mockLoadProviders).toHaveBeenCalled()
    expect(wrapper.find('[data-testid="btn-account-apple"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-account-google"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-account-email"]').exists()).toBe(true)
  })

  it('puts Apple first on iOS (native sheet, Guideline 4.8)', async () => {
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    const providerButtons = wrapper.findAll('[data-testid^="btn-account-"]')
    expect(providerButtons[0].attributes('data-testid')).toBe('btn-account-apple')
  })

  it('keeps the server order on non-iOS platforms', async () => {
    mockGetNativePlatform.mockReturnValue('android')
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    const providerButtons = wrapper.findAll('[data-testid^="btn-account-"]')
    expect(providerButtons[0].attributes('data-testid')).toBe('btn-account-google')
  })

  it('emits authenticated after a successful in-place provider sign-in', async () => {
    mockSignInWith.mockResolvedValue(true)
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    await wrapper.find('[data-testid="btn-account-apple"]').trigger('click')
    await flushPromises()

    expect(mockSignInWith).toHaveBeenCalledWith('apple')
    expect(wrapper.emitted('authenticated')).toHaveLength(1)
  })

  it('does not leave the step on a cancelled / failed provider round trip', async () => {
    mockSignInWith.mockResolvedValue(false)
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    await wrapper.find('[data-testid="btn-account-google"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('authenticated')).toBeUndefined()
  })

  it('surfaces provider errors from the shared composable', async () => {
    errorRef.value = 'Login failed'
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    expect(wrapper.find('[data-testid="text-account-error"]').text()).toBe('Login failed')
  })

  it('shows neutral pre-purchase copy by default (nothing is paid yet)', async () => {
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    // Must NOT claim a successful payment before the store sheet ever ran.
    expect(wrapper.text()).not.toContain('Payment successful')
    expect(wrapper.text()).toContain('Almost there')
  })

  it('shows the payment-success copy in redeem context (unlinked purchase waiting)', async () => {
    const wrapper = mount(OnboardingAccountStep, { props: { context: 'redeem' } })
    await flushPromises()

    expect(wrapper.text()).toContain('Payment successful')
  })

  it('routes the e-mail path to register and the existing account to login', async () => {
    const wrapper = mount(OnboardingAccountStep)
    await flushPromises()

    await wrapper.find('[data-testid="btn-account-email"]').trigger('click')
    expect(wrapper.emitted('register')).toHaveLength(1)

    await wrapper.find('[data-testid="btn-account-login"]').trigger('click')
    expect(wrapper.emitted('login')).toHaveLength(1)
  })
})
