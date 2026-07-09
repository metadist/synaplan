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

/**
 * Switching the server from the own-server modal (page 1) reloads the WebView
 * (app-owned behavior of `SynaplanServer.save`). The step to resume at survives
 * that reload in sessionStorage — and evaporates with the session, so a later
 * cold start never jumps into the middle of the flow.
 */
const RESUME_STEP_KEY = 'synaplan.onboardingResumeStep'

/** True once the user finished or skipped the first-run onboarding. */
export function isOnboardingCompleted(): boolean {
  try {
    return '1' === localStorage.getItem(COMPLETED_KEY)
  } catch {
    // Storage unavailable → treat as completed so the user is never trapped.
    return true
  }
}

/** Persist that the first-run onboarding is done (finish or skip). */
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

/** Remember the step to resume at across the server-switch WebView reload. */
export function setOnboardingResumeStep(step: number): void {
  try {
    sessionStorage.setItem(RESUME_STEP_KEY, String(step))
  } catch {
    /* no-op */
  }
}

/** Drop a remembered resume step (e.g. the server probe failed → no reload). */
export function clearOnboardingResumeStep(): void {
  try {
    sessionStorage.removeItem(RESUME_STEP_KEY)
  } catch {
    /* no-op */
  }
}

/** Read + clear the resume step in one shot; null when there is none. */
export function consumeOnboardingResumeStep(): number | null {
  try {
    const raw = sessionStorage.getItem(RESUME_STEP_KEY)
    sessionStorage.removeItem(RESUME_STEP_KEY)
    if (null === raw) {
      return null
    }
    const step = Number.parseInt(raw, 10)
    return Number.isInteger(step) && step >= 1 && step <= 2 ? step : null
  } catch {
    return null
  }
}
