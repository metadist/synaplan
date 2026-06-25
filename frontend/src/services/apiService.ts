import type { AIModel } from '@/stores/models'
import {
  getApiBaseUrl,
  refreshAccessToken as refreshTokenViaHttpClient,
} from '@/services/api/httpClient'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { getNativeAccessToken } from '@/services/api/nativeAuth'
import { hasSessionHint, clearSessionHint } from '@/services/sessionHint'
import type { z } from 'zod'

export interface DefaultModelConfig {
  chat: string
  pic2text: string
  sort: string
  sound2text: string
  summarize: string
  text2pic: string
  text2sound: string
  text2vid: string
  vectorize: string
}

// Base configuration
const API_TIMEOUT = import.meta.env.VITE_API_TIMEOUT || 30000
const API_UPLOAD_TIMEOUT = import.meta.env.VITE_API_UPLOAD_TIMEOUT || 120000
const CSRF_HEADER = import.meta.env.VITE_CSRF_HEADER_NAME || 'X-CSRF-Token'

// Resolved per call (not captured once): the native shell sets the real backend
// origin during bootstrap, which may run after this module is first imported.
function apiBase(): string {
  return getApiBaseUrl()
}

// Web authenticates via HttpOnly cookies (credentialed CORS); the native shell
// is cross-origin against `Access-Control-Allow-Origin: *` where credentialed
// mode is rejected, so it omits credentials and replays a Bearer token instead.
function requestCredentials(): RequestCredentials {
  return isNativeApp() ? 'omit' : 'include'
}

function withNativeAuth(headers: Record<string, string>): Record<string, string> {
  if (isNativeApp()) {
    const token = getNativeAccessToken()
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    }
  }
  return headers
}

// Token refresh stampede protection
let tokenRefreshPromise: Promise<boolean> | null = null

const publicAuthPaths = [
  '/login',
  '/register',
  '/verify-email',
  '/forgot-password',
  '/reset-password',
  '/logged-out',
]

function isOnPublicAuthPage(): boolean {
  return publicAuthPaths.some((p) => window.location.pathname.startsWith(p))
}

function redirectToSessionExpired(): void {
  if (!isOnPublicAuthPage()) {
    window.location.href = '/login?reason=session_expired'
  }
}

/**
 * Centralized token refresh - prevents concurrent refresh requests (stampede)
 *
 * Short-circuits when no session hint is present (issue #204) so that
 * unauthenticated callers never trigger an `/api/v1/auth/refresh` round-trip
 * just to get an expected 401 back.
 */
async function refreshAccessToken(): Promise<boolean> {
  // Native uses Bearer tokens — delegate to the canonical refresh which rotates
  // the secure-stored tokens. apiService's cookie refresh can't work
  // cross-origin against `Access-Control-Allow-Origin: *`.
  if (isNativeApp()) {
    const result = await refreshTokenViaHttpClient()
    return result.success
  }

  if (!hasSessionHint()) {
    return false
  }

  if (tokenRefreshPromise) {
    return tokenRefreshPromise
  }

  tokenRefreshPromise = (async () => {
    try {
      const refreshResponse = await fetch(`${apiBase()}/api/v1/auth/refresh`, {
        method: 'POST',
        credentials: 'include',
      })

      if (!refreshResponse.ok) {
        // Stored cookie is dead - drop the hint so the next call short-circuits.
        clearSessionHint()
      }
      return refreshResponse.ok
    } catch (error) {
      console.error('Token refresh error:', error)
      return false
    } finally {
      tokenRefreshPromise = null
    }
  })()

  return tokenRefreshPromise
}

interface ApiHttpClientOptions<S extends z.Schema | undefined = undefined> extends RequestInit {
  /** Zod schema for response validation */
  schema?: S
}

// HTTP client with cookie-based auth
// Note: For most use cases, prefer using @/services/api/httpClient instead

// Overload: with schema
async function httpClient<S extends z.Schema>(
  endpoint: string,
  options: ApiHttpClientOptions<S> & { schema: S }
): Promise<z.infer<S>>

// Overload: without schema (legacy)
async function httpClient<T = unknown>(
  endpoint: string,
  options?: ApiHttpClientOptions<undefined>
): Promise<T>

// Implementation
async function httpClient<T = unknown, S extends z.Schema | undefined = undefined>(
  endpoint: string,
  options: ApiHttpClientOptions<S> = {}
): Promise<T | z.infer<NonNullable<S>>> {
  const { schema, ...requestOptions } = options
  const csrfToken = sessionStorage.getItem('csrf_token')

  const headers: Record<string, string> = {}

  // Only set Content-Type if body is not FormData
  const isFormData = options.body instanceof FormData
  if (!isFormData) {
    headers['Content-Type'] = 'application/json'
  }

  // Add existing headers
  if (options.headers) {
    Object.assign(headers, options.headers)
  }

  // Add CSRF token for state-changing operations
  if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(options.method || 'GET')) {
    headers[CSRF_HEADER] = csrfToken
  }

  // Native shell: attach the Bearer access token (cookies are unavailable).
  withNativeAuth(headers)

  const isUpload = options.body instanceof FormData
  const timeout = isUpload ? API_UPLOAD_TIMEOUT : API_TIMEOUT
  const controller = new AbortController()
  const timeoutId = setTimeout(() => controller.abort(), timeout)

  try {
    const response = await fetch(`${apiBase()}${endpoint}`, {
      ...requestOptions,
      headers,
      credentials: requestCredentials(),
      signal: controller.signal,
    })

    clearTimeout(timeoutId)

    // 401 handling: Try to refresh token automatically
    if (response.status === 401) {
      const refreshSuccess = await refreshAccessToken()

      if (refreshSuccess) {
        // Retry original request with new timeout
        const retryController = new AbortController()
        const retryTimeoutId = setTimeout(() => retryController.abort(), timeout)

        try {
          // Refresh rotated the Bearer token on native — re-attach the new one.
          withNativeAuth(headers)
          const retryResponse = await fetch(`${apiBase()}${endpoint}`, {
            ...requestOptions,
            headers,
            credentials: requestCredentials(),
            signal: retryController.signal,
          })

          clearTimeout(retryTimeoutId)

          if (retryResponse.ok) {
            const data = await retryResponse.json()

            // Validate with schema if provided
            if (schema) {
              try {
                return schema.parse(data) as z.output<NonNullable<S>>
              } catch (error) {
                console.error('Schema validation failed:', error)
                throw error
              }
            }

            return data as T
          } else if (retryResponse.status === 401) {
            // Still 401 after refresh - redirect to login
            redirectToSessionExpired()
            throw new Error('Session expired')
          } else {
            let retryErrorMessage = `${retryResponse.status} ${retryResponse.statusText}`
            try {
              const errorData = JSON.parse(await retryResponse.text())
              retryErrorMessage = errorData.error || errorData.message || retryErrorMessage
              if (errorData.debug) {
                console.error(
                  `API Debug [${options.method || 'GET'} ${endpoint}]:`,
                  errorData.debug
                )
              }
            } catch {
              // response wasn't JSON
            }
            console.error(`API Error [${options.method || 'GET'} ${endpoint}]:`, retryErrorMessage)
            throw new Error(retryErrorMessage)
          }
        } catch (error) {
          clearTimeout(retryTimeoutId)
          throw error
        }
      } else {
        // Refresh failed - redirect to login
        redirectToSessionExpired()
        throw new Error('Session expired')
      }
    }

    if (!response.ok) {
      let errorMessage = `${response.status} ${response.statusText}`
      try {
        const errorData = JSON.parse(await response.text())
        errorMessage = errorData.error || errorData.message || errorMessage
        if (errorData.debug) {
          console.error(`API Debug [${options.method || 'GET'} ${endpoint}]:`, errorData.debug)
        }
      } catch {
        // response wasn't JSON
      }
      console.error(`API Error [${options.method || 'GET'} ${endpoint}]:`, errorMessage)
      throw new Error(errorMessage)
    }

    // Store new CSRF token if provided
    const newCsrfToken = response.headers.get(CSRF_HEADER)
    if (newCsrfToken) {
      sessionStorage.setItem('csrf_token', newCsrfToken)
    }

    const data = await response.json()

    // Validate with schema if provided
    if (schema) {
      try {
        return schema.parse(data) as z.output<NonNullable<S>>
      } catch (error) {
        console.error('Schema validation failed:', error)
        throw error
      }
    }

    return data as T
  } catch (error: unknown) {
    if ((error instanceof Error || error instanceof DOMException) && error.name === 'AbortError') {
      const seconds = Math.round(timeout / 1000)
      throw new Error(
        `Request timeout after ${seconds}s on ${options.method || 'GET'} ${endpoint}. ` +
          'The AI provider may be slow or unreachable. Check backend logs for details.',
        { cause: error }
      )
    }
    throw error
  }
}

// Note: Token refresh is handled automatically by @/services/api/httpClient
// This file's httpClient is kept for legacy compatibility but will use cookie-based auth

// Check if mock data is enabled
const useMockData = import.meta.env.VITE_ENABLE_MOCK_DATA === 'true'

export const apiService = {
  async fetchAvailableModels(): Promise<AIModel[]> {
    if (useMockData) {
      const { mockAvailableModels } = await import('@/mocks/aiModels')
      return mockAvailableModels
    }
    return httpClient<AIModel[]>('/api/v1/config/models')
  },

  async fetchDefaultConfig(): Promise<DefaultModelConfig> {
    if (useMockData) {
      const { mockDefaultConfig } = await import('@/mocks/aiModels')
      return mockDefaultConfig
    }
    return httpClient<DefaultModelConfig>('/api/v1/config/models/defaults')
  },

  async saveDefaultConfig(config: DefaultModelConfig): Promise<void> {
    if (useMockData) {
      console.log('Save config (mock):', config)
      return
    }
    return httpClient<void>('/api/v1/config/models/defaults', {
      method: 'POST',
      body: JSON.stringify(config),
    })
  },

  async verifyEmail(token: string): Promise<unknown> {
    return httpClient('/api/v1/auth/verify-email', {
      method: 'POST',
      body: JSON.stringify({ token }),
    })
  },

  async forgotPassword(email: string): Promise<unknown> {
    return httpClient('/api/v1/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
    })
  },

  async resetPassword(token: string, password: string): Promise<unknown> {
    return httpClient('/api/v1/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify({ token, password }),
    })
  },

  // Profile Management
  async getProfile(): Promise<unknown> {
    return httpClient('/api/v1/profile', {
      method: 'GET',
    })
  },

  async updateProfile(profileData: Record<string, unknown>): Promise<unknown> {
    return httpClient('/api/v1/profile', {
      method: 'PUT',
      body: JSON.stringify(profileData),
    })
  },

  async changePassword(currentPassword: string, newPassword: string): Promise<unknown> {
    return httpClient('/api/v1/profile/password', {
      method: 'PUT',
      body: JSON.stringify({ currentPassword, newPassword }),
    })
  },

  async sendMessage(userId: number, message: string, trackId?: number): Promise<unknown> {
    if (useMockData) {
      const { mockChatResponse } = await import('@/mocks/chatResponses')
      return new Promise((resolve) => setTimeout(() => resolve(mockChatResponse(message)), 800))
    }
    return httpClient('/messages/send', {
      method: 'POST',
      body: JSON.stringify({ userId, message, trackId }),
    })
  },

  streamMessage(
    userId: number,
    message: string,
    onUpdate: (data: Record<string, unknown>) => void,
    trackId?: number
  ): () => void {
    if (useMockData) {
      // Mock streaming
      import('@/mocks/chatResponses').then(({ mockStreamingResponse }) => {
        mockStreamingResponse(message, onUpdate)
      })
      return () => {}
    }

    // Use chatApi for SSE streaming (it handles cookie-based auth)
    // This is a legacy function - prefer using chatApi.streamMessage directly
    let eventSource: EventSource | null = null

    // Get SSE token from auth endpoint (EventSource can't send cookies)
    fetch(`${apiBase()}/auth/token`, {
      credentials: requestCredentials(),
      headers: withNativeAuth({}),
    })
      .then((res) => res.json())
      .then(({ token }) => {
        if (!token) {
          onUpdate({ status: 'error', error: 'Authentication required' })
          return
        }

        eventSource = new EventSource(
          `${apiBase()}/messages/stream?userId=${userId}&message=${encodeURIComponent(message)}&trackId=${trackId || ''}&token=${token}`
        )

        eventSource.onmessage = (event) => {
          const data = JSON.parse(event.data)
          onUpdate(data)

          if (data.status === 'complete' || data.status === 'error') {
            eventSource?.close()
          }
        }

        eventSource.onerror = () => {
          eventSource?.close()
          onUpdate({ status: 'error', error: 'Connection lost' })
        }
      })
      .catch(() => {
        onUpdate({ status: 'error', error: 'Failed to authenticate' })
      })

    return () => eventSource?.close()
  },
}

// Axios-like API client for filesService
export const api = {
  get: async <T>(
    url: string,
    config?: { params?: Record<string, unknown> }
  ): Promise<{ data: T }> => {
    let endpoint = url.startsWith('/') ? url : '/' + url

    if (config?.params) {
      const queryString = new URLSearchParams(
        Object.entries(config.params)
          .filter(([, value]) => value !== undefined && value !== null)
          .map(([key, value]) => [key, String(value)])
      ).toString()
      if (queryString) {
        endpoint += `?${queryString}`
      }
    }

    const data = await httpClient<T>(endpoint)
    return { data }
  },

  post: async <T>(
    url: string,
    body: unknown,
    config?: { headers?: Record<string, string> }
  ): Promise<{ data: T }> => {
    const endpoint = url.startsWith('/') ? url : '/' + url

    const options: RequestInit = {
      method: 'POST',
      body: body instanceof FormData ? body : JSON.stringify(body),
    }

    // Don't set Content-Type for FormData - browser adds boundary automatically
    if (!(body instanceof FormData)) {
      options.headers = {
        'Content-Type': 'application/json',
        ...config?.headers,
      }
    } else if (config?.headers) {
      options.headers = config.headers
    }

    const data = await httpClient<T>(endpoint, options)
    return { data }
  },

  delete: async <T>(url: string): Promise<{ data: T }> => {
    const endpoint = url.startsWith('/') ? url : '/' + url
    const data = await httpClient<T>(endpoint, { method: 'DELETE' })
    return { data }
  },
}
