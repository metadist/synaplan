/**
 * Native (Capacitor) OAuth flow.
 *
 * Social providers (Google/GitHub/Keycloak) refuse to render their consent
 * screens inside an embedded WebView, so the native shell drives OAuth through
 * the system browser and receives the result via a custom-scheme deep link:
 *
 *   1. Open `{api}/api/v1/auth/{provider}/login?native=1&challenge=…` in the
 *      system browser (Custom Tab / SFSafariViewController). `challenge` is
 *      the human-check token solved in the app; `native` only selects the
 *      deep-link return path.
 *   2. The backend completes OAuth and redirects to
 *      `com.synaplan.app://oauth/callback?handoff=<signed>` (or `?error=...`).
 *   3. Android/iOS route that deep link back into the app → `appUrlOpen` fires.
 *   4. We exchange the short-lived handoff token at `/auth/native/exchange` for
 *      real Bearer tokens and persist them (nativeAuth).
 *
 * The handoff indirection keeps long-lived tokens out of the browser history /
 * deep-link URL. Web builds never call this (see LoginView) and the Capacitor
 * imports resolve to harmless web fallbacks there anyway.
 */
import { App as CapacitorApp } from '@capacitor/app'
import { i18n } from '@/i18n'
import { Browser } from '@capacitor/browser'
import { getNativeApiBaseUrl } from '@/services/api/nativeRuntime'
import { setNativeTokens } from '@/services/api/nativeAuth'

export interface NativeOAuthResult {
  success: boolean
  error?: string
  /** True when the user dismissed the browser without completing OAuth. */
  cancelled?: boolean
}

const CALLBACK_MARKER = '://oauth'

/** System-browser start URL. `native` selects the return path; it is not a human check. */
export function nativeProviderLoginUrl(
  apiBase: string,
  provider: string,
  language: string,
  challenge = '',
  admission?: { plan?: string; code?: string }
): string {
  const params = new URLSearchParams({
    native: '1',
    language,
  })
  if (challenge !== '') params.set('challenge', challenge)
  if (admission?.plan) params.set('plan', admission.plan)
  if (admission?.code) params.set('code', admission.code)
  return `${apiBase}/api/v1/auth/${provider}/login?${params}`
}

// Grace window after the in-app browser reports it closed, to let a
// near-simultaneous deep-link callback (appUrlOpen) win before we treat the
// closure as a cancellation. This reconciles the unspecified ordering of the
// two independent native events — it is not a logic-race workaround.
const BROWSER_DISMISS_GRACE_MS = 800

export async function startNativeOAuth(
  provider: string,
  challenge = '',
  admission?: { plan?: string; code?: string }
): Promise<NativeOAuthResult> {
  return new Promise<NativeOAuthResult>((resolve) => {
    let settled = false
    let urlListener: { remove: () => Promise<void> } | undefined
    let browserListener: { remove: () => Promise<void> } | undefined
    let dismissTimer: ReturnType<typeof setTimeout> | undefined

    const finish = async (result: NativeOAuthResult): Promise<void> => {
      if (settled) {
        return
      }
      settled = true
      if (dismissTimer) {
        clearTimeout(dismissTimer)
      }
      for (const handle of [urlListener, browserListener]) {
        if (handle) {
          try {
            await handle.remove()
          } catch {
            // Listener already gone — nothing to do.
          }
        }
      }
      try {
        await Browser.close()
      } catch {
        // Browser may have been dismissed by the user already.
      }
      resolve(result)
    }

    CapacitorApp.addListener('appUrlOpen', (event: { url: string }) => {
      const url = event.url || ''
      // Ignore unrelated deep links (e.g. universal links handled elsewhere).
      if (!url.includes(CALLBACK_MARKER)) {
        return
      }

      let handoff: string | null
      let providerError: string | null
      try {
        const params = new URL(url).searchParams
        providerError = params.get('error')
        handoff = params.get('handoff')
      } catch {
        void finish({ success: false, error: 'Invalid callback URL' })
        return
      }

      if (providerError) {
        void finish({ success: false, error: providerError })
        return
      }
      if (!handoff) {
        void finish({ success: false, error: 'No handoff token received' })
        return
      }
      void exchangeHandoff(handoff).then(finish)
    }).then((handle) => {
      urlListener = handle
      // If finish() already ran (extremely fast callback), clean up immediately.
      if (settled) {
        void handle.remove()
      }
    })

    // If the user closes the system browser without completing OAuth, no callback
    // ever arrives. Treat that dismissal as a clean cancellation so the login UI
    // never gets stuck in a loading state.
    Browser.addListener('browserFinished', () => {
      if (settled) {
        return
      }
      dismissTimer = setTimeout(() => {
        void finish({ success: false, cancelled: true })
      }, BROWSER_DISMISS_GRACE_MS)
    }).then((handle) => {
      browserListener = handle
      if (settled) {
        void handle.remove()
      }
    })

    const authUrl = nativeProviderLoginUrl(
      getNativeApiBaseUrl(),
      provider,
      String(i18n.global.locale.value),
      challenge,
      admission
    )
    Browser.open({ url: authUrl }).catch(() => {
      void finish({ success: false, error: 'Could not open the browser' })
    })
  })
}

async function exchangeHandoff(handoff: string): Promise<NativeOAuthResult> {
  try {
    const response = await fetch(`${getNativeApiBaseUrl()}/api/v1/auth/native/exchange`, {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ handoff }),
    })
    if (!response.ok) {
      return { success: false, error: 'Token exchange failed' }
    }
    const data = await response.json()
    setNativeTokens(data?.tokens)
    return { success: true }
  } catch {
    return { success: false, error: 'Network error during token exchange' }
  }
}
