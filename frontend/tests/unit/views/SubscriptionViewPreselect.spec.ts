import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * The paywall hands the picked tier over as `?plan=<tier>` so a guest who signs
 * up first still lands on the plan they chose. The value comes straight from the
 * URL, so it is only honored when the server actually offers that tier — and it
 * never reaches a CSS selector, where a bracket or quote would throw and break
 * the page mount.
 */

let routeQuery: Record<string, unknown> = {}
const scrolledTo: (string | null)[] = []
const mockGetPlans = vi.fn()

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return { ...actual, isNativeApp: () => false }
})

vi.mock('@/services/api/nativeServer', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeServer')>()
  return { ...actual, isPurchaseAllowed: () => true }
})

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getPlans: (...args: unknown[]) => mockGetPlans(...args),
    getSubscriptionStatus: vi.fn().mockResolvedValue({ hasSubscription: false, plan: 'NEW' }),
    createCheckoutSession: vi.fn(),
    createPortalSession: vi.fn(),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { level: 'NEW' },
    refreshUser: vi.fn().mockResolvedValue(undefined),
  }),
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    billing: { enabled: true },
    // The view links to the legal pages (App Store Review Guideline 3.1.2);
    // the real store always resolves these, falling back to the brand pages.
    branding: {
      termsUrl: 'https://example.test/terms',
      privacyUrl: 'https://example.test/privacy',
    },
  }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ alert: vi.fn().mockResolvedValue(undefined) }),
}))

vi.mock('@/composables/useDateFormat', () => ({
  useDateFormat: () => ({ formatDateTime: () => '' }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useRoute: () => ({ query: routeQuery }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import SubscriptionView from '@/views/SubscriptionView.vue'

function plan(id: string) {
  return {
    id,
    name: id,
    stripePriceId: `price_${id.toLowerCase()}`,
    price: 19.95,
    appPrice: 24.99,
    currency: 'EUR',
    interval: 'month',
    features: ['a'],
  }
}

// Attached to the document because the scroll target is looked up there, just
// like in the running app.
function mountView() {
  return mount(SubscriptionView, {
    attachTo: document.body,
    global: { stubs: { MainLayout: { template: '<div><slot /></div>' } } },
  })
}

describe('SubscriptionView — plan preselected by the paywall', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    routeQuery = {}
    scrolledTo.length = 0
    Element.prototype.scrollIntoView = function (this: Element) {
      scrolledTo.push(this.getAttribute('data-plan-id'))
    }
    mockGetPlans.mockResolvedValue({
      plans: [plan('PRO'), plan('TEAM')],
      stripeConfigured: true,
    })
  })

  it('highlights and scrolls to the preselected tier, case-insensitively', async () => {
    routeQuery = { plan: 'pro' }

    const wrapper = mountView()
    await flushPromises()

    expect(scrolledTo).toEqual(['PRO'])
    expect(wrapper.find('[data-plan-id="PRO"]').classes()).toContain('ring-[var(--brand)]')
    wrapper.unmount()
  })

  it('ignores a tier the server does not offer, selector metacharacters included', async () => {
    routeQuery = { plan: 'pro"], [data-plan-id="TEAM' }

    const wrapper = mountView()
    await flushPromises()

    expect(scrolledTo).toEqual([])
    for (const card of wrapper.findAll('[data-testid="card-plan"]')) {
      expect(card.classes()).not.toContain('ring-[var(--brand)]')
    }
    wrapper.unmount()
  })
})
