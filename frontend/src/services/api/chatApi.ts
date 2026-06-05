/**
 * Chat API - Message & Conversation Management
 */

import { z } from 'zod'
import { httpClient, getApiBaseUrl } from './httpClient'
import { UserMemorySchema } from './userMemoriesApi'
import { hasSessionHint, clearSessionHint } from '@/services/sessionHint'
import type { StreamUpdatePayload } from '@/types/chatStream'

/**
 * Phase 2c: response shape of `GET /api/v1/messages/{id}/memories`.
 *
 * Hand-written Zod schema (matches the OpenAPI annotation on
 * `MessageController::getExtractedMemories()` and the JSON written by
 * `ExtractMemoriesCommandHandler::writeOutcomeMeta()`). Once
 * `make -C frontend generate-schemas` learns to walk the new annotation
 * we'll swap this for the generated `Get*MemoriesResponseSchema` alias —
 * until then this matches the project's API convention of validating
 * every JSON response with Zod via `httpClient({ schema })`.
 *
 * `saved` and `delete_suggestions` are arrays of `UserMemoryDTO::toArray()`
 * payloads, which is exactly what `UserMemorySchema` (defined in
 * `userMemoriesApi.ts`) describes. Extra DTO keys (`userId`, `active`)
 * are stripped by Zod's default object semantics — fine, the chat UI
 * doesn't read them.
 */
export const GetExtractedMemoriesResponseSchema = z.object({
  status: z.enum(['pending', 'empty', 'complete']),
  completed_at: z.number().nullable(),
  saved: z.array(UserMemorySchema),
  delete_suggestions: z.array(UserMemorySchema),
})

export type GetExtractedMemoriesResponse = z.infer<typeof GetExtractedMemoriesResponseSchema>

// SSE token configuration
// Note: SSE_TOKEN_EXPIRY_MS = 5 * 60 * 1000 (5 minutes, backend setting)
const SSE_TOKEN_REFRESH_MS = 4 * 60 * 1000 // Refresh 1 minute before expiry

// Phase 1d: how long before the cache expiry we proactively re-fetch the
// token in the background. Picking 30s gives us a ~3.5 min "always-warm"
// window so the user-visible streamMessage() never waits on a token
// round-trip.
const SSE_TOKEN_PROACTIVE_REFRESH_MS = SSE_TOKEN_REFRESH_MS - 30 * 1000

// Cache SSE token (needed because EventSource can't send cookies)
let cachedSseToken: string | null = null
let tokenFetchPromise: Promise<string | null> | null = null
let tokenRefreshPromise: Promise<boolean> | null = null
let tokenExpiryTimer: ReturnType<typeof setTimeout> | null = null
let tokenProactiveTimer: ReturnType<typeof setTimeout> | null = null

/**
 * Centralized token refresh - prevents concurrent refresh requests (stampede)
 *
 * Short-circuits when no session hint is present (issue #204): without
 * this guard a guest hitting any chat surface would unconditionally fire
 * `POST /api/v1/auth/refresh`, log a misleading "session expired" error
 * and force-redirect to `/login?reason=session_expired`.
 */
async function refreshAccessToken(): Promise<boolean> {
  if (!hasSessionHint()) {
    return false
  }

  if (tokenRefreshPromise) {
    return tokenRefreshPromise
  }

  tokenRefreshPromise = (async () => {
    try {
      const refreshResponse = await fetch(`${getApiBaseUrl()}/api/v1/auth/refresh`, {
        method: 'POST',
        credentials: 'include',
      })

      if (refreshResponse.ok) {
        // Clear cached token so it gets refetched
        cachedSseToken = null
        return true
      }

      // Server rejected the refresh - the cookie is gone. Clear the hint
      // so subsequent calls short-circuit instead of repeating the dance.
      clearSessionHint()
      return false
    } catch {
      return false
    } finally {
      tokenRefreshPromise = null
    }
  })()

  return tokenRefreshPromise
}

/**
 * Get access token for SSE (EventSource can't send cookies)
 * Handles 401 with automatic token refresh
 * Throws error if authentication fails (triggers redirect to login)
 *
 * Short-circuits cleanly when no session hint is present (issue #204):
 * unauthenticated callers — guests, page warm-ups, anything pre-login —
 * get `null` back without firing any network requests or console errors.
 * The misleading "Token refresh failed - session expired" log only
 * surfaces when there genuinely WAS a session that just expired.
 */
async function getSseToken(): Promise<string | null> {
  // Return cached token if available
  if (cachedSseToken) {
    return cachedSseToken
  }

  // Issue #204: never bootstrap auth for callers that have no session.
  if (!hasSessionHint()) {
    return null
  }

  // Prevent multiple simultaneous fetches
  if (tokenFetchPromise) {
    return tokenFetchPromise
  }

  tokenFetchPromise = (async () => {
    try {
      const response = await fetch(`${getApiBaseUrl()}/api/v1/auth/token`, {
        credentials: 'include',
      })

      // Handle 401 - try to refresh access token first
      if (response.status === 401) {
        if (import.meta.env.DEV) {
          console.debug('🔄 SSE token fetch got 401 - attempting token refresh')
        }
        const refreshSuccess = await refreshAccessToken()

        if (refreshSuccess) {
          // Retry original request after successful refresh
          const retryResponse = await fetch(`${getApiBaseUrl()}/api/v1/auth/token`, {
            credentials: 'include',
          })

          if (retryResponse.ok) {
            const data = await retryResponse.json()
            cacheSseToken(data.token)

            return cachedSseToken
          }

          // Refresh succeeded but token fetch failed - auth issue
          console.error(
            '🔒 Token refresh succeeded but SSE token fetch failed - authentication expired'
          )
          throw new Error('Authentication required')
        } else {
          // Refresh failed - session expired
          console.error('🔒 Token refresh failed - session expired')
          throw new Error('Authentication required')
        }
      }

      if (!response.ok) {
        console.error('❌ SSE token fetch failed:', response.status)
        return null
      }

      const data = await response.json()
      cacheSseToken(data.token)

      return cachedSseToken
    } catch (error) {
      // If error is "Authentication required", redirect to login
      if (error instanceof Error && error.message === 'Authentication required') {
        // Trigger auth failure handling (redirect to login)
        window.location.href = `/login?reason=session_expired`
      }
      return null
    } finally {
      tokenFetchPromise = null
    }
  })()

  return tokenFetchPromise
}

/**
 * Cache the SSE token and schedule both:
 *   - hard expiry: drop from cache so the next call refetches
 *   - proactive refresh: 30s before hard expiry, kick off a background
 *     refetch so the cache is always warm for the user-visible
 *     `streamMessage()` call. Phase 1d optimization — the previous code
 *     waited until cache miss and made the user wait one extra HTTP RTT
 *     per message after the 4-minute window expired.
 */
function cacheSseToken(token: string | null): void {
  cachedSseToken = token

  if (tokenExpiryTimer) {
    clearTimeout(tokenExpiryTimer)
    tokenExpiryTimer = null
  }
  if (tokenProactiveTimer) {
    clearTimeout(tokenProactiveTimer)
    tokenProactiveTimer = null
  }

  if (!token) {
    return
  }

  tokenExpiryTimer = setTimeout(() => {
    cachedSseToken = null
    tokenExpiryTimer = null
  }, SSE_TOKEN_REFRESH_MS)

  // Schedule a proactive background refresh well before the hard expiry.
  // Don't await — we want this to happen silently while the user keeps
  // chatting. If the page is hidden, skip; we'll refresh on next visibility.
  tokenProactiveTimer = setTimeout(() => {
    tokenProactiveTimer = null
    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
      return
    }
    cachedSseToken = null
    void getSseToken().catch(() => {
      // Network blip — next call will retry. Don't crash the page.
    })
  }, SSE_TOKEN_PROACTIVE_REFRESH_MS)
}

/**
 * Prefetch the SSE token without waiting for the result.
 *
 * Call from app boot and on window focus to keep the cache warm so the
 * user-visible `streamMessage()` call never spends a round-trip on auth.
 */
export function prefetchSseToken(): void {
  if (cachedSseToken || tokenFetchPromise) {
    return
  }
  void getSseToken().catch(() => {
    // Silent — this is a best-effort warm-up.
  })
}

/**
 * Clear cached SSE token (call on logout)
 */
export function clearSseToken(): void {
  cachedSseToken = null
  if (tokenExpiryTimer) {
    clearTimeout(tokenExpiryTimer)
    tokenExpiryTimer = null
  }
  if (tokenProactiveTimer) {
    clearTimeout(tokenProactiveTimer)
    tokenProactiveTimer = null
  }
}

export type { StreamUpdatePayload }

export type GuestStreamCallback = (data: StreamUpdatePayload) => void

export const chatApi = {
  async sendMessage(userId: number, message: string, trackId?: number): Promise<unknown> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient('/api/v1/messages/send', {
      method: 'POST',
      body: JSON.stringify({ userId, message, trackId }),
    })
  },

  async getConversations(userId: number): Promise<unknown> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient(`/api/v1/conversations/${userId}`, {
      method: 'GET',
    })
  },

  async getMessages(conversationId: number): Promise<unknown> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient(`/api/v1/conversations/${conversationId}/messages`, {
      method: 'GET',
    })
  },

  streamMessage(opts: {
    userId: number
    message: string
    trackId?: number
    chatId: number
    onUpdate: (data: StreamUpdatePayload) => void
    includeReasoning?: boolean
    webSearch?: boolean
    modelId?: number
    fileIds?: number[]
    voiceReply?: boolean
    isAgain?: boolean
    continueMessageId?: number
    ragGroupKey?: string
  }): () => void {
    const paramsObj: Record<string, string> = {
      message: opts.message,
      chatId: opts.chatId.toString(),
      userId: opts.userId.toString(),
    }

    if (opts.trackId) paramsObj.trackId = opts.trackId.toString()
    if (opts.includeReasoning) paramsObj.reasoning = '1'
    if (opts.webSearch) paramsObj.webSearch = '1'
    if (opts.modelId) paramsObj.modelId = opts.modelId.toString()
    if (opts.voiceReply) paramsObj.voiceReply = '1'
    if (opts.isAgain) paramsObj.isAgain = '1'
    if (opts.continueMessageId) paramsObj.continueMessageId = opts.continueMessageId.toString()
    if (opts.ragGroupKey) paramsObj.ragGroupKey = opts.ragGroupKey

    if (opts.fileIds && opts.fileIds.length > 0) {
      paramsObj.fileIds = opts.fileIds.join(',')
    }

    const params = new URLSearchParams(paramsObj)
    const baseUrl = `${getApiBaseUrl()}/api/v1/messages/stream?${params}`

    let eventSource: EventSource | null = null
    let completionReceived = false
    let isStopped = false // Flag to prevent processing after manual stop

    // Get SSE token and start stream (async IIFE, but return cleanup sync)
    ;(async () => {
      try {
        if (isStopped) return

        const token = await getSseToken()
        if (!token) {
          console.error('🚫 No SSE token available - authentication required')
          opts.onUpdate({
            status: 'error',
            error: 'Authentication required. Please log in again to continue.',
            message: 'Your session has expired. Please refresh the page and log in again.',
          })
          return
        }

        const url = `${baseUrl}&token=${token}`

        if (isStopped) return

        // Open EventSource directly - no preflight check!
        // The preflight fetch was causing duplicate messages because it triggered
        // the backend to save the user message, and then EventSource did it again.
        // Rate limit errors are now handled via SSE error events from backend.
        eventSource = new EventSource(url)

        eventSource.onopen = () => {
          console.log('✅ SSE connection opened')
        }

        eventSource.onmessage = (event) => {
          // CRITICAL: Don't process any events after stop
          if (isStopped) {
            console.log('⏹️ Ignoring SSE event - stream was stopped')
            return
          }

          try {
            const data = JSON.parse(event.data) as StreamUpdatePayload
            completionReceived = data.status === 'complete'
            opts.onUpdate(data)

            // Close connection on completion
            if (data.status === 'complete') {
              eventSource?.close()
            } else if (data.status === 'error') {
              eventSource?.close()
            }
          } catch (error) {
            console.error('Failed to parse SSE data:', error, 'Raw data:', event.data)
          }
        }

        eventSource.onerror = () => {
          // Don't process errors after manual stop
          if (isStopped) {
            console.log('⏹️ Ignoring SSE error - stream was stopped')
            return
          }

          console.log(
            'SSE error event received, readyState:',
            eventSource?.readyState,
            'completionReceived:',
            completionReceived
          )

          // If we already received completion, this is just normal stream end
          if (completionReceived) {
            console.log('✅ Stream ended after completion (normal)')
            eventSource?.close()
            return
          }

          // SSE CLOSED (2) or CONNECTING (0) - Connection closed by server
          if (
            eventSource?.readyState === EventSource.CLOSED ||
            eventSource?.readyState === EventSource.CONNECTING
          ) {
            console.log('⚠️ SSE connection closed by server (treating as completion)')
            eventSource?.close()
            opts.onUpdate({ status: 'complete', message: 'Response complete', metadata: {} })
            return
          }

          // SSE OPEN (1) - Only treat as error if still open and something went wrong
          if (eventSource?.readyState === EventSource.OPEN) {
            console.error('❌ SSE connection error during active stream')
            eventSource?.close()
            opts.onUpdate({ status: 'error', error: 'Connection interrupted' })
          }
        }
      } catch (error) {
        console.error('🚫 Stream setup error:', error)
        opts.onUpdate({ status: 'error', error: 'Failed to connect' })
      }
    })()

    // Return cleanup function that sets the stop flag and closes connection
    return () => {
      console.log('🛑 Closing EventSource and setting stop flag')
      isStopped = true
      eventSource?.close()
    }
  },

  async getHistory(limit = 50, trackId?: number): Promise<unknown> {
    const params = new URLSearchParams({ limit: limit.toString() })
    if (trackId) {
      params.append('trackId', trackId.toString())
    }
    return httpClient(`/api/v1/messages/history?${params}`, { method: 'GET' })
  },

  async enhanceMessage(text: string): Promise<{ original: string; enhanced: string }> {
    return httpClient<{ original: string; enhanced: string }>('/api/v1/messages/enhance', {
      method: 'POST',
      body: JSON.stringify({ text }),
    })
  },

  async getChatMessages(chatId: number, offset = 0, limit = 50): Promise<unknown> {
    return httpClient(`/api/v1/chats/${chatId}/messages?offset=${offset}&limit=${limit}`, {
      method: 'GET',
    })
  },

  /**
   * Phase 2c: poll the backgrounded memory extraction outcome for a message.
   *
   * Returns `{ status: 'pending' | 'empty' | 'complete', saved, delete_suggestions }`.
   * The frontend calls this once or twice after SSE `complete` to pick up
   * memories the worker extracted asynchronously.
   *
   * Response is validated at runtime against
   * {@link GetExtractedMemoriesResponseSchema}.
   */
  async getExtractedMemories(messageId: number): Promise<GetExtractedMemoriesResponse> {
    return httpClient(`/api/v1/messages/${messageId}/memories`, {
      method: 'GET',
      schema: GetExtractedMemoriesResponseSchema,
    })
  },

  /**
   * Upload file for chat message (File wird sofort hochgeladen und extrahiert)
   * For audio files, response includes transcribed text
   */
  async uploadChatFile(
    file: File,
    signal?: AbortSignal
  ): Promise<{
    success: boolean
    file_id: number
    filename: string
    size: number
    mime: string
    file_type: string
    status: string
    extracted_text_length: number
    extraction_error?: 'audio_transcription_failed' | 'document_extraction_failed'
    extraction_strategy?: string
    text?: string
    language?: string
    duration?: number
  }> {
    const formData = new FormData()
    formData.append('file', file)

    return httpClient('/api/v1/messages/upload-file', {
      method: 'POST',
      body: formData,
      signal,
    })
  },

  /**
   * Upload audio for transcription with WhisperCPP
   * Returns transcribed text that can be inserted into chat input
   */
  async transcribeAudio(
    audioBlob: Blob,
    filename = 'recording.webm'
  ): Promise<{
    success: boolean
    file_id: number
    filename: string
    text?: string
    language?: string
    duration?: number
  }> {
    const formData = new FormData()
    formData.append('file', audioBlob, filename)

    return httpClient('/api/v1/messages/upload-file', {
      method: 'POST',
      body: formData,
    })
  },

  /**
   * Stop streaming - notify backend to stop streaming
   */
  async stopStream(trackId?: number): Promise<{ success: boolean; message: string }> {
    console.log('📡 chatApi.stopStream called with trackId:', trackId)
    try {
      const result = await httpClient<{ success: boolean; message: string }>(
        '/api/v1/messages/stop-stream',
        {
          method: 'POST',
          body: JSON.stringify({ trackId }),
        }
      )
      console.log('📡 chatApi.stopStream response:', result)
      return result
    } catch (error) {
      console.error('📡 chatApi.stopStream error:', error)
      throw error
    }
  },

  /**
   * Stream a guest message (no auth token, uses guestSession query param).
   */
  streamGuestMessage(opts: {
    guestSessionId: string
    message: string
    chatId: number
    trackId?: number
    onUpdate: (data: StreamUpdatePayload) => void
  }): () => void {
    const paramsObj: Record<string, string> = {
      message: opts.message,
      chatId: opts.chatId.toString(),
      guestSession: opts.guestSessionId,
    }

    if (opts.trackId) paramsObj.trackId = opts.trackId.toString()

    const params = new URLSearchParams(paramsObj)
    const url = `${getApiBaseUrl()}/api/v1/messages/stream?${params}`

    let eventSource: EventSource | null = null
    let completionReceived = false
    let isStopped = false

    eventSource = new EventSource(url)

    eventSource.onmessage = (event) => {
      if (isStopped) return

      try {
        const data = JSON.parse(event.data) as StreamUpdatePayload
        completionReceived = data.status === 'complete'
        opts.onUpdate(data)

        if (data.status === 'complete' || data.status === 'error') {
          eventSource?.close()
        }
      } catch (error) {
        console.error('Failed to parse guest SSE data:', error)
      }
    }

    eventSource.onerror = () => {
      if (isStopped) return

      if (completionReceived) {
        eventSource?.close()
        return
      }

      if (
        eventSource?.readyState === EventSource.CLOSED ||
        eventSource?.readyState === EventSource.CONNECTING
      ) {
        eventSource?.close()
        opts.onUpdate({ status: 'complete', message: 'Response complete', metadata: {} })
        return
      }

      if (eventSource?.readyState === EventSource.OPEN) {
        eventSource?.close()
        opts.onUpdate({ status: 'error', error: 'Connection interrupted' })
      }
    }

    return () => {
      isStopped = true
      eventSource?.close()
    }
  },
}
