/**
 * Native (Capacitor) OAuth flow.
 *
 * Social providers (Google/GitHub/Keycloak) refuse to render their consent
 * screens inside an embedded WebView, so the native shell drives OAuth through
 * the system browser and receives the result via a custom-scheme deep link:
 *
 *   1. Open `{api}/api/v1/auth/{provider}/login?native=1` in the system browser
 *      (Custom Tab / SFSafariViewController).
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
import { Browser } from '@capacitor/browser'
import { getNativeApiBaseUrl } from '@/services/api/nativeRuntime'
import { setNativeTokens } from '@/services/api/nativeAuth'

export interface NativeOAuthResult {
  success: boolean
  error?: string
}

const CALLBACK_MARKER = '://oauth'

export async function startNativeOAuth(provider: string): Promise<NativeOAuthResult> {
  return new Promise<NativeOAuthResult>((resolve) => {
    let settled = false
    let listenerHandle: { remove: () => Promise<void> } | undefined

    const finish = async (result: NativeOAuthResult): Promise<void> => {
      if (settled) {
        return
      }
      settled = true
      if (listenerHandle) {
        try {
          await listenerHandle.remove()
        } catch {
          // Listener already gone — nothing to do.
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
      listenerHandle = handle
      // If finish() already ran (extremely fast callback), clean up immediately.
      if (settled) {
        void handle.remove()
      }
    })

    const authUrl = `${getNativeApiBaseUrl()}/api/v1/auth/${provider}/login?native=1`
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
