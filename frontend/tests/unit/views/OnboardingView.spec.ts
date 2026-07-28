import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

/**
 * MOBILE-APP SEAM (first-run onboarding): orchestration of the native
 * first-run flow. The step components are stubbed (per AGENTS_DEV: stub heavy
 * deps) — this spec pins the page wiring, the skip/finish paths (which must
 * persist completion so the flow never re-appears), and the auth-first
 * purchase orchestration: plans CTA → account step (purchase mode) →
 * terminal purchase step, plus the redeem fallback after a signed-out
 * restore and the register-first fallback into register → subscription.
 */

const mockReplace = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
}))

import OnboardingView from '@/views/OnboardingView.vue'
import { isOnboardingCompleted, setOnboardingResumeStep } from '@/composables/useOnboarding'
import { peekPendingRedirect } from '@/utils/pendingAuthRedirect'
import { peekPurchaseIntent } from '@/services/iapPurchaseIntent'

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
      "<button data-testid=\"stub-buy\" @click=\"$emit('buy-plan', { planId: 'PRO', productId: 'com.synaplan.app.pro.monthly' })\" />" +
      '<button data-testid="stub-purchased-unlinked" @click="$emit(\'purchased-unlinked\')" />' +
      '</div>',
    emits: ['back', 'guest', 'login', 'register', 'select-plan', 'buy-plan', 'purchased-unlinked'],
  },
  OnboardingAccountStep: {
    template:
      '<div data-testid="stub-account" :data-context="context">' +
      '<button data-testid="stub-authenticated" @click="$emit(\'authenticated\')" />' +
      '<button data-testid="stub-account-register" @click="$emit(\'register\')" />' +
      '<button data-testid="stub-account-login" @click="$emit(\'login\')" />' +
      '</div>',
    props: ['context'],
    emits: ['authenticated', 'register', 'login'],
  },
  OnboardingPurchaseStep: {
    template:
      '<div data-testid="stub-purchase" :data-product-id="productId">' +
      '<button data-testid="stub-purchase-purchased" @click="$emit(\'purchased\')" />' +
      '<button data-testid="stub-purchase-done" @click="$emit(\'done\')" />' +
      '<button data-testid="stub-purchase-manage" @click="$emit(\'manage\')" />' +
      '</div>',
    props: ['productId'],
    emits: ['purchased', 'done', 'manage'],
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

  describe('auth-first purchase flow (buy-plan → account → purchase)', () => {
    async function advanceToAccountWithIntent(wrapper: ReturnType<typeof mountView>) {
      await advanceToPlans(wrapper)
      await wrapper.find('[data-testid="stub-buy"]').trigger('click')
      await nextTick()
      await nextTick()
    }

    it('the plans CTA advances to the account step in purchase mode (nothing paid yet)', async () => {
      const wrapper = mountView()
      await advanceToAccountWithIntent(wrapper)

      const account = wrapper.find('[data-testid="stub-account"]')
      expect(account.exists()).toBe(true)
      expect(account.attributes('data-context')).toBe('purchase')
      // Nothing is paid yet: completion must NOT be persisted, skip stays,
      // only the dot navigation is gone (the step is outside the dots).
      expect(isOnboardingCompleted()).toBe(false)
      expect(wrapper.find('[data-testid="btn-skip-onboarding"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="section-progress"]').exists()).toBe(false)
      // The intent is persisted so the e-mail register / login round trip
      // (verification, WebView re-creation, restart) can resume the purchase.
      expect(peekPurchaseIntent()).toEqual({
        planId: 'PRO',
        productId: 'com.synaplan.app.pro.monthly',
      })
    })

    it('skipping from the pre-purchase account step drops the persisted intent', async () => {
      const wrapper = mountView()
      await advanceToAccountWithIntent(wrapper)

      await wrapper.find('[data-testid="btn-skip-onboarding"]').trigger('click')

      // A deliberate opt-out: no later sign-in may resurrect the store sheet.
      expect(peekPurchaseIntent()).toBeNull()
      expect(mockReplace).toHaveBeenCalledWith('/')
    })

    it('after an in-place sign-in the terminal purchase step runs with the selected product', async () => {
      const wrapper = mountView()
      await advanceToAccountWithIntent(wrapper)

      await wrapper.find('[data-testid="stub-authenticated"]').trigger('click')
      await nextTick()
      await nextTick()

      const purchase = wrapper.find('[data-testid="stub-purchase"]')
      expect(purchase.exists()).toBe(true)
      expect(purchase.attributes('data-product-id')).toBe('com.synaplan.app.pro.monthly')
      // No skip on the purchase step — it has its own retry / "later" exits.
      expect(wrapper.find('[data-testid="btn-skip-onboarding"]').exists()).toBe(false)
    })

    it('a granted purchase (and equally "later") finishes into the app', async () => {
      const wrapper = mountView()
      await advanceToAccountWithIntent(wrapper)
      await wrapper.find('[data-testid="stub-authenticated"]').trigger('click')
      await nextTick()
      await nextTick()

      await wrapper.find('[data-testid="stub-purchase-purchased"]').trigger('click')

      expect(isOnboardingCompleted()).toBe(true)
      expect(mockReplace).toHaveBeenCalledWith('/')
      // Settled — the persisted intent must not linger.
      expect(peekPurchaseIntent()).toBeNull()
    })

    it('"already subscribed" routes to the subscription page for managing', async () => {
      const wrapper = mountView()
      await advanceToAccountWithIntent(wrapper)
      await wrapper.find('[data-testid="stub-authenticated"]').trigger('click')
      await nextTick()
      await nextTick()

      await wrapper.find('[data-testid="stub-purchase-manage"]').trigger('click')

      expect(isOnboardingCompleted()).toBe(true)
      expect(mockReplace).toHaveBeenCalledWith('/subscription')
      // Settled ("already subscribed") — the persisted intent must not linger.
      expect(peekPurchaseIntent()).toBeNull()
    })

    it('the e-mail path keeps the purchase intent via the /subscription redirect', async () => {
      const wrapper = mountView()
      await advanceToAccountWithIntent(wrapper)

      await wrapper.find('[data-testid="stub-account-register"]').trigger('click')

      expect(peekPendingRedirect()).toBe('/subscription')
      // The persisted intent survives the round trip and lets the
      // subscription page continue the purchase after authentication.
      expect(peekPurchaseIntent()).not.toBeNull()
      expect(mockReplace).toHaveBeenCalledWith({
        name: 'register',
        query: { redirect: '/subscription' },
      })
    })

    it('the login path keeps the purchase intent via the /subscription redirect', async () => {
      const wrapper = mountView()
      await advanceToAccountWithIntent(wrapper)

      await wrapper.find('[data-testid="stub-account-login"]').trigger('click')

      expect(peekPendingRedirect()).toBe('/subscription')
      expect(peekPurchaseIntent()).not.toBeNull()
      expect(mockReplace).toHaveBeenCalledWith({ name: 'login' })
    })
  })

  describe('redeem fallback (signed-out restore → account step)', () => {
    async function advanceToRedeemAccount(wrapper: ReturnType<typeof mountView>) {
      await advanceToPlans(wrapper)
      await wrapper.find('[data-testid="stub-purchased-unlinked"]').trigger('click')
      await nextTick()
      await nextTick()
    }

    it('a re-delivered signed-out purchase advances to the account step in redeem mode', async () => {
      const wrapper = mountView()
      await advanceToRedeemAccount(wrapper)

      const account = wrapper.find('[data-testid="stub-account"]')
      expect(account.exists()).toBe(true)
      expect(account.attributes('data-context')).toBe('redeem')
      // Completion is persisted NOW: backgrounding the app must lead to the
      // guest-chat reminder banner, never a second onboarding run.
      expect(isOnboardingCompleted()).toBe(true)
    })

    it('the redeem account step is terminal: no skip, no dot navigation', async () => {
      const wrapper = mountView()
      await advanceToRedeemAccount(wrapper)

      expect(wrapper.find('[data-testid="btn-skip-onboarding"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="section-progress"]').exists()).toBe(false)
    })

    it('an in-place sign-in enters the app (redemption runs in the post-auth hook)', async () => {
      const wrapper = mountView()
      await advanceToRedeemAccount(wrapper)

      await wrapper.find('[data-testid="stub-authenticated"]').trigger('click')
      expect(mockReplace).toHaveBeenCalledWith('/')
    })

    it('the e-mail path routes to plain register (redemption is post-login)', async () => {
      const wrapper = mountView()
      await advanceToRedeemAccount(wrapper)

      await wrapper.find('[data-testid="stub-account-register"]').trigger('click')
      expect(mockReplace).toHaveBeenCalledWith({ name: 'register' })
    })
  })
})
