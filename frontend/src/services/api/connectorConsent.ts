/**
 * Leaving Synaplan for a connector's consent page (Microsoft 365, Dropbox,
 * MCP servers, Notion) and coming back.
 *
 * On the web the page navigates there and the provider sends the browser back
 * to the callback, which a short-lived cookie ties to this browser.
 *
 * The native app keeps no cookies and providers refuse an embedded WebView, so
 * it starts the flow with `native=1` (the state is then bound to the account),
 * opens the system browser, and waits for the deep link
 * `<scheme>://oauth/connect?connector=…&code=…&state=…`. It then repeats the
 * callback itself with its Bearer token; the backend redeems the state only
 * for the account that started it and answers with the path to show.
 */
import { App as CapacitorApp } from '@capacitor/app'
import { Browser } from '@capacitor/browser'
import type { z } from 'zod'
import { httpClient } from './httpClient'
import { isNativeApp } from './nativeRuntime'
import {
  GetApiConnectionsDropboxCallbackResponseSchema,
  GetApiConnectionsM365CallbackResponseSchema,
  GetApiMcpServersOauthCallbackResponseSchema,
} from '@/generated/api-schemas'

const RETURN_MARKER = '://oauth/connect'

// The dismissal and the deep link are two independent native events; give a
// near-simultaneous deep link time to win before calling it a cancellation.
const BROWSER_DISMISS_GRACE_MS = 800

type ReturnSchema = z.ZodType<{ redirect: string }>

/** The only callbacks a deep link may be replayed to. */
const CALLBACKS: Record<string, { path: string; schema: ReturnSchema }> = {
  m365: {
    path: '/api/v1/connections/m365/callback',
    schema: GetApiConnectionsM365CallbackResponseSchema,
  },
  dropbox: {
    path: '/api/v1/connections/dropbox/callback',
    schema: GetApiConnectionsDropboxCallbackResponseSchema,
  },
  mcp: {
    path: '/api/v1/mcp-servers/oauth/callback',
    schema: GetApiMcpServersOauthCallbackResponseSchema,
  },
}

type Handle = { remove: () => Promise<void> }

/** Query for a consent start call: tells the backend the native app is asking. */
export function consentStartParams(): Record<string, string> | undefined {
  return isNativeApp() ? { native: '1' } : undefined
}

/**
 * Send the person to the consent page.
 *
 * Resolves `true` when this page is being left (the result arrives with the
 * next page load) and `false` when the person closed the browser without
 * finishing. Rejects when the answer could not be redeemed.
 */
export async function openConsent(url: string): Promise<boolean> {
  if (!isNativeApp()) {
    window.location.assign(url)
    return true
  }
  const answer = await waitForReturn(url)
  if (answer === null) {
    return false
  }
  window.location.assign(await redeem(answer))
  return true
}

/** The deep link the system browser handed back, or null when it was closed. */
function waitForReturn(url: string): Promise<string | null> {
  return new Promise((resolve, reject) => {
    let settled = false
    let dismissTimer: ReturnType<typeof setTimeout> | undefined
    const handles: Handle[] = []

    const keep = (handle: Handle): void => {
      if (settled) {
        void handle.remove().catch(() => undefined)
      } else {
        handles.push(handle)
      }
    }
    const settle = (finish: () => void): void => {
      if (settled) return
      settled = true
      if (dismissTimer) clearTimeout(dismissTimer)
      for (const handle of handles) {
        void handle.remove().catch(() => undefined)
      }
      void Browser.close().catch(() => undefined)
      finish()
    }

    void CapacitorApp.addListener('appUrlOpen', (event: { url: string }) => {
      const opened = event.url || ''
      if (opened.includes(RETURN_MARKER)) {
        settle(() => resolve(opened))
      }
    }).then(keep)

    void Browser.addListener('browserFinished', () => {
      if (settled) return
      dismissTimer = setTimeout(() => settle(() => resolve(null)), BROWSER_DISMISS_GRACE_MS)
    }).then(keep)

    Browser.open({ url }).catch(() => {
      settle(() => reject(new Error('Could not open the browser')))
    })
  })
}

/** Replay the provider's answer to its callback with the app's own token. */
async function redeem(deepLink: string): Promise<string> {
  const params = new URL(deepLink).searchParams
  const callback = CALLBACKS[params.get('connector') ?? '']
  if (!callback) {
    throw new Error('Unknown connector in the return link')
  }
  params.delete('connector')

  const { redirect } = await httpClient(callback.path, {
    params: Object.fromEntries(params),
    headers: { Accept: 'application/json' },
    schema: callback.schema,
  })
  if (!redirect.startsWith('/') || redirect.startsWith('//')) {
    throw new Error('Unexpected return path')
  }
  return redirect
}
