import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * The paywall is a store-facing purchase surface in the native shell, so this
 * spec pins the rules Apple/Google check: it is always dismissible (3.1.2),
 * "Restore Purchases" is reachable in the app (3.1.1), and a guest — who has no
 * account to attach an entitlement to — is routed through sign-up instead of
 * straight into a purchase.
 */

let nativeShell = false
const mockGetPlans = vi.fn()
const mockRouterPush = vi.fn()
const mockSetPendingRedirect = vi.fn()
const mockRestore = vi.fn().mockResolvedValue(true)
const mockAuth = {
  isAuthenticated: false,
  user: null as { level: string } | null,
  refreshUser: vi.fn().mockResolvedValue(undefined),
}

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return { ...actual, isNativeApp: () => nativeShell }
})

vi.mock('@/services/nativeIap', () => ({
  getStorePrice: () => null,
  initNativeIap: vi.fn().mockResolvedValue(false),
  isNativeIapAvailable: () => false,
  purchaseProduct: vi.fn(),
  restoreNativePurchases: () => mockRestore(),
}))

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getPlans: (...args: unknown[]) => mockGetPlans(...args),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => mockAuth,
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    branding: {
      termsUrl: 'https://example.test/terms',
      privacyUrl: 'https://example.test/privacy',
    },
  }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ alert: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/utils/pendingAuthRedirect', () => ({
  setPendingRedirect: (...args: unknown[]) => mockSetPendingRedirect(...args),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: mockRouterPush }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import SubscriptionPaywallModal from '@/components/subscription/SubscriptionPaywallModal.vue'

function plan(id: string, price: number) {
  return {
    id,
    name: id,
    stripePriceId: `price_${id.toLowerCase()}`,
    iapProductId: `com.synaplan.app.${id.toLowerCase()}.monthly`,
    price,
    appPrice: price + 5,
    currency: 'EUR',
    interval: 'month',
    features: ['from the server'],
  }
}

async function mountPaywall() {
  const wrapper = mount(SubscriptionPaywallModal, {
    props: { isOpen: true, reason: 'guest_limit' as const },
    global: { stubs: { teleport: true } },
  })
  await flushPromises()
  return wrapper
}

describe('SubscriptionPaywallModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    nativeShell = false
    mockAuth.isAuthenticated = false
    mockAuth.user = null
    mockGetPlans.mockResolvedValue({
      plans: [plan('PRO', 19.95), plan('TEAM', 49.95), plan('BUSINESS', 99.95)],
      stripeConfigured: true,
    })
  })

  it('renders one card per tier and recommends TEAM', async () => {
    const wrapper = await mountPaywall()

    const cards = wrapper.findAll('[data-testid="paywall-plan-card"]')
    expect(cards).toHaveLength(3)
    expect(cards.map((card) => card.attributes('data-plan-id'))).toEqual([
      'PRO',
      'TEAM',
      'BUSINESS',
    ])
    expect(wrapper.find('[data-testid="paywall-recommended"]').exists()).toBe(true)
  })

  it('shows localized benefits instead of the English server strings', async () => {
    const wrapper = await mountPaywall()

    const proCard = wrapper.find('[data-plan-id="PRO"]')
    expect(proCard.text()).toContain('Unlimited Messages')
    expect(proCard.text()).not.toContain('from the server')
  })

  it('is dismissible via the close button', async () => {
    const wrapper = await mountPaywall()

    await wrapper.find('[data-testid="paywall-close"]').trigger('click')

    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('sends a guest through sign-up, carrying the picked tier', async () => {
    const wrapper = await mountPaywall()

    await wrapper.find('[data-testid="paywall-select-team"]').trigger('click')
    await flushPromises()

    expect(mockSetPendingRedirect).toHaveBeenCalledWith('/subscription?plan=TEAM')
    expect(mockRouterPush).toHaveBeenCalledWith({
      path: '/register',
      query: { redirect: '/subscription?plan=TEAM' },
    })
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('only offers tiers above the current plan', async () => {
    mockAuth.isAuthenticated = true
    mockAuth.user = { level: 'PRO' }

    const wrapper = await mountPaywall()

    const offered = wrapper
      .findAll('[data-testid="paywall-plan-card"]')
      .map((card) => card.attributes('data-plan-id'))
    expect(offered).toEqual(['TEAM', 'BUSINESS'])
  })

  it('offers Restore Purchases and drops the backdrop in the native shell', async () => {
    nativeShell = true

    const wrapper = await mountPaywall()

    expect(wrapper.find('[data-testid="paywall-restore"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="paywall-backdrop"]').exists()).toBe(false)

    await wrapper.find('[data-testid="paywall-restore"]').trigger('click')
    expect(mockRestore).toHaveBeenCalled()
  })

  it('has no restore path on the web, where the backdrop dismisses it', async () => {
    const wrapper = await mountPaywall()

    expect(wrapper.find('[data-testid="paywall-restore"]').exists()).toBe(false)

    await wrapper.find('[data-testid="paywall-backdrop"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })
})
