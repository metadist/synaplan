/**
 * HTTP Client - Base HTTP functionality
 */

import { useConfigStore } from '@/stores/config'

const config = useConfigStore()
const API_BASE_URL = config.apiBaseUrl

type ResponseType = 'json' | 'blob' | 'text' | 'arrayBuffer'

interface HttpClientOptions extends RequestInit {
  params?: Record<string, string>
  /** Skip adding Authorization header (for unauthenticated endpoints) */
  skipAuth?: boolean
  /** Response type to parse. Defaults to 'json' */
  responseType?: ResponseType
}

async function httpClient<T>(endpoint: string, options: HttpClientOptions = {}): Promise<T> {
  const { params, skipAuth = false, responseType = 'json', ...fetchOptions } = options

  let url = `${API_BASE_URL}${endpoint}`

  if (params) {
    const queryString = new URLSearchParams(params).toString()
    url += `?${queryString}`
  }

  const token = localStorage.getItem('auth_token')
  const headers: Record<string, string> = {
    ...(options.headers as Record<string, string>),
  }

  // Only set Content-Type for JSON if body is not FormData
  // FormData needs the browser to set Content-Type with boundary
  if (!(fetchOptions.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
  }

  if (token && !skipAuth) {
    headers['Authorization'] = `Bearer ${token}`
  }

  console.log('🌐 httpClient request:', {
    url,
    method: fetchOptions.method || 'GET',
    hasToken: !!token,
    bodyPreview: fetchOptions.body ? JSON.parse(fetchOptions.body as string) : null
  })

  const response = await fetch(url, {
    ...fetchOptions,
    headers,
  })

  console.log('🌐 httpClient response:', {
    url,
    status: response.status,
    statusText: response.statusText,
    ok: response.ok
  })

  if (!response.ok) {
    if (response.status === 401 && !skipAuth) {
      // Token invalid or expired - trigger complete logout
      console.warn('🔒 Authentication failed - logging out user')

      // Clear localStorage
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')

      // Clear all stores via router navigation (triggers store resets)
      // Use router.push instead of window.location to maintain SPA state
      const { useAuthStore } = await import('@/stores/auth')
      const { useHistoryStore } = await import('@/stores/history')
      const { useChatsStore } = await import('@/stores/chats')

      const authStore = useAuthStore()
      const historyStore = useHistoryStore()
      const chatsStore = useChatsStore()

      // Clear stores
      authStore.$reset()
      historyStore.clear()
      chatsStore.$reset()

      // Redirect to login (this will navigate away from the current page)
      window.location.href = '/login?reason=session_expired'

      // Return a never-resolving promise to prevent further execution
      // This avoids error logs in console after redirect
      return new Promise(() => {}) as Promise<T>
    }

    let errorMessage = `HTTP ${response.status}: ${response.statusText}`
    try {
      const errorData = await response.json()
      errorMessage = errorData.error || errorData.message || errorMessage
    } catch {
      // Use default error message
    }
    throw new Error(errorMessage)
  }

  // Parse response based on requested type
  switch (responseType) {
    case 'blob':
      return response.blob() as Promise<T>
    case 'text':
      return response.text() as Promise<T>
    case 'arrayBuffer':
      return response.arrayBuffer() as Promise<T>
    case 'json':
    default:
      return response.json()
  }
}

export { httpClient, API_BASE_URL }

