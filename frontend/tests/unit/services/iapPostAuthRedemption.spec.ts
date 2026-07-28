import { describe, it, expect, vi, beforeEach } from 'vitest'

/**
 * MOBILE-APP SEAM: guard around the post-auth redemption of an unlinked
 * store purchase (the purchase-first fallback for signed-out restore /
 * crash recovery). The contract pinned here: an account that ALREADY has an
 * active subscription never gets a held purchase auto-linked — the user gets
 * platform-specific refund guidance instead — and an UNKNOWN account state
 * (failed status check) keeps the redemption pending rather than risking a
 * double charge.
 */

const mockHasPendingIapRedemption = vi.fn()
const mockRedeemPendingIapPurchase = vi.fn()
const mockDismissPendingIapRedemption = vi.fn()
const mockIsNativeApp = vi.fn(() => true)
const mockGetNativePlatform = vi.fn(() => 'ios')
const mockGetSubscriptionStatus = vi.fn()
const mockSuccess = vi.fn()
const mockError = vi.fn()
const mockRefreshUser = vi.fn()

vi.mock('@/services/nativeIap', () => ({
  hasPendingIapRedemption: () => mockHasPendingIapRedemption(),
  redeemPendingIapPurchase: () => mockRedeemPendingIapPurchase(),
  dismissPendingIapRedemption: () => mockDismissPendingIapRedemption(),
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => mockIsNativeApp(),
  getNativePlatform: () => mockGetNativePlatform(),
}))

vi.mock('@/services/api/subscriptionApi', () => ({
  subscriptionApi: {
    getSubscriptionStatus: () => mockGetSubscriptionStatus(),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: mockError }),
}))

// Assert on message KEYS, not rendered copy.
vi.mock('@/i18n', () => ({
  i18n: { global: { t: (key: string) => key } },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ refreshUser: mockRefreshUser }),
}))

import { redeemPendingIapPurchaseAfterAuth } from '@/services/iapPostAuthRedemption'

describe('redeemPendingIapPurchaseAfterAuth', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockIsNativeApp.mockReturnValue(true)
    mockGetNativePlatform.mockReturnValue('ios')
    mockHasPendingIapRedemption.mockReturnValue(true)
    mockGetSubscriptionStatus.mockResolvedValue({ hasSubscription: false, active: false })
  })

  it('is a cheap no-op without a pending redemption (no status call)', async () => {
    mockHasPendingIapRedemption.mockReturnValue(false)

    await redeemPendingIapPurchaseAfterAuth()

    expect(mockGetSubscriptionStatus).not.toHaveBeenCalled()
    expect(mockRedeemPendingIapPurchase).not.toHaveBeenCalled()
  })

  it('never auto-links into an account that already has an active subscription (iOS guidance)', async () => {
    mockGetSubscriptionStatus.mockResolvedValue({ hasSubscription: true, active: true })

    await redeemPendingIapPurchaseAfterAuth()

    expect(mockRedeemPendingIapPurchase).not.toHaveBeenCalled()
    expect(mockDismissPendingIapRedemption).toHaveBeenCalled()
    expect(mockError).toHaveBeenCalledWith(
      'subscription.native.redeemBlockedExistingIos',
      expect.any(Number)
    )
  })

  it('shows the Google Play auto-refund guidance on Android', async () => {
    mockGetNativePlatform.mockReturnValue('android')
    mockGetSubscriptionStatus.mockResolvedValue({ hasSubscription: true, active: true })

    await redeemPendingIapPurchaseAfterAuth()

    expect(mockError).toHaveBeenCalledWith(
      'subscription.native.redeemBlockedExistingAndroid',
      expect.any(Number)
    )
  })

  it('keeps the redemption pending when the account state cannot be checked', async () => {
    mockGetSubscriptionStatus.mockRejectedValue(new Error('network down'))

    await redeemPendingIapPurchaseAfterAuth()

    // Redeeming blind would reopen the double-charge window; leave the flag
    // so the next authentication retries the check.
    expect(mockRedeemPendingIapPurchase).not.toHaveBeenCalled()
    expect(mockDismissPendingIapRedemption).not.toHaveBeenCalled()
    expect(mockError).not.toHaveBeenCalled()
  })

  it('redeems after a clean pre-check and refreshes the principal on grant', async () => {
    mockRedeemPendingIapPurchase.mockResolvedValue({ status: 'granted', tier: 'PRO' })

    await redeemPendingIapPurchaseAfterAuth()

    expect(mockRedeemPendingIapPurchase).toHaveBeenCalled()
    expect(mockRefreshUser).toHaveBeenCalled()
    expect(mockSuccess).toHaveBeenCalledWith('subscription.native.purchaseSuccess')
  })
})
