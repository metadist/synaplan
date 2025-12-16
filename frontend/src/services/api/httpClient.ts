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

// Track auth failures to prevent redirect loops
let authFailureCount = 0
let lastAuthFailureTime = 0
const AUTH_FAILURE_WINDOW_MS = 5000 // 5 second window
const MAX_AUTH_FAILURES_IN_WINDOW = 2

/**
 * Refresh result - indicates if refresh was successful and if OIDC session expired
 */
interface RefreshResult {
  success: boolean
  oidcSessionExpired?: boolean
}

/**
 * Check if we're in a potential auth failure loop
 */
function isInAuthFailureLoop(): boolean {
  const now = Date.now()

  // Reset counter if outside window
  if (now - lastAuthFailureTime > AUTH_FAILURE_WINDOW_MS) {
    authFailureCount = 0
  }

  return authFailureCount >= MAX_AUTH_FAILURES_IN_WINDOW
}

/**
 * Record an auth failure for loop detection
 */
function recordAuthFailure(): void {
  const now = Date.now()

  // Reset counter if outside window
  if (now - lastAuthFailureTime > AUTH_FAILURE_WINDOW_MS) {
    authFailureCount = 0
  }

  authFailureCount++
  lastAuthFailureTime = now
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
        // Reset failure counter on success
        authFailureCount = 0
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
 * Handle authentication failure - clear state and redirect using router (not full page reload)
 */
async function handleAuthFailure(): Promise<never> {
  console.warn('🔒 Authentication failed - logging out user')

  // Record this failure for loop detection
  recordAuthFailure()

  // Check for loop before redirecting
  if (isInAuthFailureLoop()) {
    console.error('🛑 Auth failure loop detected - not redirecting')
    throw new Error('Authentication failed (loop detected)')
  }

  const { useAuthStore } = await import('@/stores/auth')
  const { useHistoryStore } = await import('@/stores/history')
  const { useChatsStore } = await import('@/stores/chats')

  const authStore = useAuthStore()
  const historyStore = useHistoryStore()
  const chatsStore = useChatsStore()

  authStore.$reset()
  historyStore.clear()
  chatsStore.$reset()

  // Use Vue Router instead of window.location.href to avoid full page reload loops
  // Support subfolder deployments via BASE_URL (from vite.config base option)
  const loginPath = `${import.meta.env.BASE_URL}login`.replace('//', '/')

  try {
    const { default: router } = await import('@/router')
    if (!window.location.pathname.startsWith(loginPath)) {
      router.push({ name: 'login', query: { reason: 'session_expired' } })
    }
  } catch {
    if (!window.location.pathname.startsWith(loginPath)) {
      window.location.href = `${loginPath}?reason=session_expired`
    }
  }

  // Throw error to stop the request chain
  throw new Error('Authentication required')
}

async function httpClient<T>(endpoint: string, options: HttpClientOptions = {}): Promise<T> {
  const {
    params,
    skipAuth = false,
    responseType = 'json',
    _isRetry = false,
    ...fetchOptions
  } = options

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
    bodyPreview:
      fetchOptions.body && !(fetchOptions.body instanceof FormData)
        ? JSON.parse(fetchOptions.body as string)
        : null,
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
    ok: response.ok,
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
