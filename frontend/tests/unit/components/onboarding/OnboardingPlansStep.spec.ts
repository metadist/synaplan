import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (first-run onboarding), step 3: the guest path must always
 * be available (never wall the app behind a purchase — Apple/Google policy and
 * onboarding best practice), and the plan cards come from the public plan
 * catalogue with a graceful fallback when billing is off or the load fails.
 */

const mockGetPlans = vi.fn()
const mockIsNativeApp = vi.fn(() => false)
const mockIsPurchaseAllowed = vi.fn(() => true)

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getPlans: (...args: unknown[]) => mockGetPlans(...args),
  },
}))

vi.mock('@/services/api/nativeServer', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeServer')>()
  return {
    ...actual,
    isPurchaseAllowed: () => mockIsPurchaseAllowed(),
  }
})

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    // Switchable per test; keep getNativeApiBaseUrl et al. intact (httpClient
    // → nativeAuth imports them at module load).
    isNativeApp: () => mockIsNativeApp(),
  }
})

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import OnboardingPlansStep from '@/components/onboarding/OnboardingPlansStep.vue'

const proPlan = {
  id: 'PRO',
  name: 'Pro',
  stripePriceId: 'price_pro',
  price: 19.95,
  appPrice: 25.99,
  currency: 'EUR',
  interval: 'month',
  features: ['Feature A', 'Feature B', 'Feature C', 'Feature D', 'Feature E'],
}

const teamPlan = {
  id: 'TEAM',
  name: 'Team',
  stripePriceId: 'price_team',
  price: 49.95,
  appPrice: 64.99,
  currency: 'EUR',
  interval: 'month',
  features: ['Team A', 'Team B'],
}

describe('OnboardingPlansStep', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGetPlans.mockResolvedValue({ plans: [proPlan, teamPlan], stripeConfigured: true })
    mockIsPurchaseAllowed.mockReturnValue(true)
  })

  it('always offers the guest path and emits "guest"', async () => {
    const wrapper = mount(OnboardingPlansStep)
    await flushPromises()

    const guestBtn = wrapper.find('[data-testid="btn-try-guest"]')
    expect(guestBtn.exists()).toBe(true)

    await guestBtn.trigger('click')
    expect(wrapper.emitted('guest')).toHaveLength(1)
  })

  it('shows a skeleton (not the price-free fallback) while the catalogue loads', async () => {
    // Pin the anti-flash behaviour: until the catalogue resolves we must show a
    // skeleton, never the register/guest fallback that would flash before plans.
    let resolvePlans: (value: unknown) => void = () => {}
    mockGetPlans.mockReturnValue(
      new Promise((resolve) => {
        resolvePlans = resolve
      })
    )

    const wrapper = mount(OnboardingPlansStep)
    await flushPromises()

    // Still loading: skeleton visible, fallback (register) and plans both hidden.
    expect(wrapper.find('[data-testid="section-plans-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-create-account"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(false)

    resolvePlans({ plans: [proPlan], stripeConfigured: true })
    await flushPromises()

    expect(wrapper.find('[data-testid="section-plans-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(true)
  })

  it('renders plans from the public catalogue and emits the pre-selected plan id via the CTA', async () => {
    const wrapper = mount(OnboardingPlansStep)
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('€19.95')

    // First plan is pre-selected (paywall best practice: a sensible default).
    expect(wrapper.find('[data-testid="btn-plan-pro"]').attributes('aria-pressed')).toBe('true')

    await wrapper.find('[data-testid="btn-plan-continue"]').trigger('click')
    expect(wrapper.emitted('select-plan')).toEqual([['PRO']])
  })

  it('shows the marked-up app price in the native shell (store commission on top)', async () => {
    // In the app the store's localized price wins once loaded; before that the
    // fallback is `appPrice` (web price + store commission) — NEVER the cheaper
    // web price (anti-steering).
    mockIsNativeApp.mockReturnValue(true)
    try {
      const wrapper = mount(OnboardingPlansStep)
      await flushPromises()

      expect(wrapper.text()).toContain('€25.99')
      expect(wrapper.text()).not.toContain('€19.95')
    } finally {
      mockIsNativeApp.mockReturnValue(false)
    }
  })

  it('shows the features of the selected plan (max 4) and swaps them on selection', async () => {
    const wrapper = mount(OnboardingPlansStep)
    await flushPromises()

    const features = () =>
      wrapper
        .find('[data-testid="section-plan-features"]')
        .findAll('li')
        .map((li) => li.text())
    expect(features()).toEqual(['Feature A', 'Feature B', 'Feature C', 'Feature D'])

    await wrapper.find('[data-testid="btn-plan-team"]').trigger('click')
    expect(features()).toEqual(['Team A', 'Team B'])

    await wrapper.find('[data-testid="btn-plan-continue"]').trigger('click')
    expect(wrapper.emitted('select-plan')).toEqual([['TEAM']])
  })

  it('never fetches or shows plans on a custom server in the native app', async () => {
    // Custom (self-hosted) server: no store purchase channel, so the step must
    // not even request the catalogue — only guest / sign-in / register remain.
    mockIsPurchaseAllowed.mockReturnValue(false)
    const wrapper = mount(OnboardingPlansStep)
    await flushPromises()

    expect(mockGetPlans).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-try-guest"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-create-account"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-login"]').exists()).toBe(true)
  })

  it('hides plans when the server has no purchase channel configured (billing off)', async () => {
    mockGetPlans.mockResolvedValue({
      plans: [proPlan],
      stripeConfigured: false,
      iapConfigured: false,
    })
    const wrapper = mount(OnboardingPlansStep)
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(false)
    // Guest / sign-in / register remain available.
    expect(wrapper.find('[data-testid="btn-try-guest"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="btn-login"]').exists()).toBe(true)
  })

  it('retries once after a failed catalogue load (cold-start resilience)', async () => {
    vi.useFakeTimers()
    try {
      mockGetPlans
        .mockRejectedValueOnce(new Error('network down'))
        .mockResolvedValueOnce({ plans: [proPlan], stripeConfigured: true })

      const wrapper = mount(OnboardingPlansStep)
      await flushPromises()
      expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(false)

      await vi.advanceTimersByTimeAsync(1500)
      await flushPromises()

      expect(mockGetPlans).toHaveBeenCalledTimes(2)
      expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('degrades gracefully when the plan catalogue keeps failing', async () => {
    vi.useFakeTimers()
    try {
      mockGetPlans.mockRejectedValue(new Error('network down'))
      const wrapper = mount(OnboardingPlansStep)
      await flushPromises()
      await vi.advanceTimersByTimeAsync(1500)
      await flushPromises()

      expect(mockGetPlans).toHaveBeenCalledTimes(2)
      expect(wrapper.find('[data-testid="btn-plan-pro"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="btn-try-guest"]').exists()).toBe(true)
    } finally {
      vi.useRealTimers()
    }
  })

  it('emits login and back from the footer actions', async () => {
    const wrapper = mount(OnboardingPlansStep)
    await flushPromises()

    await wrapper.find('[data-testid="btn-login"]').trigger('click')
    expect(wrapper.emitted('login')).toHaveLength(1)

    await wrapper.find('[data-testid="btn-plans-back"]').trigger('click')
    expect(wrapper.emitted('back')).toHaveLength(1)
  })
})
