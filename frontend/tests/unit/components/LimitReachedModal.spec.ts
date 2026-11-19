import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LimitReachedModal from '@/components/common/LimitReachedModal.vue'

/**
 * Without billing there is nothing to buy, so the modal must not tell a person
 * to upgrade. It points them at whoever runs the server instead, and a plan
 * with none of an action must not claim they used it up.
 */

const billing = vi.hoisted(() => ({ enabled: false }))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ billing }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => false,
}))

vi.mock('@/services/api/nativeServer', () => ({
  isPurchaseAllowed: () => true,
}))

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: { createTopupSession: vi.fn() },
}))

function mountModal(props: Record<string, unknown>) {
  return mount(LimitReachedModal, {
    props: { isOpen: true, actionType: 'MESSAGES', ...props },
    global: { stubs: { Teleport: true, Transition: false } },
  })
}

describe('LimitReachedModal', () => {
  beforeEach(() => {
    billing.enabled = false
  })

  it('says the plan includes none when the server gives the Free plan no usage', () => {
    const wrapper = mountModal({ limitType: 'lifetime', used: 0, currentLimit: 0 })

    expect(wrapper.text()).toContain('Not included in your plan')
    expect(wrapper.text()).toContain('Ask the person who runs this Synaplan')
    expect(wrapper.text()).not.toContain('Upgrade')
  })

  it('says the usage is used up and who to ask when the plan had some', () => {
    const wrapper = mountModal({ limitType: 'lifetime', used: 5, currentLimit: 5 })

    expect(wrapper.text()).toContain('Limit reached')
    expect(wrapper.text()).toContain('includes 5')
    expect(wrapper.text()).not.toContain('Upgrade')
  })

  it('keeps the upgrade copy where billing sells plans', () => {
    billing.enabled = true
    const wrapper = mountModal({ limitType: 'lifetime', used: 50, currentLimit: 50 })

    expect(wrapper.text()).toContain('Free Limit Reached')
    expect(wrapper.text()).toContain('Upgrade')
  })

  it('keeps the hourly copy, which resets on its own', () => {
    const wrapper = mountModal({ limitType: 'hourly', used: 100, currentLimit: 100 })

    expect(wrapper.text()).toContain('Hourly Limit Reached')
  })
})
