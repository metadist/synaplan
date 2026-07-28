import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (auth-first onboarding): the subscription page continues a
 * purchase intent persisted by the onboarding once the user is signed in
 * (e-mail register / login round trip, WebView re-creation, restart). The
 * contract pinned here: the freshly loaded subscription status acts as the
 * pre-check — an account that already has an active subscription is never
 * charged again — and the intent is settled (cleared) either way.
 */

const mockGetPlans = vi.fn()
const mockGetSubscriptionStatus = vi.fn()
const mockPurchaseProduct = vi.fn()
const mockInfo = vi.fn()
const mockRouterPush = vi.fn()

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    isNativeApp: () => true,
  }
})

vi.mock('@/services/api/nativeServer', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeServer')>()
  return {
    ...actual,
    isPurchaseAllowed: () => true,
  }
})

vi.mock('@/services/nativeIap', () => ({
  getStorePrice: vi.fn(() => null),
  initNativeIap: vi.fn(async () => true),
  isNativeIapAvailable: () => true,
  purchaseProduct: (...args: unknown[]) => mockPurchaseProduct(...args),
  restoreNativePurchases: vi.fn(),
}))

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getPlans: (...args: unknown[]) => mockGetPlans(...args),
    getSubscriptionStatus: () => mockGetSubscriptionStatus(),
    createCheckoutSession: vi.fn(),
    createPortalSession: vi.fn(),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { level: 'NEW' }, refreshUser: vi.fn() }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ billing: { enabled: true } }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ alert: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), info: mockInfo }),
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
import { setPurchaseIntent, peekPurchaseIntent } from '@/services/iapPurchaseIntent'

const PRODUCT_ID = 'com.synaplan.app.business.monthly'

function mountView() {
  return mount(SubscriptionView, {
    global: {
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
      },
    },
  })
}

describe('SubscriptionView — persisted purchase intent (auth-first onboarding)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    mockGetPlans.mockResolvedValue({
      plans: [
        {
          id: 'BUSINESS',
          name: 'Business',
          stripePriceId: 'price_business',
          price: 99.95,
          appPrice: 129.99,
          currency: 'EUR',
          interval: 'month',
          features: ['a'],
          iapProductId: PRODUCT_ID,
        },
      ],
      stripeConfigured: true,
      iapConfigured: true,
    })
    mockGetSubscriptionStatus.mockResolvedValue({ hasSubscription: false, plan: 'NEW' })
    mockPurchaseProduct.mockResolvedValue({ status: 'cancelled' })
  })

  it('continues the onboarding purchase automatically after sign-in', async () => {
    setPurchaseIntent({ planId: 'BUSINESS', productId: PRODUCT_ID })

    mountView()
    await flushPromises()

    expect(mockPurchaseProduct).toHaveBeenCalledWith(PRODUCT_ID)
    // Settled — a later visit must never re-open the store sheet.
    expect(peekPurchaseIntent()).toBeNull()
  })

  it('never charges an account that already has an active subscription', async () => {
    setPurchaseIntent({ planId: 'BUSINESS', productId: PRODUCT_ID })
    mockGetSubscriptionStatus.mockResolvedValue({
      hasSubscription: true,
      active: true,
      plan: 'BUSINESS',
      tier: 'BUSINESS',
    })

    mountView()
    await flushPromises()

    // The pre-check catches the conflict BEFORE any store sheet.
    expect(mockPurchaseProduct).not.toHaveBeenCalled()
    expect(mockInfo).toHaveBeenCalled()
    expect(peekPurchaseIntent()).toBeNull()
  })

  it('does not purchase blind when the subscription status could not be loaded', async () => {
    setPurchaseIntent({ planId: 'BUSINESS', productId: PRODUCT_ID })
    mockGetSubscriptionStatus.mockRejectedValue(new Error('network down'))

    mountView()
    await flushPromises()

    expect(mockPurchaseProduct).not.toHaveBeenCalled()
  })

  it('is inert without a pending intent', async () => {
    mountView()
    await flushPromises()

    expect(mockPurchaseProduct).not.toHaveBeenCalled()
    expect(mockInfo).not.toHaveBeenCalled()
  })
})
