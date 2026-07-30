import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (first-run onboarding): orchestration of the native
 * first-run welcome page. The step component is stubbed (per AGENTS_DEV: stub
 * heavy deps) — this spec pins the page wiring and the finish path, which must
 * persist completion so the flow never re-appears, and must enter the app
 * without any sign-in.
 */

const mockReplace = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
}))

import OnboardingView from '@/views/OnboardingView.vue'
import { isOnboardingCompleted } from '@/composables/useOnboarding'

const stubs = {
  OnboardingWelcomeStep: {
    template:
      '<div data-testid="stub-welcome"><button data-testid="stub-next" @click="$emit(\'next\')" /></div>',
    emits: ['next'],
  },
}

function mountView() {
  return mount(OnboardingView, { global: { stubs } })
}

describe('OnboardingView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    sessionStorage.clear()
  })

  it('renders the welcome page as the only step', () => {
    const wrapper = mountView()

    expect(wrapper.find('[data-testid="stub-welcome"]').exists()).toBe(true)
    // No plans page means no dot navigation and no skip affordance.
    expect(wrapper.find('[data-testid="section-progress"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-skip-onboarding"]').exists()).toBe(false)
  })

  it('the get-started CTA persists completion and enters the guest chat', async () => {
    const wrapper = mountView()

    await wrapper.find('[data-testid="stub-next"]').trigger('click')

    expect(isOnboardingCompleted()).toBe(true)
    expect(mockReplace).toHaveBeenCalledWith('/')
  })

  it('offers the language picker so the first page can be read in the user language', () => {
    const wrapper = mountView()

    expect(wrapper.find('[data-testid="btn-language-toggle"]').exists()).toBe(true)
  })
})
