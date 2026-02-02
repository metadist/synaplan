/**
 * Server-Sent Events client for real-time widget communication.
 * 
 * Provides real-time updates without requiring an additional container.
 * Falls back to polling if SSE is not supported.
 */

import { useConfigStore } from '@/stores/config'

export interface WidgetEvent {
  id?: number
  type: string
  [key: string]: unknown
}

export interface EventSubscription {
  unsubscribe: () => void
}

export interface SubscribeOptions {
  apiUrl?: string
}

/**
 * Subscribe to session events via SSE.
 * Used by the embedded widget to receive real-time updates.
 * 
 * @param widgetId - The widget ID
 * @param sessionId - The session ID
 * @param onEvent - Callback for events
 * @param onError - Callback for errors
 * @param options - Optional settings including apiUrl override
 */
export function subscribeToSession(
  widgetId: string,
  sessionId: string,
  onEvent: (event: WidgetEvent) => void,
  onError?: (error: Error) => void,
  options?: SubscribeOptions
): EventSubscription {
  // Use provided apiUrl or fall back to config store
  let baseUrl = options?.apiUrl
  if (!baseUrl) {
    try {
      const configStore = useConfigStore()
      baseUrl = configStore.apiBaseUrl
    } catch {
      // Config store not available (widget context), use current origin
      baseUrl = window.location.origin
    }
  }
  let lastEventId = 0
  let eventSource: EventSource | null = null
  let isActive = true

  const connectSSE = () => {
    if (!isActive) return

    const url = `${baseUrl}/api/v1/widgets/${widgetId}/sessions/${sessionId}/events?lastEventId=${lastEventId}`
    
    try {
      eventSource = new EventSource(url)

      eventSource.onopen = () => {
        console.debug('[SSE] Connected to session events')
        retryCount = 0 // Reset retry count on successful connection
      }

      eventSource.addEventListener('connected', () => {
        console.debug('[SSE] Session connection confirmed')
      })

      eventSource.addEventListener('takeover', (e) => {
        const data = JSON.parse(e.data)
        lastEventId = parseInt(e.lastEventId || '0', 10)
        onEvent({ type: 'takeover', ...data })
      })

      eventSource.addEventListener('handback', (e) => {
        const data = JSON.parse(e.data)
        lastEventId = parseInt(e.lastEventId || '0', 10)
        onEvent({ type: 'handback', ...data })
      })

      eventSource.addEventListener('message', (e) => {
        const data = JSON.parse(e.data)
        lastEventId = parseInt(e.lastEventId || '0', 10)
        onEvent({ type: 'message', ...data })
      })

      eventSource.addEventListener('reconnect', (e) => {
        const data = JSON.parse(e.data)
        lastEventId = data.lastEventId || lastEventId
        // Reconnect after a delay (server requested reconnection after timeout)
        eventSource?.close()
        eventSource = null
        if (isActive) {
          console.debug('[SSE] Server requested reconnection, reconnecting in 5s')
          setTimeout(connectSSE, 5000)
        }
      })

      eventSource.onerror = (error) => {
        console.warn('[SSE] Connection error', error)
        // Retry SSE connection after a delay (no polling fallback)
        eventSource?.close()
        eventSource = null
        if (isActive) {
          retryCount++
          // Exponential backoff: 2s, 4s, 8s, 16s, max 30s
          const delay = Math.min(2000 * Math.pow(2, retryCount - 1), 30000)
          console.debug(`[SSE] Reconnecting in ${delay / 1000}s (attempt ${retryCount})`)
          setTimeout(connectSSE, delay)
        }
      }
    } catch (error) {
      console.warn('[SSE] Failed to connect', error)
      onError?.(error as Error)
    }
  }

  let retryCount = 0

  // Start with SSE
  connectSSE()

  return {
    unsubscribe: () => {
      isActive = false
      eventSource?.close()
      eventSource = null
    }
  }
}

/**
 * Subscribe to widget notifications via polling.
 * Used by the admin UI to receive notifications about new messages.
 */
export function subscribeToNotifications(
  widgetId: string,
  onNotification: (event: WidgetEvent) => void,
  getAuthToken: () => string | null
): EventSubscription {
  const configStore = useConfigStore()
  const baseUrl = configStore.apiBaseUrl
  let lastEventId = 0
  let pollingInterval: ReturnType<typeof setInterval> | null = null
  let isActive = true

  pollingInterval = setInterval(async () => {
    if (!isActive) return

    const token = getAuthToken()
    if (!token) return

    try {
      const url = `${baseUrl}/api/v1/widgets/${widgetId}/notifications?lastEventId=${lastEventId}`
      const response = await fetch(url, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      })
      const data = await response.json()

      if (data.success && data.events?.length > 0) {
        for (const event of data.events) {
          onNotification(event)
        }
        lastEventId = data.lastEventId
      }
    } catch (error) {
      console.error('[Notifications] Error fetching:', error)
    }
  }, 3000) // Poll every 3 seconds

  return {
    unsubscribe: () => {
      isActive = false
      if (pollingInterval) {
        clearInterval(pollingInterval)
        pollingInterval = null
      }
    }
  }
}
