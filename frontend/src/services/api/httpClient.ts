/**
 * HTTP Client - Cookie-based authentication
 * All requests include credentials for HttpOnly cookies
 */

import { useConfigStore } from '@/stores/config'

const config = useConfigStore()
const API_BASE_URL = config.apiBaseUrl

type ResponseType = 'json' | 'blob' | 'text' | 'arrayBuffer'

interface HttpClientOptions extends RequestInit {
  params?: Record<string, string>
  /** Skip auto-refresh on 401 (for auth endpoints) */
  skipAuth?: boolean
  /** Response type to parse. Defaults to 'json' */
  responseType?: ResponseType
  /** Skip retry on 401 (internal use) */
  _isRetry?: boolean
}

// Track if we're currently refreshing
let isRefreshing = false
let refreshPromise: Promise<RefreshResult> | null = null

/**
 * Refresh result - indicates if refresh was successful and if OIDC session expired
 */
interface RefreshResult {
  success: boolean
  oidcSessionExpired?: boolean
}

/**
 * Refresh access token using refresh token cookie.
 * For OIDC users, this refreshes against Keycloak.
 * If Keycloak rejects the refresh (user logged out), returns oidcSessionExpired: true
 */
async function refreshAccessToken(): Promise<RefreshResult> {
  if (isRefreshing && refreshPromise) {
    return refreshPromise as Promise<RefreshResult>
  }

  isRefreshing = true
  refreshPromise = (async (): Promise<RefreshResult> => {
    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/refresh`, {
        method: 'POST',
        credentials: 'include',
      })

      if (response.ok) {
        console.log('🔄 Token refreshed successfully')
        return { success: true }
      }

      // Check if this was an OIDC session expiry (user logged out from Keycloak)
      try {
        const errorData = await response.json()
        if (errorData.code === 'OIDC_SESSION_EXPIRED') {
          console.log('🔒 OIDC session expired - user logged out from identity provider')
          return { success: false, oidcSessionExpired: true }
        }
      } catch {
        // Ignore JSON parse errors
      }

      console.log('🔄 Token refresh failed')
      return { success: false }
    } catch (error) {
      console.error('Token refresh error:', error)
      return { success: false }
    } finally {
      isRefreshing = false
      refreshPromise = null
    }
  })()

  return refreshPromise as Promise<RefreshResult>
}

/**
 * Handle authentication failure - logout and redirect
 */
async function handleAuthFailure(): Promise<never> {
  console.warn('🔒 Authentication failed - logging out user')


  const { useAuthStore } = await import('@/stores/auth')
  const { useHistoryStore } = await import('@/stores/history')
  const { useChatsStore } = await import('@/stores/chats')

  const authStore = useAuthStore()
  const historyStore = useHistoryStore()
  const chatsStore = useChatsStore()

  authStore.$reset()
  historyStore.clear()
  chatsStore.$reset()

  // Redirect to login
  window.location.href = '/login?reason=session_expired'

  // Return never-resolving promise
  return new Promise(() => {})
}

async function httpClient<T>(endpoint: string, options: HttpClientOptions = {}): Promise<T> {
  const { params, skipAuth = false, responseType = 'json', _isRetry = false, ...fetchOptions } = options

  let url = `${API_BASE_URL}${endpoint}`

  if (params) {
    const queryString = new URLSearchParams(params).toString()
    url += `?${queryString}`
  }

  const headers: Record<string, string> = {
    ...(options.headers as Record<string, string>),
  }

  // Only set Content-Type for JSON if body is not FormData
  if (!(fetchOptions.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
  }

  // No Authorization header needed - using HttpOnly cookies
  
  console.log('🌐 httpClient request:', {
    url,
    method: fetchOptions.method || 'GET',
    bodyPreview: fetchOptions.body && !(fetchOptions.body instanceof FormData) 
      ? JSON.parse(fetchOptions.body as string) 
      : null
  })

  const response = await fetch(url, {
    ...fetchOptions,
    headers,
    credentials: 'include', // Always include cookies
  })

  console.log('🌐 httpClient response:', {
    url,
    status: response.status,
    statusText: response.statusText,
    ok: response.ok
  })

  if (!response.ok) {
    // Handle 401 - try to refresh token
    if (response.status === 401 && !skipAuth && !_isRetry) {
      console.log('🔄 Got 401, attempting token refresh...')
      
      const refreshResult = await refreshAccessToken()
      
      // If OIDC session expired (user logged out from Keycloak), immediately logout
      if (refreshResult.oidcSessionExpired) {
        console.log('🔒 OIDC provider invalidated session')
        return handleAuthFailure()
      }
      
      if (refreshResult.success) {
        // Retry the original request
        console.log('🔄 Retrying request after token refresh')
        return httpClient<T>(endpoint, { ...options, _isRetry: true })
      }
      
      // Refresh failed - logout
      return handleAuthFailure()
    }

    // Already retried or skipAuth - handle as normal error
    if (response.status === 401 && !skipAuth) {
      return handleAuthFailure()
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
