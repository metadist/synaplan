import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * MOBILE-APP SEAM (first-run onboarding), page 4 (auth-first): the terminal
 * purchase step. The core contract pinned here is the ORDER of operations —
 * the subscription pre-check runs BEFORE the store sheet, so an account that
 * already has an active subscription is never charged again (the exact
 * conflict the auth-first flow exists to prevent). Purchase outcomes map to
 * success / pending / retry+later, and an UNKNOWN account state (failed
 * pre-check) never triggers a purchase.
 */

const mockGetSubscriptionStatus = vi.fn()
const mockInitNativeIap = vi.fn(async (_ids: string[]) => true)
const mockPurchaseProduct = vi.fn()
const mockSuccess = vi.fn()

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getSubscriptionStatus: (...args: unknown[]) => mockGetSubscriptionStatus(...args),
  },
}))

vi.mock('@/services/nativeIap', () => ({
  initNativeIap: (ids: string[]) => mockInitNativeIap(ids),
  purchaseProduct: (...args: unknown[]) => mockPurchaseProduct(...args),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: vi.fn() }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

import OnboardingPurchaseStep from '@/components/onboarding/OnboardingPurchaseStep.vue'

const PRODUCT_ID = 'com.synaplan.app.pro.monthly'

function mountStep() {
  return mount(OnboardingPurchaseStep, { props: { productId: PRODUCT_ID } })
}

describe('OnboardingPurchaseStep', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockInitNativeIap.mockResolvedValue(true)
    mockGetSubscriptionStatus.mockResolvedValue({ hasSubscription: false, active: false })
  })

  it('never opens the store sheet when the account already has an active subscription', async () => {
    mockGetSubscriptionStatus.mockResolvedValue({
      hasSubscription: true,
      active: true,
      plan: 'PRO',
      tier: 'PRO',
    })
    const wrapper = mountStep()
    await flushPromises()

    // The conflict is caught BEFORE any money moves.
    expect(mockPurchaseProduct).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="text-purchase-already"]').exists()).toBe(true)

    await wrapper.find('[data-testid="btn-purchase-to-chat"]').trigger('click')
    expect(wrapper.emitted('done')).toHaveLength(1)

    await wrapper.find('[data-testid="btn-purchase-manage"]').trigger('click')
    expect(wrapper.emitted('manage')).toHaveLength(1)
  })

  it('purchases after a clean pre-check and emits purchased with a success toast', async () => {
    mockPurchaseProduct.mockResolvedValue({ status: 'granted', tier: 'PRO' })
    const wrapper = mountStep()
    await flushPromises()

    expect(mockGetSubscriptionStatus).toHaveBeenCalled()
    expect(mockPurchaseProduct).toHaveBeenCalledWith(PRODUCT_ID)
    expect(mockSuccess).toHaveBeenCalled()
    expect(wrapper.emitted('purchased')).toHaveLength(1)
  })

  it('a dismissed store sheet lands on retry + "later" (nothing was charged)', async () => {
    mockPurchaseProduct.mockResolvedValue({ status: 'cancelled' })
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-purchase-retry"]').exists()).toBe(true)

    await wrapper.find('[data-testid="btn-purchase-later"]').trigger('click')
    expect(wrapper.emitted('done')).toHaveLength(1)
  })

  it('retry re-runs the pre-check and the purchase', async () => {
    mockPurchaseProduct
      .mockResolvedValueOnce({ status: 'error', code: 'store_error' })
      .mockResolvedValueOnce({ status: 'granted', tier: 'PRO' })
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.find('[data-testid="text-purchase-error"]').exists()).toBe(true)

    await wrapper.find('[data-testid="btn-purchase-retry"]').trigger('click')
    await flushPromises()

    // The pre-check guards EVERY attempt, not just the first one.
    expect(mockGetSubscriptionStatus).toHaveBeenCalledTimes(2)
    expect(mockPurchaseProduct).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('purchased')).toHaveLength(1)
  })

  it('never purchases while the account state is unknown (failed pre-check)', async () => {
    mockGetSubscriptionStatus.mockRejectedValue(new Error('network down'))
    const wrapper = mountStep()
    await flushPromises()

    expect(mockPurchaseProduct).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="btn-purchase-retry"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="text-purchase-error"]').exists()).toBe(true)
  })

  it('a store-deferred purchase (Ask to Buy) shows the pending state with a chat exit', async () => {
    mockPurchaseProduct.mockResolvedValue({ status: 'pending' })
    const wrapper = mountStep()
    await flushPromises()

    expect(wrapper.find('[data-testid="text-purchase-pending"]').exists()).toBe(true)

    await wrapper.find('[data-testid="btn-purchase-to-chat"]').trigger('click')
    expect(wrapper.emitted('done')).toHaveLength(1)
  })
})
