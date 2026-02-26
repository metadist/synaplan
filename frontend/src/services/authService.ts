// synaplan-ui/src/services/authService.ts
// Cookie-based authentication - no localStorage at all (security)
//
// OAuth Cookie Race Condition Fix:
// When redirecting back from OAuth providers (Google, GitHub, etc.), there can be
// a race condition where cookies are set by the backend but not immediately available
// in the browser on the next request. This is especially common with SameSite=LAX cookies
// during cross-site navigation (OAuth redirects).
//
// Solution: Retry mechanism with exponential backoff in getCurrentUser() and handleOAuthCallback()
// - Up to 3 retries with delays: 200ms, 400ms, 800ms (max ~1.4s total)
// - Only triggered during OAuth callback flow
// - Falls back to normal refresh token flow if retries exhausted
import { ref } from 'vue'
import { clearSseToken } from '@/services/api/chatApi'
import { getApiBaseUrl } from '@/services/api/httpClient'

const API_BASE_URL = getApiBaseUrl()

// Auth State - only in memory, never in localStorage
// This prevents manipulation of isAdmin, level, etc.
const user = ref<any | null>(null)
const isRefreshing = ref(false)
const isLoggingOut = ref(false) // Prevent auth checks during logout
let refreshPromise: Promise<boolean> | null = null

/**
 * Session hint key in localStorage.
 * NOT security-sensitive - purely an optimization flag to avoid unnecessary
 * 401 requests (GET /auth/me + POST /auth/refresh) for users who have
 * never logged in. Set on successful auth, cleared on logout.
 */
const SESSION_HINT_KEY = 'sh'

function setSessionHint(): void {
  try {
    localStorage.setItem(SESSION_HINT_KEY, '1')
  } catch {
    // localStorage unavailable - graceful degradation
  }
}

function clearSessionHint(): void {
  try {
    localStorage.removeItem(SESSION_HINT_KEY)
  } catch {
    // localStorage unavailable - graceful degradation
  }
}

function hasSessionHint(): boolean {
  try {
    return localStorage.getItem(SESSION_HINT_KEY) === '1'
  } catch {
    // localStorage unavailable - assume session might exist to avoid breaking auth
    return true
  }
}

export const authService = {
  /**
   * Login User - cookies are set by backend
   */
  async login(
    email: string,
    password: string,
    recaptchaToken?: string
  ): Promise<{ success: boolean; error?: string }> {
    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include', // Important: include cookies
        body: JSON.stringify({ email, password, recaptchaToken }),
      })

      const data = await response.json()

      if (!response.ok) {
        return { success: false, error: data.error || 'Login failed' }
      }

      // Store user info only in memory (not localStorage for security)
      user.value = data.user
      setSessionHint()

      return { success: true }
    } catch (error) {
      console.error('Login error:', error)
      return { success: false, error: 'Network error' }
    }
  },

  /**
   * Register User
   */
  async register(
    email: string,
    password: string,
    recaptchaToken?: string
  ): Promise<{ success: boolean; error?: string }> {
    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/register`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({ email, password, recaptchaToken }),
      })

      const data = await response.json()

      if (!response.ok) {
        return { success: false, error: data.error || 'Registration failed' }
      }

      return { success: true }
    } catch (error) {
      console.error('Registration error:', error)
      return { success: false, error: 'Network error' }
    }
  },

  /**
   * Logout User - clears cookies on backend
   */
  async logout(silent = false): Promise<void> {
    // Prevent multiple logout calls and auth checks during logout
    if (isLoggingOut.value) {
      return
    }

    isLoggingOut.value = true

    let oidcLogoutUrl: string | null = null

    try {
      if (!silent) {
        const response = await fetch(`${API_BASE_URL}/api/v1/auth/logout`, {
          method: 'POST',
          credentials: 'include',
        })

        if (response.ok) {
          const data = await response.json()
          oidcLogoutUrl = data.oidc_logout_url || null
        }
      }
    } catch (error) {
      // Ignore errors during logout - session might already be expired
    } finally {
      user.value = null
      clearSseToken()
      clearSessionHint()
      isLoggingOut.value = false
    }

    // Redirect to OIDC provider logout to end the Keycloak session.
    // This must happen after clearing local state so the user is logged out
    // even if the redirect fails.
    if (oidcLogoutUrl) {
      window.location.href = oidcLogoutUrl
    }
  },

  /**
   * Get Current User - validates session via cookie
   * @param retries Number of retry attempts (used for OAuth callback race conditions)
   * @param retryDelay Initial delay between retries in ms (exponential backoff)
   */
  async getCurrentUser(retries = 0, retryDelay = 200): Promise<any | null> {
    // Don't check auth during logout process
    if (isLoggingOut.value) {
      return null
    }

    // Optimization: skip API calls for users who have never logged in.
    // OAuth callbacks pass retries > 0, so always check those.
    if (!hasSessionHint() && retries === 0) {
      return null
    }

    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/me`, {
        credentials: 'include',
      })

      if (response.status === 401) {
        // Don't try to refresh if already logging out
        if (isLoggingOut.value) {
          return null
        }

        // OAuth callback race condition: Cookies might not be available yet
        // Retry with exponential backoff before trying to refresh
        if (retries > 0) {
          console.log(
            `⏳ Auth cookies not yet available, retrying in ${retryDelay}ms (${retries} retries left)...`
          )
          await new Promise((resolve) => setTimeout(resolve, retryDelay))
          return this.getCurrentUser(retries - 1, retryDelay * 2)
        }

        // Try to refresh token (silently)
        const refreshed = await this.refreshToken()
        if (refreshed) {
          // Retry getting user (only once, no recursion)
          const retryResponse = await fetch(`${API_BASE_URL}/api/v1/auth/me`, {
            credentials: 'include',
          })
          if (retryResponse.ok) {
            const data = await retryResponse.json()
            user.value = data.user
            return data.user
          }
        }
        // Refresh failed or retry failed, clear auth silently
        await this.logout(true)
        return null
      }

      if (!response.ok) {
        return null
      }

      const data = await response.json()
      user.value = data.user
      setSessionHint()

      return data.user
    } catch (error) {
      // Network errors are expected (e.g., offline) - don't log
      return null
    }
  },

  /**
   * Refresh Access Token using Refresh Token cookie
   */
  async refreshToken(): Promise<boolean> {
    // Don't refresh during logout
    if (isLoggingOut.value) {
      return false
    }

    // Prevent multiple simultaneous refresh calls
    if (isRefreshing.value && refreshPromise) {
      return refreshPromise
    }

    isRefreshing.value = true
    refreshPromise = this._doRefresh()

    try {
      return await refreshPromise
    } finally {
      isRefreshing.value = false
      refreshPromise = null
    }
  },

  async _doRefresh(): Promise<boolean> {
    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/refresh`, {
        method: 'POST',
        credentials: 'include',
      })

      if (!response.ok) {
        // Refresh token invalid/expired (expected behavior, don't spam console)
        clearSessionHint()
        await this.logout(true) // Silent logout
        return false
      }

      const data = await response.json()

      // Update user info if provided (memory only)
      if (data.user) {
        user.value = data.user
      }

      setSessionHint()
      return true
    } catch (error) {
      // Network error - this is unexpected, log it
      console.error('Token refresh network error:', error)
      return false
    }
  },

  /**
   * Check if user is authenticated (has user info)
   */
  isAuthenticated(): boolean {
    return user.value !== null
  },

  /**
   * Get Auth Token - not available with HttpOnly cookies
   * @deprecated Use cookies instead
   */
  getToken(): string | null {
    console.warn('getToken() is deprecated - tokens are now HttpOnly cookies')
    return null
  },

  /**
   * Get Current User (reactive)
   */
  getUser() {
    return user
  },

  /**
   * Handle OAuth callback - cookies are already set by redirect
   * Uses retry mechanism to handle race conditions where cookies aren't immediately available
   */
  async handleOAuthCallback(): Promise<{ success: boolean; error?: string }> {
    try {
      // Fetch current user with retries to handle OAuth cookie race conditions
      // Retry up to 3 times with exponential backoff (200ms, 400ms, 800ms = max ~1.4s total)
      const currentUser = await this.getCurrentUser(3, 200)

      if (currentUser) {
        setSessionHint()
        return { success: true }
      }

      console.error('❌ OAuth callback failed: No user after retries')
      return { success: false, error: 'Failed to get user after OAuth' }
    } catch (error) {
      console.error('OAuth callback error:', error)
      return { success: false, error: 'OAuth callback failed' }
    }
  },

  /**
   * Revoke all sessions
   */
  async revokeAllSessions(): Promise<{ success: boolean; sessionsRevoked?: number }> {
    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/revoke-all`, {
        method: 'POST',
        credentials: 'include',
      })

      if (!response.ok) {
        return { success: false }
      }

      const data = await response.json()

      // Clear local state (memory only)
      user.value = null
      clearSessionHint()

      return { success: true, sessionsRevoked: data.sessions_revoked }
    } catch (error) {
      console.error('Revoke all sessions error:', error)
      return { success: false }
    }
  },
}
