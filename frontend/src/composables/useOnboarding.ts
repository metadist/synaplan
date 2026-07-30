/**
 * MOBILE-APP SEAM (first-run onboarding): state helpers for the native-only
 * first-run onboarding flow (`/onboarding`, see `views/OnboardingView.vue`).
 *
 * The flow is shown exactly once per install: on the very first entry
 * navigation of a signed-out native user. Completion is persisted in
 * `localStorage` (same persistence class as the app-owned
 * `synaplan.serverUrl` — the WebView origin is stable per install).
 *
 * Every check is fail-safe: any storage error means "no onboarding", so the
 * web build and a broken storage environment can never be trapped in the
 * flow. Web deployments never see it at all (`isNativeApp()` gate).
 */
import { isNativeApp } from '@/services/api/nativeRuntime'
import { GUEST_STORAGE_KEY } from '@/stores/guest'

const COMPLETED_KEY = 'synaplan.onboardingCompleted'

/** True once the user finished the first-run onboarding. */
export function isOnboardingCompleted(): boolean {
  try {
    return '1' === localStorage.getItem(COMPLETED_KEY)
  } catch {
    // Storage unavailable → treat as completed so the user is never trapped.
    return true
  }
}

/** Persist that the first-run onboarding is done. */
export function markOnboardingCompleted(): void {
  try {
    localStorage.setItem(COMPLETED_KEY, '1')
  } catch {
    /* no-op: without storage the flow simply won't be persisted */
  }
}

/**
 * Should the entry navigation be routed into the first-run onboarding?
 *
 * Native shell only, signed-out only, once per install. An existing guest
 * session also counts as "not a first run" — that user has already chatted.
 */
export function shouldShowOnboarding(isAuthenticated: boolean): boolean {
  if (!isNativeApp()) {
    return false
  }
  if (isAuthenticated) {
    return false
  }
  if (isOnboardingCompleted()) {
    return false
  }
  try {
    if (localStorage.getItem(GUEST_STORAGE_KEY)) {
      return false
    }
  } catch {
    return false
  }
  return true
}
