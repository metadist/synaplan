import { describe, it, expect, vi, beforeEach } from 'vitest'

/**
 * The paywall must never nag the wrong audience: BUSINESS/ADMIN have nothing to
 * upgrade to, paying tiers only see it when their allowance is actually gone,
 * and an install without a purchase channel (billing off, or the native app on
 * a custom server) must fall back to the plain modals.
 */

const mockAuth = { isAuthenticated: false, userLevel: 'NEW' }
const mockBilling = { enabled: true }
const mockPurchaseAllowed = vi.fn(() => true)

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => mockAuth,
}))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ billing: mockBilling }),
}))

vi.mock('@/services/api/nativeServer', () => ({
  isPurchaseAllowed: () => mockPurchaseAllowed(),
}))

import {
  usePaywallPrompt,
  PAYWALL_LAST_SHOWN_KEY,
  PAYWALL_REMINDER_INTERVAL_MS,
} from '@/composables/usePaywallPrompt'

describe('usePaywallPrompt', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    mockAuth.isAuthenticated = false
    mockAuth.userLevel = 'NEW'
    mockBilling.enabled = true
    mockPurchaseAllowed.mockReturnValue(true)
  })

  it('opens for a guest whose trial is spent', () => {
    const paywall = usePaywallPrompt()

    expect(paywall.openPaywall('guest_limit')).toBe(true)
    expect(paywall.isPaywallOpen.value).toBe(true)
    expect(paywall.paywallReason.value).toBe('guest_limit')
  })

  it('never opens for BUSINESS or ADMIN — there is nothing above them', () => {
    mockAuth.isAuthenticated = true

    for (const level of ['BUSINESS', 'ADMIN']) {
      mockAuth.userLevel = level
      const paywall = usePaywallPrompt()

      expect(paywall.openPaywall('quota_exhausted')).toBe(false)
      expect(paywall.openPaywall('reminder')).toBe(false)
      expect(paywall.isPaywallOpen.value).toBe(false)
    }
  })

  it('shows a paying tier the upgrade only when its allowance is spent', () => {
    mockAuth.isAuthenticated = true
    mockAuth.userLevel = 'PRO'
    const paywall = usePaywallPrompt()

    expect(paywall.isEligible('quota_exhausted')).toBe(true)
    expect(paywall.isEligible('reminder')).toBe(false)
  })

  it('reminds guests and free accounts', () => {
    const guest = usePaywallPrompt()
    expect(guest.isEligible('reminder')).toBe(true)

    mockAuth.isAuthenticated = true
    mockAuth.userLevel = 'NEW'
    expect(usePaywallPrompt().isEligible('reminder')).toBe(true)
  })

  it('stays closed without a purchase channel', () => {
    mockBilling.enabled = false
    expect(usePaywallPrompt().openPaywall('guest_limit')).toBe(false)

    mockBilling.enabled = true
    mockPurchaseAllowed.mockReturnValue(false)
    expect(usePaywallPrompt().openPaywall('guest_limit')).toBe(false)
  })

  it('throttles the reminder to once per interval', () => {
    const paywall = usePaywallPrompt()
    const now = Date.now()

    expect(paywall.shouldRemind(now)).toBe(true)
    paywall.openPaywall('reminder')

    expect(paywall.shouldRemind(now)).toBe(false)
    expect(paywall.shouldRemind(now + PAYWALL_REMINDER_INTERVAL_MS - 1000)).toBe(false)
    expect(paywall.shouldRemind(now + PAYWALL_REMINDER_INTERVAL_MS)).toBe(true)
  })

  it('lets a hard trigger through even inside the reminder cool-down', () => {
    localStorage.setItem(PAYWALL_LAST_SHOWN_KEY, String(Date.now()))
    const paywall = usePaywallPrompt()

    expect(paywall.shouldRemind()).toBe(false)
    expect(paywall.openPaywall('guest_limit')).toBe(true)
  })

  it('closes on demand', () => {
    const paywall = usePaywallPrompt()
    paywall.openPaywall('guest_limit')

    paywall.closePaywall()

    expect(paywall.isPaywallOpen.value).toBe(false)
  })
})
