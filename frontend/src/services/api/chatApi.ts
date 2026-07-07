/**
 * Chat API - Message & Conversation Management
 */

import { z } from 'zod'
import { httpClient, getApiBaseUrl } from './httpClient'
import { isNativeApp } from './nativeRuntime'
import { getNativeAccessToken, hasNativeTokens } from './nativeAuth'
import { UserMemorySchema } from './userMemoriesApi'
import { hasSessionHint, clearSessionHint } from '@/services/sessionHint'
import type { StreamUpdatePayload } from '@/types/chatStream'

/**
 * Map a recorder MIME type to an audio file extension the backend accepts and
 * routes to the transcription path.
 *
 * Safari/macOS cannot record `audio/webm` and falls back to `audio/mp4`, but
 * an MP4-audio recording must be named `.m4a` (not `.mp4`): the backend treats
 * `mp4` as a video type, and external STT APIs key off the filename extension,
 * so a mislabeled upload silently fails to transcribe. Everything here maps to
 * an extension in the backend's audio set (`ogg/mp3/wav/m4a/opus/flac/webm`).
 */
const AUDIO_MIME_TO_EXTENSION: Record<string, string> = {
  'audio/webm': 'webm',
  'audio/ogg': 'ogg',
  'audio/mp4': 'm4a',
  'audio/x-m4a': 'm4a',
  'audio/aac': 'm4a',
  'audio/mpeg': 'mp3',
  'audio/mp3': 'mp3',
  'audio/wav': 'wav',
  'audio/x-wav': 'wav',
  'audio/flac': 'flac',
}

/**
 * Derive a recording filename from a blob's MIME type (params like
 * `;codecs=opus` are stripped). Defaults to `webm` for Chrome/Firefox/opus.
 */
export const audioRecordingFilename = (mimeType: string | undefined): string => {
  const subtype = (mimeType ?? '').split(';')[0].trim().toLowerCase()

  return `recording.${AUDIO_MIME_TO_EXTENSION[subtype] ?? 'webm'}`
}

/**
 * SSE auth differs by platform: web sends the session cookie, native sends the
 * stored Bearer token with cookies omitted (cross-origin). The short-lived
 * `/auth/token` value is then handed to `EventSource` via the `?token=` query
 * param (EventSource can't set headers).
 */
function sseTokenFetchInit(): RequestInit {
  if (isNativeApp()) {
    const token = getNativeAccessToken()
    return {
      credentials: 'omit',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    }
  }
  return { credentials: 'include' }
}

/** Native has an SSE-capable session when a Bearer token is stored. */
function hasSseSessionHint(): boolean {
  return isNativeApp() ? hasNativeTokens() : hasSessionHint()
}

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
  if (!hasSseSessionHint()) {
    return null
  }

  // Prevent multiple simultaneous fetches
  if (tokenFetchPromise) {
    return tokenFetchPromise
  }

  tokenFetchPromise = (async () => {
    try {
      const response = await fetch(`${getApiBaseUrl()}/api/v1/auth/token`, sseTokenFetchInit())

      // Handle 401 - try to refresh access token first
      if (response.status === 401) {
        if (import.meta.env.DEV) {
          console.debug('🔄 SSE token fetch got 401 - attempting token refresh')
        }
        const refreshSuccess = await refreshAccessToken()

        if (refreshSuccess) {
          // Retry original request after successful refresh
          const retryResponse = await fetch(
            `${getApiBaseUrl()}/api/v1/auth/token`,
            sseTokenFetchInit()
          )

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
    warmSseTokenViaRefresh()
  }, SSE_TOKEN_PROACTIVE_REFRESH_MS)
}

/**
 * Background-warm the SSE token cache without producing console noise.
 *
 * Refreshes the access-token cookie FIRST, then fetches a fresh SSE token.
 * The access cookie TTL (5 min) is shorter than the ~3.5 min proactive cycle
 * and also expires while a tab sits in the background, so warming via
 * `GET /auth/token` alone hits an expired cookie and the browser logs a 401
 * to the console every cycle (#1140). The refresh endpoint authenticates via
 * the longer-lived, non-rotating refresh cookie, so it succeeds even when the
 * access token has expired and silently renews the access cookie before the
 * token fetch. When there is no session, `refreshAccessToken()` short-circuits
 * to `false` and `getSseToken()` returns `null` — no requests, no errors.
 */
function warmSseTokenViaRefresh(): void {
  void refreshAccessToken()
    .then(() => getSseToken())
    .catch(() => {
      // Network blip — next call will retry. Don't crash the page.
    })
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
  // Refresh the access cookie before warming so a focus/visibility prefetch
  // after a long idle period does not log a 401 for an expired cookie (#1140).
  warmSseTokenViaRefresh()
}

/**
 * Clear cached SSE token and abort any in-flight fetch/refresh.
 *
 * Must be called on every principal swap (login, logout, impersonation
 * start/stop) so the next stream request fetches a fresh token for the
 * new authenticated user.
 */
export function clearSseToken(): void {
  cachedSseToken = null
  tokenFetchPromise = null
  tokenRefreshPromise = null
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

/**
 * One prior turn of an incognito conversation. The backend has no stored chat
 * to load context from, so the frontend ships the in-memory transcript
 * (oldest first) with every stream request; the server caps it at ~30
 * entries / 15k chars (mirror of its regular chat-history budget).
 */
export interface IncognitoHistoryEntry {
  role: 'user' | 'assistant'
  content: string
}

/**
 * POST-based SSE transport for /api/v1/messages/stream.
 *
 * EventSource is GET-only, so the legacy transport carried the whole message
 * (plus the auth token) in the URL. Long pasted texts blew the proxy
 * request-line limit (HTTP 431) before the backend ever saw the request, and
 * the old onerror handler masked that as a silent "complete" — the user got
 * an empty bubble. Sending the parameters as a JSON body removes the length
 * limit, keeps the message text and token out of access logs, and lets us
 * report connection failures as real errors.
 *
 * Auth mirrors sseTokenFetchInit(): web sends the session cookie, native
 * sends the stored Bearer token. On a 401 (expired 5-min access cookie) we
 * refresh once and retry.
 *
 * Returns a cleanup function that aborts the request. Aborting only detaches
 * the client — the backend keeps streaming and persists the result
 * (detach-on-navigation, #1225); an explicit Stop goes through /stop-stream.
 */
function openStreamPost(
  body: Record<string, string | IncognitoHistoryEntry[]>,
  onUpdate: (data: StreamUpdatePayload) => void
): () => void {
  const controller = new AbortController()
  let completionReceived = false
  let isStopped = false

  const authInit = sseTokenFetchInit()
  const doFetch = () =>
    fetch(`${getApiBaseUrl()}/api/v1/messages/stream`, {
      method: 'POST',
      credentials: authInit.credentials,
      headers: {
        ...(authInit.headers ?? {}),
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
      },
      body: JSON.stringify(body),
      signal: controller.signal,
    })

  const processEvent = (eventChunk: string) => {
    const jsonStr = eventChunk
      .split('\n')
      .filter((line) => line.startsWith('data:'))
      .map((line) => line.slice(5).trim())
      .filter(Boolean)
      .join('')

    if (!jsonStr) return

    try {
      const data = JSON.parse(jsonStr) as StreamUpdatePayload
      completionReceived = completionReceived || data.status === 'complete'
      onUpdate(data)
    } catch (error) {
      console.error('Failed to parse SSE data:', error, 'Raw data:', jsonStr)
    }
  }

  ;(async () => {
    try {
      let response = await doFetch()

      // Expired access cookie: refresh once, then retry. Mirrors the 401
      // handling the old EventSource path did via /auth/token.
      if (response.status === 401) {
        const refreshed = await refreshAccessToken()
        if (refreshed && !isStopped) {
          response = await doFetch()
        }
      }

      if (isStopped) return

      if (!response.ok) {
        console.error(`🚫 Stream connection failed (HTTP ${response.status})`)
        onUpdate(
          response.status === 401
            ? {
                status: 'error',
                error: 'Authentication required. Please log in again to continue.',
                message: 'Your session has expired. Please refresh the page and log in again.',
              }
            : { status: 'error', error: `Connection failed (HTTP ${response.status})` }
        )
        return
      }

      if (!response.body) {
        onUpdate({ status: 'error', error: 'Streaming not supported by this browser' })
        return
      }

      const reader = response.body.getReader()
      const decoder = new TextDecoder()
      let buffer = ''

      try {
        while (true) {
          const { done, value } = await reader.read()
          if (done) break

          buffer += decoder.decode(value, { stream: true })
          const events = buffer.split('\n\n')
          buffer = events.pop() ?? ''

          for (const eventChunk of events) {
            if (isStopped) return
            processEvent(eventChunk)
          }
        }

        if (!isStopped && buffer.trim() !== '') {
          processEvent(buffer)
        }
      } finally {
        reader.cancel().catch(() => {
          // ignore cancellation errors
        })
      }

      // Stream closed without a terminal event: the backend always ends a
      // turn with 'complete' or 'error', so this is a dropped connection or
      // a crashed worker — surface it instead of leaving an empty bubble.
      if (!completionReceived && !isStopped) {
        console.error('❌ Stream ended without completion event')
        onUpdate({ status: 'error', error: 'Connection interrupted' })
      }
    } catch (error) {
      if (isStopped || (error instanceof DOMException && error.name === 'AbortError')) {
        return
      }
      console.error('🚫 Stream setup error:', error)
      onUpdate({ status: 'error', error: 'Failed to connect' })
    }
  })()

  return () => {
    isStopped = true
    controller.abort()
  }
}

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
    /** Optional in incognito mode — no chat row exists on the server. */
    chatId?: number
    onUpdate: (data: StreamUpdatePayload) => void
    includeReasoning?: boolean
    webSearch?: boolean
    modelId?: number
    fileIds?: number[]
    voiceReply?: boolean
    isAgain?: boolean
    continueMessageId?: number
    ragGroupKey?: string
    quotedText?: string
    quotedMessageId?: number
    /** Incognito mode: the turn is processed fully in-memory on the server. */
    incognito?: boolean
    /** Incognito only: the in-memory transcript (oldest first) for context. */
    history?: IncognitoHistoryEntry[]
  }): () => void {
    const paramsObj: Record<string, string | IncognitoHistoryEntry[]> = {
      message: opts.message,
      userId: opts.userId.toString(),
    }

    if (opts.chatId) paramsObj.chatId = opts.chatId.toString()
    if (opts.incognito) {
      paramsObj.incognito = '1'
      paramsObj.history = opts.history ?? []
    }
    if (opts.trackId) paramsObj.trackId = opts.trackId.toString()
    if (opts.includeReasoning) paramsObj.reasoning = '1'
    if (opts.webSearch) paramsObj.webSearch = '1'
    if (opts.modelId) paramsObj.modelId = opts.modelId.toString()
    if (opts.voiceReply) paramsObj.voiceReply = '1'
    if (opts.isAgain) paramsObj.isAgain = '1'
    if (opts.continueMessageId) paramsObj.continueMessageId = opts.continueMessageId.toString()
    if (opts.ragGroupKey) paramsObj.ragGroupKey = opts.ragGroupKey
    if (opts.quotedText) paramsObj.quotedText = opts.quotedText
    if (opts.quotedMessageId) paramsObj.quotedMessageId = opts.quotedMessageId.toString()

    if (opts.fileIds && opts.fileIds.length > 0) {
      paramsObj.fileIds = opts.fileIds.join(',')
    }

    // POST transport: parameters travel in the JSON body, so long pasted
    // texts never hit URL length limits and no auth token leaks into the URL.
    return openStreamPost(paramsObj, opts.onUpdate)
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
   * Issue #1070: fetch a single persisted message in the same row shape as
   * `getChatMessages`. Called after SSE `complete` to reconcile the
   * live-streamed state against the authoritative persisted version
   * (files, media, metadata).
   */
  async getMessage(messageId: number): Promise<unknown> {
    return httpClient(`/api/v1/messages/${messageId}`, {
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
    signal?: AbortSignal,
    options?: {
      /**
       * Incognito session upload: the backend marks the file ephemeral so it
       * never surfaces in file listings and is deleted after the session.
       */
      incognito?: boolean
    }
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
    if (options?.incognito) {
      formData.append('incognito', '1')
    }

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
    filename?: string,
    options?: { incognito?: boolean }
  ): Promise<{
    success: boolean
    file_id: number
    filename: string
    text?: string
    language?: string
    duration?: number
  }> {
    // Derive the extension from the actual recording MIME so Safari/macOS
    // (audio/mp4) uploads as `.m4a` and stays on the transcription path,
    // instead of always claiming `.webm`.
    const resolvedFilename = filename ?? audioRecordingFilename(audioBlob.type)

    const formData = new FormData()
    formData.append('file', audioBlob, resolvedFilename)
    if (options?.incognito) {
      formData.append('incognito', '1')
    }

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
   * Stop streaming for a guest session — guest counterpart of stopStream().
   *
   * Uses the public /api/v1/guest/stop-stream endpoint (authorized by the
   * server-issued session id) via plain fetch instead of httpClient, so a 401
   * can never trigger the "session expired" login redirect for guests
   * (issue #1037). Needed since detach-on-navigation (#1225): closing the
   * EventSource alone no longer cancels the backend turn.
   */
  async stopGuestStream(guestSessionId: string, trackId: number): Promise<void> {
    const response = await fetch(`${getApiBaseUrl()}/api/v1/guest/stop-stream`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sessionId: guestSessionId, trackId }),
    })
    if (!response.ok) {
      throw new Error(`Failed to stop guest stream (HTTP ${response.status})`)
    }
  },

  /**
   * Cancel a single multitask media node (per-card Stop button) without
   * stopping the rest of the turn.
   */
  async cancelTask(trackId: number, nodeId: string): Promise<{ success: boolean }> {
    return httpClient<{ success: boolean }>('/api/v1/messages/cancel-node', {
      method: 'POST',
      body: JSON.stringify({ trackId: String(trackId), nodeId }),
    })
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
    quotedText?: string
    quotedMessageId?: number
  }): () => void {
    const paramsObj: Record<string, string> = {
      message: opts.message,
      chatId: opts.chatId.toString(),
      guestSession: opts.guestSessionId,
    }

    if (opts.trackId) paramsObj.trackId = opts.trackId.toString()
    if (opts.quotedText) paramsObj.quotedText = opts.quotedText
    if (opts.quotedMessageId) paramsObj.quotedMessageId = opts.quotedMessageId.toString()

    // Same POST transport as streamMessage: the guest session id authorizes
    // the request via the guestSession body property.
    return openStreamPost(paramsObj, opts.onUpdate)
  },
}
