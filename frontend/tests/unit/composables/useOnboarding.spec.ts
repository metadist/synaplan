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
  setOnboardingResumeStep,
  clearOnboardingResumeStep,
  consumeOnboardingResumeStep,
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

  describe('resume step (server-switch WebView reload)', () => {
    it('sets and consumes the resume step exactly once', () => {
      setOnboardingResumeStep(2)
      expect(consumeOnboardingResumeStep()).toBe(2)
      // One-shot: the second read is empty.
      expect(consumeOnboardingResumeStep()).toBeNull()
    })

    it('can be cleared (probe rejected → no reload happened)', () => {
      setOnboardingResumeStep(2)
      clearOnboardingResumeStep()
      expect(consumeOnboardingResumeStep()).toBeNull()
    })

    it('rejects garbage values instead of resuming at a broken step', () => {
      sessionStorage.setItem('synaplan.onboardingResumeStep', 'NaN')
      expect(consumeOnboardingResumeStep()).toBeNull()
      sessionStorage.setItem('synaplan.onboardingResumeStep', '99')
      expect(consumeOnboardingResumeStep()).toBeNull()
    })
  })
})
