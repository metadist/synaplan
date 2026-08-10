import { describe, it, expect, vi, afterAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (App Review 2.1 / 5.1.2(i), Play "prominent disclosure"):
 * Apple asked where users consent to their input being processed by third-party
 * AI providers. The answer is the first onboarding screen — tapping "get
 * started" is the affirmative act. Both stores accept fine print only while the
 * substance stays on screen, so this spec pins what may never quietly move into
 * the "details" modal: the sentence itself, in every locale, plus a working
 * privacy-policy link.
 */

vi.mock('@/services/api/nativeServer', () => ({
  isNativeServerControlAvailable: () => true,
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    branding: {
      name: 'Synaplan',
      privacyUrl: 'https://example.test/privacy',
    },
  }),
}))

vi.mock('@/composables/useBrandLogo', () => ({
  useBrandLogo: () => ({ logoSrc: { value: '/logo.svg' } }),
}))

vi.mock('@/composables/useTheme', () => ({
  useTheme: () => ({ theme: { value: 'light' } }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import OnboardingWelcomeStep from '@/components/onboarding/OnboardingWelcomeStep.vue'
import { i18n } from '@/i18n'

function mountStep() {
  return mount(OnboardingWelcomeStep, {
    global: {
      stubs: {
        OnboardingServerModal: true,
        OnboardingInfoModal: true,
      },
    },
  })
}

describe('OnboardingWelcomeStep — AI processing notice', () => {
  const previousLocale = i18n.global.locale.value

  afterAll(() => {
    i18n.global.locale.value = previousLocale
  })

  beforeEach(() => {
    i18n.global.locale.value = 'en'
  })

  it('states that input goes to AI providers before anything can be sent', () => {
    const notice = mountStep().find('[data-testid="section-onboarding-ai-notice"]')

    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('AI providers')
  })

  it('links to the privacy policy from the configured brand', () => {
    const link = mountStep().find('[data-testid="link-onboarding-privacy"]')

    expect(link.attributes('href')).toBe('https://example.test/privacy')
    expect(link.attributes('rel')).toContain('noopener')
  })

  it('carries the notice in every supported locale', () => {
    for (const locale of ['de', 'en', 'es', 'tr'] as const) {
      i18n.global.locale.value = locale

      const notice = mountStep().find('[data-testid="section-onboarding-ai-notice"]')

      // A missing key would render the key path itself instead of a sentence.
      expect(notice.text()).not.toContain('onboarding.welcome.aiNotice')
      expect(notice.text().length).toBeGreaterThan(80)
    }
  })

  it('keeps the disclosure on the page rather than behind the details modal', () => {
    const html = mountStep().html()

    // Both stores require the disclosure itself to be visible during normal
    // use. Only the elaboration may sit behind a tap, so the notice has to
    // render outside the modal — and after the CTA it qualifies.
    expect(html.indexOf('section-onboarding-ai-notice')).toBeGreaterThan(
      html.indexOf('btn-welcome-next')
    )
  })

  it('opens the processing details on demand', async () => {
    const step = mountStep()
    const modal = () => step.findComponent({ name: 'OnboardingInfoModal' })

    expect(modal().props('isOpen')).toBe(false)

    await step.find('[data-testid="btn-onboarding-ai-details"]').trigger('click')

    expect(modal().props('isOpen')).toBe(true)
    expect(modal().props('title')).toBe(i18n.global.t('onboarding.ai.title'))
    expect(modal().props('linkUrl')).toBe('https://example.test/privacy')
  })
})
