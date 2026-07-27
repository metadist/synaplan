import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

/**
 * MOBILE-APP SEAM (first-run onboarding): orchestration of the two-page native
 * first-run flow. The step components are stubbed (per AGENTS_DEV: stub heavy
 * deps) — this spec pins the page wiring, the skip/finish paths (which must
 * persist completion so the flow never re-appears), and the plan-selection
 * handoff into register → subscription (IAP purchase path).
 */

const mockReplace = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
}))

import OnboardingView from '@/views/OnboardingView.vue'
import { isOnboardingCompleted, setOnboardingResumeStep } from '@/composables/useOnboarding'
import { peekPendingRedirect } from '@/utils/pendingAuthRedirect'

const stubs = {
  OnboardingWelcomeStep: {
    template:
      '<div data-testid="stub-welcome"><button data-testid="stub-next" @click="$emit(\'next\')" /></div>',
    emits: ['next'],
  },
  OnboardingPlansStep: {
    template:
      '<div data-testid="stub-plans">' +
      '<button data-testid="stub-guest" @click="$emit(\'guest\')" />' +
      '<button data-testid="stub-select" @click="$emit(\'select-plan\', \'PRO\')" />' +
      '<button data-testid="stub-login" @click="$emit(\'login\')" />' +
      '<button data-testid="stub-purchased-unlinked" @click="$emit(\'purchased-unlinked\')" />' +
      '<button data-testid="stub-purchased" @click="$emit(\'purchased\')" />' +
      '</div>',
    emits: ['back', 'guest', 'login', 'register', 'select-plan', 'purchased-unlinked', 'purchased'],
  },
  OnboardingAccountStep: {
    template:
      '<div data-testid="stub-account">' +
      '<button data-testid="stub-authenticated" @click="$emit(\'authenticated\')" />' +
      '<button data-testid="stub-account-register" @click="$emit(\'register\')" />' +
      '</div>',
    emits: ['authenticated', 'register', 'login'],
  },
}

function mountView() {
  return mount(OnboardingView, { global: { stubs } })
}

async function advanceToPlans(wrapper: ReturnType<typeof mountView>) {
  await wrapper.find('[data-testid="stub-next"]').trigger('click')
  await nextTick()
  await nextTick()
}

describe('OnboardingView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    sessionStorage.clear()
  })

  it('starts at the welcome page and walks forward to the plans page', async () => {
    const wrapper = mountView()
    expect(wrapper.find('[data-testid="stub-welcome"]').exists()).toBe(true)

    await advanceToPlans(wrapper)
    expect(wrapper.find('[data-testid="stub-plans"]').exists()).toBe(true)
  })

  it('resumes at the remembered page after the server-switch reload', () => {
    setOnboardingResumeStep(2)
    const wrapper = mountView()
    expect(wrapper.find('[data-testid="stub-plans"]').exists()).toBe(true)
  })

  it('skip persists completion and leaves for the chat entry', async () => {
    const wrapper = mountView()
    await wrapper.find('[data-testid="btn-skip-onboarding"]').trigger('click')

    expect(isOnboardingCompleted()).toBe(true)
    expect(mockReplace).toHaveBeenCalledWith('/')
  })

  it('the guest CTA persists completion and enters the guest chat', async () => {
    const wrapper = mountView()
    await advanceToPlans(wrapper)

    await wrapper.find('[data-testid="stub-guest"]').trigger('click')

    expect(isOnboardingCompleted()).toBe(true)
    expect(mockReplace).toHaveBeenCalledWith('/')
  })

  it('selecting a plan routes to register with a pending /subscription redirect', async () => {
    const wrapper = mountView()
    await advanceToPlans(wrapper)

    await wrapper.find('[data-testid="stub-select"]').trigger('click')

    expect(isOnboardingCompleted()).toBe(true)
    // The purchase completes post-login on the subscription page (native IAP
    // path) — the intent survives the register → login round trip.
    expect(peekPendingRedirect()).toBe('/subscription')
    expect(mockReplace).toHaveBeenCalledWith({
      name: 'register',
      query: { redirect: '/subscription' },
    })
  })

  it('"sign in" persists completion and goes to the login page', async () => {
    const wrapper = mountView()
    await advanceToPlans(wrapper)

    await wrapper.find('[data-testid="stub-login"]').trigger('click')

    expect(isOnboardingCompleted()).toBe(true)
    expect(mockReplace).toHaveBeenCalledWith({ name: 'login' })
  })

  it('renders one progress dot per page with the active dot marked', async () => {
    const wrapper = mountView()
    const dots = wrapper.findAll('[data-testid="section-progress"] button')
    expect(dots).toHaveLength(2)
    expect(dots[0].classes()).toContain('onboarding-dot--active')

    await advanceToPlans(wrapper)
    const dotsAfter = wrapper.findAll('[data-testid="section-progress"] button')
    expect(dotsAfter[1].classes()).toContain('onboarding-dot--active')
  })

  describe('purchase-first flow (account step)', () => {
    async function advanceToAccount(wrapper: ReturnType<typeof mountView>) {
      await advanceToPlans(wrapper)
      await wrapper.find('[data-testid="stub-purchased-unlinked"]').trigger('click')
      await nextTick()
      await nextTick()
    }

    it('a signed-out purchase advances to the account step and persists completion', async () => {
      const wrapper = mountView()
      await advanceToAccount(wrapper)

      expect(wrapper.find('[data-testid="stub-account"]').exists()).toBe(true)
      // Completion is persisted NOW: backgrounding the app must lead to the
      // guest-chat reminder banner, never a second onboarding run.
      expect(isOnboardingCompleted()).toBe(true)
    })

    it('the account step is terminal: no skip, no dot navigation back to the paywall', async () => {
      const wrapper = mountView()
      await advanceToAccount(wrapper)

      expect(wrapper.find('[data-testid="btn-skip-onboarding"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="section-progress"]').exists()).toBe(false)
    })

    it('an in-place sign-in on the account step enters the app', async () => {
      const wrapper = mountView()
      await advanceToAccount(wrapper)

      await wrapper.find('[data-testid="stub-authenticated"]').trigger('click')
      expect(mockReplace).toHaveBeenCalledWith('/')
    })

    it('the e-mail path on the account step routes to plain register (redemption is post-login)', async () => {
      const wrapper = mountView()
      await advanceToAccount(wrapper)

      await wrapper.find('[data-testid="stub-account-register"]').trigger('click')
      expect(mockReplace).toHaveBeenCalledWith({ name: 'register' })
    })

    it('a purchase verified against an existing session finishes straight to the app', async () => {
      const wrapper = mountView()
      await advanceToPlans(wrapper)

      await wrapper.find('[data-testid="stub-purchased"]').trigger('click')

      expect(isOnboardingCompleted()).toBe(true)
      expect(mockReplace).toHaveBeenCalledWith('/')
    })
  })
})
