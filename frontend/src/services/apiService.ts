import type { AIModel } from '@/stores/models'

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
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000'
const API_TIMEOUT = import.meta.env.VITE_API_TIMEOUT || 30000
const AUTH_TOKEN_KEY = import.meta.env.VITE_AUTH_TOKEN_KEY || 'auth_token'
const REFRESH_TOKEN_KEY = import.meta.env.VITE_REFRESH_TOKEN_KEY || 'refresh_token'
const CSRF_HEADER = import.meta.env.VITE_CSRF_HEADER_NAME || 'X-CSRF-Token'

// Token management
let isRefreshing = false
let refreshSubscribers: ((token: string) => void)[] = []

const onTokenRefreshed = (token: string) => {
  refreshSubscribers.forEach(callback => callback(token))
  refreshSubscribers = []
}

const addRefreshSubscriber = (callback: (token: string) => void) => {
  refreshSubscribers.push(callback)
}

// HTTP client with auth & CSRF
async function httpClient<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const token = localStorage.getItem(AUTH_TOKEN_KEY)
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

  // Add auth token
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  // Add CSRF token for state-changing operations
  if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(options.method || 'GET')) {
    headers[CSRF_HEADER] = csrfToken
  }

  const controller = new AbortController()
  const timeoutId = setTimeout(() => controller.abort(), API_TIMEOUT)

  try {
    const response = await fetch(`${API_BASE_URL}${endpoint}`, {
      ...options,
      headers,
      signal: controller.signal,
    })

    clearTimeout(timeoutId)

    // Handle 401 - Token expired
    if (response.status === 401 && token && !isRefreshing) {
      return handleTokenRefresh(endpoint, options)
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

    return await response.json()
  } catch (error: any) {
    if (error.name === 'AbortError') {
      throw new Error('Request timeout')
    }
    throw error
  }
}

// Token refresh logic
async function handleTokenRefresh<T>(
  endpoint: string,
  options: RequestInit
): Promise<T> {
  if (!isRefreshing) {
    isRefreshing = true
    
    try {
      const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY)
      if (!refreshToken) {
        throw new Error('No refresh token')
      }

      // Call refresh endpoint
      const response = await fetch(`${API_BASE_URL}/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refreshToken })
      })

      if (!response.ok) {
        throw new Error('Token refresh failed')
      }

      const { token: newToken, refreshToken: newRefreshToken } = await response.json()
      
      localStorage.setItem(AUTH_TOKEN_KEY, newToken)
      if (newRefreshToken) {
        localStorage.setItem(REFRESH_TOKEN_KEY, newRefreshToken)
      }

      isRefreshing = false
      onTokenRefreshed(newToken)

      // Retry original request
      return httpClient(endpoint, options)
    } catch (error) {
      isRefreshing = false
      localStorage.removeItem(AUTH_TOKEN_KEY)
      localStorage.removeItem(REFRESH_TOKEN_KEY)
      window.location.href = '/login'
      throw error
    }
  } else {
    // Wait for token refresh to complete
    return new Promise((resolve) => {
      addRefreshSubscriber(() => {
        resolve(httpClient(endpoint, options))
      })
    })
  }
}

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
      body: JSON.stringify(config)
    })
  },

  async verifyEmail(token: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/verify-email', {
      method: 'POST',
      body: JSON.stringify({ token })
    })
  },

  async forgotPassword(email: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email })
    })
  },

  async resetPassword(token: string, password: string): Promise<any> {
    return httpClient<any>('/api/v1/auth/reset-password', {
      method: 'POST',
      body: JSON.stringify({ token, password })
    })
  },

  // Profile Management
  async getProfile(): Promise<any> {
    return httpClient<any>('/api/v1/profile', {
      method: 'GET'
    })
  },

  async updateProfile(profileData: any): Promise<any> {
    return httpClient<any>('/api/v1/profile', {
      method: 'PUT',
      body: JSON.stringify(profileData)
    })
  },

  async changePassword(currentPassword: string, newPassword: string): Promise<any> {
    return httpClient<any>('/api/v1/profile/password', {
      method: 'PUT',
      body: JSON.stringify({ currentPassword, newPassword })
    })
  },

  async sendMessage(userId: number, message: string, trackId?: number): Promise<any> {
    if (useMockData) {
      const { mockChatResponse } = await import('@/mocks/chatResponses')
      return new Promise(resolve => setTimeout(() => resolve(mockChatResponse(message)), 800))
    }
    return httpClient<any>('/messages/send', {
      method: 'POST',
      body: JSON.stringify({ userId, message, trackId })
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

    // Get token for SSE
    const token = localStorage.getItem('auth_token')
    const headers: Record<string, string> = {}
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    }

    // Note: EventSource doesn't support headers directly, need to use fetch + streaming
    // For now, use query param or implement custom SSE with fetch
    const eventSource = new EventSource(
      `${API_BASE_URL}/messages/stream?userId=${userId}&message=${encodeURIComponent(message)}&trackId=${trackId || ''}`
    )

    eventSource.onmessage = (event) => {
      const data = JSON.parse(event.data)
      onUpdate(data)
      
      if (data.status === 'complete' || data.status === 'error') {
        eventSource.close()
      }
    }

    eventSource.onerror = () => {
      eventSource.close()
      onUpdate({ status: 'error', error: 'Connection lost' })
    }

    return () => eventSource.close()
  }
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

  post: async <T>(url: string, body: any, config?: { headers?: Record<string, string> }): Promise<{ data: T }> => {
    const endpoint = url.startsWith('/') ? url : '/' + url
    
    const options: RequestInit = {
      method: 'POST',
      body: body instanceof FormData ? body : JSON.stringify(body)
    }

    // Don't set Content-Type for FormData - browser adds boundary automatically
    if (!(body instanceof FormData)) {
      options.headers = {
        'Content-Type': 'application/json',
        ...config?.headers
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
  }
}

