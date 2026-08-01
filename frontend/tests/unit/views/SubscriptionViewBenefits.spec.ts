import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { i18n } from '@/i18n'

/**
 * `GET /api/v1/subscription/plans` returns English-only `features`. The plan
 * page used to print them verbatim, so a German user read English bullets
 * under German headings — visible on the very screen App Review looks at.
 * Both selling surfaces now translate through `planBenefits()`.
 */

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
  useRoute: () => ({ query: {} }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import SubscriptionView from '@/views/SubscriptionView.vue'

const SERVER_FEATURE = 'Advanced AI Models from the server'

function plan(id: string) {
  return {
    id,
    name: id,
    stripePriceId: `price_${id.toLowerCase()}`,
    price: 19.95,
    appPrice: 24.99,
    currency: 'EUR',
    interval: 'month',
    features: [SERVER_FEATURE],
  }
}

function mountView() {
  return mount(SubscriptionView, {
    global: { stubs: { MainLayout: { template: '<div><slot /></div>' } } },
  })
}

describe('SubscriptionView — plan benefits', () => {
  const previousLocale = i18n.global.locale.value
  // jsdom has no layout, so the view's scroll call would throw. Stubbing the
  // prototype leaks across files, hence the restore below.
  const previousScrollIntoView = Element.prototype.scrollIntoView

  beforeEach(() => {
    vi.clearAllMocks()
    Element.prototype.scrollIntoView = () => {}
  })

  afterEach(() => {
    i18n.global.locale.value = previousLocale
    Element.prototype.scrollIntoView = previousScrollIntoView
  })

  it('translates the benefits instead of printing the English server list', async () => {
    i18n.global.locale.value = 'de'
    mockGetPlans.mockResolvedValue({ plans: [plan('PRO')], stripeConfigured: true })

    const wrapper = mountView()
    await flushPromises()

    const card = wrapper.get('[data-plan-id="PRO"]')
    expect(card.text()).toContain('15× mehr Nutzung als im Free-Plan')
    expect(card.text()).toContain('Erweiterte KI-Modelle')
    expect(card.text()).not.toContain(SERVER_FEATURE)
    wrapper.unmount()
  })

  it('falls back to the server list for a tier this build has no copy for', async () => {
    mockGetPlans.mockResolvedValue({ plans: [plan('STUDIO')], stripeConfigured: true })

    const wrapper = mountView()
    await flushPromises()

    const card = wrapper.get('[data-plan-id="STUDIO"]')
    expect(card.text()).toContain(SERVER_FEATURE)
    // The heading must not degrade into the raw key path either.
    expect(card.text()).not.toContain('subscription.plans.studio')
    wrapper.unmount()
  })
})

/**
 * App Store Review Guideline 3.1.2 wants the renewal terms and working links to
 * the terms of use and the privacy policy on the screen that sells the
 * subscription — not only in the paywall modal.
 */
describe('SubscriptionView — purchase disclosure', () => {
  const previousScrollIntoView = Element.prototype.scrollIntoView

  beforeEach(() => {
    vi.clearAllMocks()
    Element.prototype.scrollIntoView = () => {}
  })

  afterEach(() => {
    Element.prototype.scrollIntoView = previousScrollIntoView
  })

  it('states the renewal terms and links to the terms and the privacy policy', async () => {
    mockGetPlans.mockResolvedValue({ plans: [plan('PRO')], stripeConfigured: true })

    const wrapper = mountView()
    await flushPromises()

    const disclosure = wrapper.get('[data-testid="section-purchase-disclosure"]')
    expect(disclosure.text()).toContain('renew')

    const hrefs = disclosure.findAll('a').map((a) => a.attributes('href'))
    expect(hrefs).toContain('https://example.test/terms')
    expect(hrefs).toContain('https://example.test/privacy')
    wrapper.unmount()
  })
})
