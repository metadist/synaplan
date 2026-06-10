/**
 * Thin wrapper around the realtime token endpoints.
 *
 * Two flavours mirror the two backend controllers:
 *
 *   * `fetchOperatorConnectionToken()` — uses the auth cookie to identify
 *     the dashboard user.
 *   * `fetchVisitorConnectionToken()`  — anonymous; proves possession of a
 *     (widgetId, sessionId) pair.
 *
 * Both subscription helpers go through the same `/realtime/subscribe`
 * endpoint and let the backend authorizer locator decide what's allowed.
 *
 * Networking note: we deliberately use raw `fetch` (not the project's
 * `httpClient`) for the widget visitor flow because the embedded widget
 * runs cross-origin and must NOT depend on cookie-based auth or the 401
 * refresh/redirect machinery that assumes a logged-in user. Responses are
 * still Zod-validated in both flavours.
 */

import { httpClient, getApiBaseUrl } from '@/services/api/httpClient'
import {
  ConnectionTokenResponseSchema,
  SubscriptionTokenResponseSchema,
  type ConnectionTokenResponse,
  type SubscriptionTokenResponse,
} from './types'

export async function fetchOperatorConnectionToken(): Promise<ConnectionTokenResponse> {
  return httpClient('/api/v1/realtime/token', {
    method: 'POST',
    schema: ConnectionTokenResponseSchema,
  })
}

export async function fetchVisitorConnectionToken(
  widgetId: string,
  sessionId: string,
  apiBaseUrl?: string
): Promise<ConnectionTokenResponse> {
  const base = apiBaseUrl ?? getApiBaseUrl()
  const response = await fetch(
    `${base}/api/v1/realtime/widget/${encodeURIComponent(widgetId)}/sessions/${encodeURIComponent(sessionId)}/token`,
    {
      method: 'POST',
      // X-Widget-Host mirrors the chat endpoints: the backend validates the
      // embedding host against the widget's domain allowlist.
      headers: { 'Content-Type': 'application/json', 'X-Widget-Host': window.location.host },
      // Embedded widget never has session cookies for the parent app.
      credentials: 'omit',
    }
  )
  if (!response.ok) {
    throw new Error(`Failed to issue widget connection token: HTTP ${response.status}`)
  }
  return ConnectionTokenResponseSchema.parse(await response.json())
}

export async function fetchSubscriptionToken(
  channel: string,
  options?: { widgetId?: string; sessionId?: string; apiBaseUrl?: string; anonymous?: boolean }
): Promise<SubscriptionTokenResponse> {
  // Anonymous visitor flow: bypass httpClient (which would inject auth retry
  // logic + redirect on 401). Operators go through httpClient so cookie
  // refresh on 401 still works.
  if (options?.anonymous) {
    const base = options.apiBaseUrl ?? getApiBaseUrl()
    const response = await fetch(`${base}/api/v1/realtime/subscribe`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'omit',
      body: JSON.stringify({
        channel,
        widgetId: options.widgetId,
        sessionId: options.sessionId,
      }),
    })
    if (!response.ok) {
      throw new Error(`Failed to issue subscription token: HTTP ${response.status}`)
    }
    return SubscriptionTokenResponseSchema.parse(await response.json())
  }

  return httpClient('/api/v1/realtime/subscribe', {
    method: 'POST',
    body: JSON.stringify({ channel }),
    schema: SubscriptionTokenResponseSchema,
  })
}
