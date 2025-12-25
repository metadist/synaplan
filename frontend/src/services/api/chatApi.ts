/**
 * Chat API - Message & Conversation Management
 */

import { httpClient, getApiBaseUrl } from './httpClient'

// SSE token configuration
// Note: SSE_TOKEN_EXPIRY_MS = 5 * 60 * 1000 (5 minutes, backend setting)
const SSE_TOKEN_REFRESH_MS = 4 * 60 * 1000 // Refresh 1 minute before expiry

// Cache SSE token (needed because EventSource can't send cookies)
let cachedSseToken: string | null = null
let tokenFetchPromise: Promise<string | null> | null = null
let tokenRefreshPromise: Promise<boolean> | null = null
let tokenExpiryTimer: ReturnType<typeof setTimeout> | null = null

/**
 * Centralized token refresh - prevents concurrent refresh requests (stampede)
 */
async function refreshAccessToken(): Promise<boolean> {
  // If already refreshing, wait for that promise
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

      return false
    } catch (error) {
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
 */
async function getSseToken(): Promise<string | null> {
  // Return cached token if available
  if (cachedSseToken) {
    return cachedSseToken
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
        const refreshSuccess = await refreshAccessToken()

        if (refreshSuccess) {
          // Retry original request after successful refresh
          const retryResponse = await fetch(`${getApiBaseUrl()}/api/v1/auth/token`, {
            credentials: 'include',
          })

          if (!retryResponse.ok) {
            return null
          }

          const data = await retryResponse.json()
          cachedSseToken = data.token

          // Clear any existing timer first
          if (tokenExpiryTimer) {
            clearTimeout(tokenExpiryTimer)
          }
          tokenExpiryTimer = setTimeout(() => {
            cachedSseToken = null
            tokenExpiryTimer = null
          }, SSE_TOKEN_REFRESH_MS)

          return cachedSseToken
        } else {
          return null
        }
      }

      if (!response.ok) {
        return null
      }

      const data = await response.json()
      cachedSseToken = data.token

      // Clear any existing timer first
      if (tokenExpiryTimer) {
        clearTimeout(tokenExpiryTimer)
      }
      tokenExpiryTimer = setTimeout(() => {
        cachedSseToken = null
        tokenExpiryTimer = null
      }, SSE_TOKEN_REFRESH_MS)

      return cachedSseToken
    } catch (error) {
      return null
    } finally {
      tokenFetchPromise = null
    }
  })()

  return tokenFetchPromise
}

/**
 * Clear cached SSE token (call on logout)
 */
export function clearSseToken(): void {
  cachedSseToken = null
}

export const chatApi = {
  async sendMessage(userId: number, message: string, trackId?: number): Promise<any> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient<any>('/api/v1/messages/send', {
      method: 'POST',
      body: JSON.stringify({ userId, message, trackId }),
    })
  },

  async getConversations(userId: number): Promise<any> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient<any>(`/api/v1/conversations/${userId}`, {
      method: 'GET',
    })
  },

  async getMessages(conversationId: number): Promise<any> {
    // Mock data temporarily disabled - direct backend communication
    return httpClient<any>(`/api/v1/conversations/${conversationId}/messages`, {
      method: 'GET',
    })
  },

  streamMessage(
    userId: number,
    message: string,
    trackId: number | undefined,
    chatId: number,
    onUpdate: (data: any) => void,
    includeReasoning: boolean = false,
    webSearch: boolean = false,
    modelId?: number,
    fileIds?: number[]
  ): () => void {
    // Build params object
    const paramsObj: Record<string, string> = {
      message,
      chatId: chatId.toString(),
      userId: userId.toString(),
    }

    if (trackId) paramsObj.trackId = trackId.toString()
    if (includeReasoning) paramsObj.reasoning = '1'
    if (webSearch) paramsObj.webSearch = '1'
    if (modelId) paramsObj.modelId = modelId.toString()

    // Multiple fileIds as comma-separated list
    if (fileIds && fileIds.length > 0) {
      paramsObj.fileIds = fileIds.join(',')
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
          console.error('🚫 No SSE token available')
          onUpdate({ status: 'error', error: 'Authentication required' })
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
            const data = JSON.parse(event.data)
            completionReceived = data.status === 'complete'
            onUpdate(data)

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
            onUpdate({ status: 'complete', message: 'Response complete', metadata: {} })
            return
          }

          // SSE OPEN (1) - Only treat as error if still open and something went wrong
          if (eventSource?.readyState === EventSource.OPEN) {
            console.error('❌ SSE connection error during active stream')
            eventSource?.close()
            onUpdate({ status: 'error', error: 'Connection interrupted' })
          }
        }
      } catch (error) {
        console.error('🚫 Stream setup error:', error)
        onUpdate({ status: 'error', error: 'Failed to connect' })
      }
    })()

    // Return cleanup function that sets the stop flag and closes connection
    return () => {
      console.log('🛑 Closing EventSource and setting stop flag')
      isStopped = true
      eventSource?.close()
    }
  },

  async getHistory(limit = 50, trackId?: number): Promise<any> {
    const params = new URLSearchParams({ limit: limit.toString() })
    if (trackId) {
      params.append('trackId', trackId.toString())
    }
    return httpClient<any>(`/api/v1/messages/history?${params}`, { method: 'GET' })
  },

  async enhanceMessage(text: string): Promise<{ original: string; enhanced: string }> {
    return httpClient<{ original: string; enhanced: string }>('/api/v1/messages/enhance', {
      method: 'POST',
      body: JSON.stringify({ text }),
    })
  },

  async getChatMessages(chatId: number, offset = 0, limit = 50): Promise<any> {
    return httpClient<any>(`/api/v1/chats/${chatId}/messages?offset=${offset}&limit=${limit}`, {
      method: 'GET',
    })
  },

  /**
   * Upload file for chat message (File wird sofort hochgeladen und extrahiert)
   * For audio files, response includes transcribed text
   */
  async uploadChatFile(file: File): Promise<{
    success: boolean
    file_id: number
    filename: string
    size: number
    mime: string
    file_type: string
    text?: string
    language?: string
    duration?: number
  }> {
    const formData = new FormData()
    formData.append('file', file)

    return httpClient<any>('/api/v1/messages/upload-file', {
      method: 'POST',
      body: formData,
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

    return httpClient<any>('/api/v1/messages/upload-file', {
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
}
