import { describe, it, expect, vi, beforeEach, afterAll } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (App Review 2.3.10): the store note below the restore button
 * must name only the store the running build actually sells through. Apple
 * rejected 4.0.0 because the iOS binary mentioned Google Play, so this pins the
 * platform split in both directions.
 */

const mockPlatform = vi.fn<() => 'ios' | 'android' | 'web'>(() => 'ios')

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    isNativeApp: () => true,
    getNativePlatform: () => mockPlatform(),
  }
})

vi.mock('@/services/api/nativeServer', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeServer')>()
  return {
    ...actual,
    isPurchaseAllowed: () => true,
  }
})

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getPlans: vi.fn().mockResolvedValue({
      plans: [
        {
          id: 'PRO',
          name: 'Pro',
          stripePriceId: 'price_pro',
          price: 19.95,
          appPrice: 24.99,
          currency: 'EUR',
          interval: 'month',
          features: ['a'],
        },
      ],
      stripeConfigured: true,
    }),
    getSubscriptionStatus: vi.fn().mockResolvedValue({ hasSubscription: false, plan: 'NEW' }),
    createCheckoutSession: vi.fn(),
    createPortalSession: vi.fn(),
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { level: 'NEW' } }),
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
import { i18n } from '@/i18n'

async function mountAndReadStoreNote() {
  const wrapper = mount(SubscriptionView, {
    global: {
      stubs: {
        MainLayout: { template: '<div><slot /></div>' },
      },
    },
  })
  await flushPromises()
  return wrapper.find('[data-testid="section-native-store"]').text()
}

describe('SubscriptionView — store note names one store only (App Review 2.3.10)', () => {
  const previousLocale = i18n.global.locale.value

  afterAll(() => {
    i18n.global.locale.value = previousLocale
  })

  beforeEach(() => {
    // Pin the locale: the assertion is about the store name, which is identical
    // in every translation, but a stray locale would still make it brittle.
    i18n.global.locale.value = 'en'
    mockPlatform.mockReturnValue('ios')
  })

  it('mentions the App Store and never Google Play on iOS', async () => {
    const note = await mountAndReadStoreNote()

    expect(note).toContain('App Store')
    expect(note).not.toContain('Google Play')
  })

  it('mentions Google Play and never the App Store on Android', async () => {
    mockPlatform.mockReturnValue('android')

    const note = await mountAndReadStoreNote()

    expect(note).toContain('Google Play')
    expect(note).not.toContain('App Store')
  })

  it('names the right store and only that one, in every locale', async () => {
    // A translator could reintroduce the rejected wording in a single language,
    // so every locale is checked on both platforms, not just the English one.
    const expectations = {
      ios: { present: 'App Store', absent: 'Google Play' },
      android: { present: 'Google Play', absent: 'App Store' },
    } as const

    for (const platform of ['ios', 'android'] as const) {
      mockPlatform.mockReturnValue(platform)

      for (const locale of ['de', 'en', 'es', 'fr', 'tr'] as const) {
        i18n.global.locale.value = locale

        const note = await mountAndReadStoreNote()

        expect(note, `${platform}/${locale}`).toContain(expectations[platform].present)
        expect(note, `${platform}/${locale}`).not.toContain(expectations[platform].absent)
      }
    }
  })
})
