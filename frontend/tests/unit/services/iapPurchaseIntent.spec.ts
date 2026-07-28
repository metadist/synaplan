import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'

/**
 * MOBILE-APP SEAM: persisted purchase intent from the auth-first onboarding.
 * The contract pinned here: the intent survives via localStorage (register /
 * login round trips, WebView re-creation), expires after its TTL, and drives
 * the post-auth fallback route to the subscription page.
 */
import {
  setPurchaseIntent,
  peekPurchaseIntent,
  consumePurchaseIntent,
  clearPurchaseIntent,
  postAuthTargetPath,
} from '@/services/iapPurchaseIntent'

const INTENT = { planId: 'BUSINESS', productId: 'com.synaplan.app.business.monthly' }

describe('iapPurchaseIntent', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('persists and peeks the intent without clearing it', () => {
    setPurchaseIntent(INTENT)

    expect(peekPurchaseIntent()).toEqual(INTENT)
    expect(peekPurchaseIntent()).toEqual(INTENT)
  })

  it('consume returns the intent once and clears it', () => {
    setPurchaseIntent(INTENT)

    expect(consumePurchaseIntent()).toEqual(INTENT)
    expect(consumePurchaseIntent()).toBeNull()
  })

  it('expires after the TTL (a stale pick never surprises with a store sheet)', () => {
    vi.useFakeTimers()
    setPurchaseIntent(INTENT)

    vi.advanceTimersByTime(25 * 60 * 60 * 1000)

    expect(peekPurchaseIntent()).toBeNull()
  })

  it('drops malformed storage entries instead of returning garbage', () => {
    localStorage.setItem('synaplan.iapPurchaseIntent', '{"planId":42}')

    expect(peekPurchaseIntent()).toBeNull()
    expect(localStorage.getItem('synaplan.iapPurchaseIntent')).toBeNull()
  })

  it('routes post-auth to the subscription page only while an intent is pending', () => {
    expect(postAuthTargetPath()).toBe('/')

    setPurchaseIntent(INTENT)
    expect(postAuthTargetPath()).toBe('/subscription')

    clearPurchaseIntent()
    expect(postAuthTargetPath()).toBe('/')
  })

  it('lets a pending intent win over redirect hints (router-guard ?redirect=/)', () => {
    setPurchaseIntent(INTENT)

    // The router guard stamps ?redirect=/ on every signed-out entry navigation
    // (e.g. after a native server switch reload) — it must not drop the purchase.
    expect(postAuthTargetPath('/')).toBe('/subscription')
    expect(postAuthTargetPath('/settings')).toBe('/subscription')
  })

  it('applies the redirect hint when no intent is pending', () => {
    expect(postAuthTargetPath('/settings')).toBe('/settings')
    expect(postAuthTargetPath(null)).toBe('/')
  })
})
