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
  paywallReasonForLimit,
  PAYWALL_LAST_SHOWN_KEY,
  PAYWALL_REMINDER_INTERVAL_MS,
} from '@/composables/usePaywallPrompt'
import type { LimitCheckResult } from '@/composables/useLimitCheck'

function block(overrides: Partial<LimitCheckResult> = {}): LimitCheckResult {
  return {
    allowed: false,
    limitType: 'monthly',
    actionType: 'MESSAGES',
    used: 100,
    limit: 100,
    remaining: 0,
    userLevel: 'PRO',
    phoneVerified: true,
    ...overrides,
  }
}

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
    // Pin the clock: `openPaywall` stamps `Date.now()`, so a real clock ticking
    // a millisecond into the call would push the cool-down past the assertions.
    const now = Date.UTC(2026, 0, 15, 9, 0, 0)
    vi.useFakeTimers()
    vi.setSystemTime(now)

    try {
      const paywall = usePaywallPrompt()

      expect(paywall.shouldRemind(now)).toBe(true)
      paywall.openPaywall('reminder')

      expect(paywall.shouldRemind(now)).toBe(false)
      expect(paywall.shouldRemind(now + PAYWALL_REMINDER_INTERVAL_MS - 1000)).toBe(false)
      expect(paywall.shouldRemind(now + PAYWALL_REMINDER_INTERVAL_MS)).toBe(true)
    } finally {
      vi.useRealTimers()
    }
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

describe('paywallReasonForLimit', () => {
  it('sells an upgrade once the allowance is spent', () => {
    expect(paywallReasonForLimit(block({ limitType: 'monthly' }))).toBe('quota_exhausted')
    expect(paywallReasonForLimit(block({ limitType: 'lifetime', userLevel: 'NEW' }))).toBe(
      'free_limit'
    )
  })

  it('leaves an hourly throttle to the modal with its reset countdown', () => {
    expect(paywallReasonForLimit(block({ limitType: 'hourly' }))).toBeNull()
  })

  it('keeps the cheaper top-up on the web', () => {
    expect(paywallReasonForLimit(block({ topupAvailable: true }))).toBeNull()
  })

  it('keeps the free phone-verification remedy for anonymous users', () => {
    expect(
      paywallReasonForLimit(
        block({ limitType: 'lifetime', userLevel: 'ANONYMOUS', phoneVerified: false })
      )
    ).toBeNull()
    expect(
      paywallReasonForLimit(
        block({ limitType: 'lifetime', userLevel: 'ANONYMOUS', phoneVerified: true })
      )
    ).toBe('free_limit')
  })
})
