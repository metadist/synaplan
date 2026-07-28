/**
 * MOBILE-APP SEAM: shared social sign-in logic (provider catalogue + the
 * provider round trip), extracted from `RegisterView` so the post-purchase
 * onboarding account step can reuse it without duplicating the native/web
 * branching.
 *
 * - Web: full-page redirect to the backend's provider login route (the
 *   returned promise never resolves `true`; the page navigates away).
 * - Native shell: providers block embedded WebViews, so OAuth runs in the
 *   system browser (deep-link handoff), except Sign in with Apple on iOS,
 *   which must use the native sheet (App Review Guideline 4.8).
 */
import { ref } from 'vue'
import { useConfigStore } from '@/stores/config'
import { useAuthStore } from '@/stores/auth'
import { isNativeApp, getNativePlatform } from '@/services/api/nativeRuntime'
import { startNativeOAuth } from '@/services/api/nativeOAuth'
import { startNativeAppleSignIn } from '@/services/api/nativeAppleAuth'
import { setPendingRedirect } from '@/utils/pendingAuthRedirect'

export interface SocialProvider {
  id: string
  name: string
  enabled: boolean
  icon: string
  auto_redirect?: boolean
}

export function useSocialAuth() {
  const config = useConfigStore()
  const authStore = useAuthStore()

  const providers = ref<SocialProvider[]>([])
  /** Provider-flow error, cleared on the next attempt. Empty = no error. */
  const error = ref('')
  /** True while a native provider round trip is in flight. */
  const busy = ref(false)

  async function loadProviders(): Promise<void> {
    try {
      const response = await fetch(`${config.appBaseUrl}/api/v1/auth/providers`)
      const data = await response.json()
      providers.value = data.providers || []
    } catch (e) {
      console.error('Failed to load social providers:', e)
      providers.value = []
    }
  }

  /**
   * Run the provider sign-in. `redirect` (a same-origin path) survives the
   * OAuth round trip via the pending-redirect stash.
   *
   * Returns `true` only when the session was established in-place (native
   * flow); a user-dismissed sheet/browser returns `false` without setting
   * `error`. On the web this navigates away and never returns `true`.
   */
  async function signInWith(provider: string, redirect?: string): Promise<boolean> {
    // OAuth round-trip strips the SPA's URL state, so stash the intent
    // for OAuthCallback to pick up. setPendingRedirect validates internally.
    if (redirect) setPendingRedirect(redirect)

    if (isNativeApp()) {
      error.value = ''
      busy.value = true
      try {
        // iOS must use the native Sign-in-with-Apple sheet (Guideline 4.8);
        // every other provider (and Apple on Android) uses the system-browser
        // OAuth flow.
        const result =
          'apple' === provider && 'ios' === getNativePlatform()
            ? await startNativeAppleSignIn()
            : await startNativeOAuth(provider)
        if (!result.success) {
          // A user-dismissed browser is a silent cancellation, not an error.
          if (!result.cancelled) {
            error.value = result.error || 'Login failed'
          }
          return false
        }
        // Tokens are stored — verify the session via the store so the reactive
        // auth state + config (guest → authenticated) update everywhere.
        const ok = await authStore.handleOAuthCallback()
        if (!ok) {
          error.value = 'Login failed'
          return false
        }
        return true
      } finally {
        busy.value = false
      }
    }

    window.location.href = `${config.appBaseUrl}/api/v1/auth/${provider}/login`
    return false
  }

  return { providers, loadProviders, signInWith, error, busy }
}
