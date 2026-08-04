import { describe, it, expect, vi, beforeEach } from 'vitest'

/**
 * MOBILE-APP SEAM (first-run onboarding): pins the gate logic of the one-time
 * native onboarding flow. The single most important invariant: the web build
 * NEVER sees the flow, and a native user sees it at most once per install.
 */

let mockIsNative = true

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    isNativeApp: () => mockIsNative,
  }
})

import {
  isOnboardingCompleted,
  markOnboardingCompleted,
  shouldShowOnboarding,
} from '@/composables/useOnboarding'
import { GUEST_STORAGE_KEY } from '@/stores/guest'

describe('useOnboarding', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    mockIsNative = true
  })

  describe('shouldShowOnboarding', () => {
    it('shows on a true native first run (signed out, no guest session, not completed)', () => {
      expect(shouldShowOnboarding(false)).toBe(true)
    })

    it('NEVER shows on the web build', () => {
      mockIsNative = false
      expect(shouldShowOnboarding(false)).toBe(false)
    })

    it('does not show for signed-in users', () => {
      expect(shouldShowOnboarding(true)).toBe(false)
    })

    it('does not show again after completion', () => {
      markOnboardingCompleted()
      expect(shouldShowOnboarding(false)).toBe(false)
    })

    it('treats an existing guest session as "not a first run"', () => {
      localStorage.setItem(GUEST_STORAGE_KEY, 'guest-123')
      expect(shouldShowOnboarding(false)).toBe(false)
    })
  })

  describe('completion flag', () => {
    it('persists completion in localStorage', () => {
      expect(isOnboardingCompleted()).toBe(false)
      markOnboardingCompleted()
      expect(isOnboardingCompleted()).toBe(true)
      expect(localStorage.getItem('synaplan.onboardingCompleted')).toBe('1')
    })
  })
})
