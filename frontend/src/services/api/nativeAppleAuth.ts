/**
 * MOBILE-APP SEAM: native (iOS) Sign in with Apple.
 *
 * Apple App Review Guideline 4.8 requires the *native* Sign-in-with-Apple sheet
 * on iOS when other third-party social logins are offered — a browser round trip
 * is not sufficient. This module drives the native system UI via the Capacitor
 * plugin and exchanges the resulting identity token for Bearer tokens at the
 * backend's dedicated native endpoint:
 *
 *   1. `SignInWithApple.authorize()` presents the iOS system sheet and returns a
 *      signed identity token (JWT, audience = app bundle id) plus — ONLY on the
 *      first authorization — the user's name/email.
 *   2. POST `{ identityToken, firstName?, lastName?, email? }` to
 *      `/api/v1/auth/apple/native`, which verifies the token against Apple's
 *      JWKS and returns Bearer tokens in the body (no cookies / no deep link).
 *   3. Persist the tokens via `setNativeTokens` (secure storage).
 *
 * Web and Android never call this (see the platform guard in the auth views);
 * they fall back to the system-browser OAuth flow in `nativeOAuth.ts` /
 * the full-page redirect. The plugin ships a web stub, so importing it here does
 * not break the shared web bundle.
 */
import { SignInWithApple, type SignInWithAppleOptions } from '@capacitor-community/apple-sign-in'
import { getNativeApiBaseUrl } from '@/services/api/nativeRuntime'
import { setNativeTokens } from '@/services/api/nativeAuth'
import type { NativeOAuthResult } from '@/services/api/nativeOAuth'

/**
 * The app bundle id doubles as the identity-token audience on iOS. Locked in
 * `synaplan-apps/docs/IDENTIFIERS.md` and matched by the backend verifier's
 * `APPLE_APP_BUNDLE_ID`. Not a secret (also the deep-link scheme).
 */
const APP_BUNDLE_ID = 'com.synaplan.app'

/** iOS ASAuthorization "canceled" error code — a silent user dismissal. */
const APPLE_CANCELED_CODE = '1001'

export async function startNativeAppleSignIn(): Promise<NativeOAuthResult> {
  const options: SignInWithAppleOptions = {
    // clientId/redirectURI are ignored by the iOS native flow (it uses the app's
    // own bundle id), but the plugin's type contract requires them.
    clientId: APP_BUNDLE_ID,
    redirectURI: `${getNativeApiBaseUrl()}/api/v1/auth/apple/callback`,
    scopes: 'name email',
  }

  let identityToken: string
  let givenName: string | null
  let familyName: string | null
  let email: string | null
  try {
    const { response } = await SignInWithApple.authorize(options)
    identityToken = response.identityToken
    givenName = response.givenName ?? null
    familyName = response.familyName ?? null
    email = response.email ?? null
  } catch (e) {
    // A user-dismissed sheet is a clean cancellation, not an error.
    const message = e instanceof Error ? e.message : String(e)
    if (message.includes(APPLE_CANCELED_CODE) || /cancel/i.test(message)) {
      return { success: false, cancelled: true }
    }
    return { success: false, error: 'Apple sign-in failed' }
  }

  if (!identityToken) {
    return { success: false, error: 'No identity token received from Apple' }
  }

  try {
    const res = await fetch(`${getNativeApiBaseUrl()}/api/v1/auth/apple/native`, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ identityToken, firstName: givenName, lastName: familyName, email }),
    })
    if (!res.ok) {
      return { success: false, error: 'Apple sign-in verification failed' }
    }
    const data = await res.json()
    setNativeTokens(data?.tokens)
    return { success: true }
  } catch {
    return { success: false, error: 'Network error during Apple sign-in' }
  }
}
