// synaplan-ui/src/services/authService.ts
// Cookie-based authentication - no localStorage at all (security)
import { ref } from 'vue'
import { useConfigStore } from '@/stores/config'
import { clearSseToken } from '@/services/api/chatApi'

const config = useConfigStore()
const API_BASE_URL = config.apiBaseUrl

// Auth State - only in memory, never in localStorage
// This prevents manipulation of isAdmin, level, etc.
const user = ref<any | null>(null)
const isRefreshing = ref(false)
const isLoggingOut = ref(false) // Prevent auth checks during logout
let refreshPromise: Promise<boolean> | null = null

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

    try {
      // Only call logout endpoint if we're not already logged out (silent = false means user initiated)
      if (!silent) {
        await fetch(`${API_BASE_URL}/api/v1/auth/logout`, {
          method: 'POST',
          credentials: 'include',
        })
      }
    } catch (error) {
      // Ignore errors during logout - session might already be expired
    } finally {
      // Clear local state (memory only)
      user.value = null
      // Clear SSE token cache
      clearSseToken()
      // Reset logout flag after a short delay
      setTimeout(() => {
        isLoggingOut.value = false
      }, 500)
    }
  },

  /**
   * Get Current User - validates session via cookie
   */
  async getCurrentUser(): Promise<any | null> {
    // Don't check auth during logout process
    if (isLoggingOut.value) {
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
        await this.logout(true) // Silent logout
        return false
      }

      const data = await response.json()

      // Update user info if provided (memory only)
      if (data.user) {
        user.value = data.user
      }

      console.log('✅ Token refreshed successfully')
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
   */
  async handleOAuthCallback(): Promise<{ success: boolean; error?: string }> {
    try {
      // Just fetch current user - cookies were set by OAuth redirect
      const currentUser = await this.getCurrentUser()

      if (currentUser) {
        return { success: true }
      }

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

      return { success: true, sessionsRevoked: data.sessions_revoked }
    } catch (error) {
      console.error('Revoke all sessions error:', error)
      return { success: false }
    }
  },
}
