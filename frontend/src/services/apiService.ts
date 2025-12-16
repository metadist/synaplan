import type { AIModel } from '@/stores/models'
import { useConfigStore } from '@/stores/config'
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
const config = useConfigStore()
const API_BASE_URL = config.apiBaseUrl
const API_TIMEOUT = import.meta.env.VITE_API_TIMEOUT || 30000
const CSRF_HEADER = import.meta.env.VITE_CSRF_HEADER_NAME || 'X-CSRF-Token'

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

  const controller = new AbortController()
  const timeoutId = setTimeout(() => controller.abort(), API_TIMEOUT)

  try {
    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...requestOptions,
      headers,
      credentials: 'include', // Use HttpOnly cookies for auth
      signal: controller.signal,
    })

    clearTimeout(timeoutId)

    // 401 handling: With cookie-based auth, token refresh is automatic via httpClient
    // If we get 401 here, redirect to login
    if (response.status === 401) {
      window.location.href = '/login?reason=session_expired'
      throw new Error('Session expired')
    }

    if (!response.ok) {
      const errorText = await response.text()
      console.error('API Error Details:', errorText)
      throw new Error(`API Error: ${response.status} ${response.statusText}`)
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
        return schema.parse(data)
      } catch (error) {
        console.error('Schema validation failed:', error)
        throw error
      }
    }

    return data
  } catch (error: any) {
    if (error.name === 'AbortError') {
      throw new Error('Request timeout')
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

  async verifyEmail(token: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/verify-email', {
      method: 'POST',
      body: JSON.stringify({ token }),
    })
  },

  async forgotPassword(email: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
    })
  },

  async resetPassword(token: string, password: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify({ token, password }),
    })
  },

  // Profile Management
  async getProfile(): Promise<any> {
    return httpClient<any>('/api/v1/profile', {
      method: 'GET',
    })
  },

  async updateProfile(profileData: any): Promise<any> {
    return httpClient<any>('/api/v1/profile', {
      method: 'PUT',
      body: JSON.stringify(profileData),
    })
  },

  async changePassword(currentPassword: string, newPassword: string): Promise<any> {
    return httpClient<any>('/api/v1/profile/password', {
      method: 'PUT',
      body: JSON.stringify({ currentPassword, newPassword }),
    })
  },

  async sendMessage(userId: number, message: string, trackId?: number): Promise<any> {
    if (useMockData) {
      const { mockChatResponse } = await import('@/mocks/chatResponses')
      return new Promise((resolve) => setTimeout(() => resolve(mockChatResponse(message)), 800))
    }
    return httpClient<any>('/messages/send', {
      method: 'POST',
      body: JSON.stringify({ userId, message, trackId }),
    })
  },

  streamMessage(
    userId: number,
    message: string,
    onUpdate: (data: any) => void,
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
    fetch(`${API_BASE_URL}/auth/token`, { credentials: 'include' })
      .then((res) => res.json())
      .then(({ token }) => {
        if (!token) {
          onUpdate({ status: 'error', error: 'Authentication required' })
          return
        }

        eventSource = new EventSource(
          `${API_BASE_URL}/messages/stream?userId=${userId}&message=${encodeURIComponent(message)}&trackId=${trackId || ''}&token=${token}`
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
  get: async <T>(url: string, config?: { params?: Record<string, any> }): Promise<{ data: T }> => {
    let endpoint = url.startsWith('/') ? url : '/' + url

    if (config?.params) {
      const queryString = new URLSearchParams(
        Object.entries(config.params)
          .filter(([_, value]) => value !== undefined && value !== null)
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
    body: any,
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
