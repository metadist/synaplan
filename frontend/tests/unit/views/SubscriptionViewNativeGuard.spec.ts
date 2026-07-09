import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (Epic 5.2): the native shell must NEVER open the Stripe web
 * checkout (Apple 3.1.1 / Google Play). This test pins that guard: inside the
 * app, selecting a plan delegates to the native IAP path (a dialog for now) and
 * the Stripe `createCheckoutSession` call is never reached.
 */

const mockCreateCheckoutSession = vi.fn()
const mockCreatePortalSession = vi.fn()
const mockAlert = vi.fn().mockResolvedValue(undefined)
const mockRouterPush = vi.fn()
const mockIsPurchaseAllowed = vi.fn(() => true)
const mockGetPlans = vi.fn()
// Vitest runs with import.meta.env.DEV = true, so the REAL isNonProdBuild()
// would always be true here and silently reroute every purchase through the
// dev checkout fallback. Default to `false` so these tests pin the PROD
// (store-only) behaviour; the dev-fallback test flips it explicitly.
const mockIsNonProdBuild = vi.fn(() => false)

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    // Force the native shell for this test; keep getNativeApiBaseUrl et al. intact
    // (httpClient → nativeAuth imports them at module load).
    isNativeApp: () => true,
    isNonProdBuild: () => mockIsNonProdBuild(),
  }
})

vi.mock('@/services/api/nativeServer', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeServer')>()
  return {
    ...actual,
    isPurchaseAllowed: () => mockIsPurchaseAllowed(),
  }
})

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getPlans: (...args: unknown[]) => mockGetPlans(...args),
    getSubscriptionStatus: vi.fn().mockResolvedValue({ hasSubscription: false, plan: 'NEW' }),
    createCheckoutSession: (...args: unknown[]) => mockCreateCheckoutSession(...args),
    createPortalSession: (...args: unknown[]) => mockCreatePortalSession(...args),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { level: 'NEW' } }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ billing: { enabled: true } }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ alert: mockAlert }),
}))

vi.mock('@/composables/useDateFormat', () => ({
  useDateFormat: () => ({ formatDateTime: () => '' }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: mockRouterPush }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import SubscriptionView from '@/views/SubscriptionView.vue'

function mountView() {
  return mount(SubscriptionView, {
    global: {
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
      },
    },
  })
}

describe('SubscriptionView — native purchase guard (Epic 5.2)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockIsPurchaseAllowed.mockReturnValue(true)
    mockIsNonProdBuild.mockReturnValue(false)
    mockGetPlans.mockResolvedValue({
      plans: [
        {
          id: 'PRO',
          name: 'Pro',
          stripePriceId: 'price_pro',
          price: 19.95,
          appPrice: 25.99,
          currency: 'EUR',
          interval: 'month',
          features: ['a'],
        },
      ],
      stripeConfigured: true,
    })
  })

  it('does NOT open the Stripe checkout when running in the native shell', async () => {
    const wrapper = mountView()
    await flushPromises()

    const selectBtn = wrapper.find('[data-testid="btn-select-pro"]')
    expect(selectBtn.exists()).toBe(true)

    await selectBtn.trigger('click')
    await flushPromises()

    // The web checkout must never be reached in the app.
    expect(mockCreateCheckoutSession).not.toHaveBeenCalled()
    // Instead the native IAP path informs the user (Epic 5.3 wires the real plugin).
    expect(mockAlert).toHaveBeenCalledTimes(1)
  })

  it('falls back to the Stripe checkout in a non-prod build when the store cannot sell the product', async () => {
    // Local development (e.g. `cap run` without Xcode's StoreKit catalogue):
    // no CdvPurchase global → the store product is never ready → the purchase
    // must route through the server's (test) checkout instead of erroring.
    mockIsNonProdBuild.mockReturnValue(true)
    mockCreateCheckoutSession.mockResolvedValue({ sessionId: 's', url: 'https://stripe.test/c' })
    const windowOpen = vi.spyOn(window, 'open').mockReturnValue(null)

    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('[data-testid="btn-select-pro"]').trigger('click')
    await flushPromises()

    expect(mockCreateCheckoutSession).toHaveBeenCalledWith('PRO')
    // Opened externally — the WebView must not navigate away.
    expect(windowOpen).toHaveBeenCalledWith('https://stripe.test/c', '_blank')
    expect(mockAlert).not.toHaveBeenCalled()

    windowOpen.mockRestore()
  })

  it('redirects home and never loads plans on a custom server in the app', async () => {
    // Custom (self-hosted) server: no store purchase channel, so the whole
    // subscription page (prices, checkout) must be unreachable.
    mockIsPurchaseAllowed.mockReturnValue(false)

    mountView()
    await flushPromises()

    expect(mockRouterPush).toHaveBeenCalledWith('/')
    expect(mockGetPlans).not.toHaveBeenCalled()
    expect(mockCreateCheckoutSession).not.toHaveBeenCalled()
  })
})
