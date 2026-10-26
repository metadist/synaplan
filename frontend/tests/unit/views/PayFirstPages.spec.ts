import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { start, claim, replace, config } = vi.hoisted(() => ({
  start: vi.fn(),
  claim: vi.fn(),
  replace: vi.fn(),
  config: { payFirst: true },
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: { plan: 'PRO', session_id: 'cs_test_1' } }),
  useRouter: () => ({ replace }),
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ auth: { payFirst: config.payFirst } }),
  ApiError: class ApiError extends Error {},
}))

vi.mock('@/composables/useSignupChallenge', () => ({
  useSignupChallenge: () => ({ getToken: vi.fn().mockResolvedValue('challenge') }),
}))

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: { getPlans: vi.fn().mockResolvedValue({ plans: [] }) },
}))

vi.mock('@/services/api/checkoutApi', () => ({
  checkoutApi: { start, claim },
}))

import StartView from '@/views/StartView.vue'
import WelcomeView from '@/views/WelcomeView.vue'

describe('Start page', () => {
  beforeEach(() => {
    config.payFirst = true
    start.mockReset()
    replace.mockReset()
  })

  it('is absent when pay first is off', () => {
    config.payFirst = false
    mount(StartView)
    expect(replace).toHaveBeenCalledWith('/register')
  })

  it('opens the payment page for the chosen plan', async () => {
    start.mockResolvedValue({ success: true, checkoutUrl: 'https://checkout.stripe.test/s/1' })
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    const page = mount(StartView)
    await page.get('[data-testid="start-email"]').setValue('ada@example.com')
    await page.get('[data-testid="start-continue"]').trigger('submit')
    await flushPromises()

    expect(start).toHaveBeenCalledWith('ada@example.com', 'PRO', 'challenge')
    expect(assign).toHaveBeenCalledWith('https://checkout.stripe.test/s/1')
    vi.unstubAllGlobals()
  })
})

describe('Welcome page', () => {
  it('says the plan is active once the payment is confirmed', async () => {
    vi.useFakeTimers()
    claim.mockResolvedValueOnce({
      status: 'pending',
      plan: 'PRO',
      email: 'ada@example.com',
      signedIn: false,
      success: true,
    })
    claim.mockResolvedValueOnce({
      status: 'ready',
      plan: 'PRO',
      email: 'ada@example.com',
      signedIn: true,
      success: true,
    })
    const page = mount(WelcomeView)
    await flushPromises()
    expect(page.get('[data-testid="welcome-status"]').text()).toContain(
      'Setting up your Pro account'
    )

    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    expect(page.get('[data-testid="welcome-status"]').text()).toContain('Your Pro plan is active.')
    expect(page.find('[data-testid="welcome-to-chat"]').exists()).toBe(true)
    vi.useRealTimers()
  })
})
