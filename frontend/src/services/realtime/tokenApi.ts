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
 * runs cross-origin and must NOT depend on cookie-based auth or schema
 * validation that assume a logged-in user.
 */

import { httpClient, getApiBaseUrl } from '@/services/api/httpClient'
import type { ConnectionTokenResponse, SubscriptionTokenResponse } from './types'

export async function fetchOperatorConnectionToken(): Promise<ConnectionTokenResponse> {
  return httpClient<ConnectionTokenResponse>('/api/v1/realtime/token', {
    method: 'POST',
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
      headers: { 'Content-Type': 'application/json' },
      // Embedded widget never has session cookies for the parent app.
      credentials: 'omit',
    }
  )
  if (!response.ok) {
    throw new Error(`Failed to issue widget connection token: HTTP ${response.status}`)
  }
  return (await response.json()) as ConnectionTokenResponse
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
    return (await response.json()) as SubscriptionTokenResponse
  }

  return httpClient<SubscriptionTokenResponse>('/api/v1/realtime/subscribe', {
    method: 'POST',
    body: JSON.stringify({ channel }),
  })
}
