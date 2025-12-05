// synaplan-ui/src/services/authService.ts
import { ref } from 'vue'
import { useConfigStore } from '@/stores/config'

const config = useConfigStore()
const API_BASE_URL = config.apiBaseUrl

// Auth State
const token = ref<string | null>(localStorage.getItem('auth_token'))
const user = ref<any | null>(null)

// Load user from localStorage on init
const storedUser = localStorage.getItem('auth_user')
if (storedUser && storedUser !== 'undefined' && storedUser !== 'null') {
  try {
    user.value = JSON.parse(storedUser)
  } catch (e) {
    console.error('Failed to parse stored user:', e)
    localStorage.removeItem('auth_user')
  }
}

export const authService = {
  /**
   * Login User
   */
  async login(email: string, password: string, recaptchaToken?: string): Promise<{ success: boolean; error?: string }> {
    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password, recaptchaToken }),
      })

      const data = await response.json()

      if (!response.ok) {
        return { success: false, error: data.error || 'Login failed' }
      }

      // Store token and user
      token.value = data.token
      user.value = data.user
      localStorage.setItem('auth_token', data.token)
      localStorage.setItem('auth_user', JSON.stringify(data.user))

      return { success: true }
    } catch (error) {
      console.error('Login error:', error)
      return { success: false, error: 'Network error' }
    }
  },

  /**
   * Register User
   */
  async register(email: string, password: string, recaptchaToken?: string): Promise<{ success: boolean; error?: string }> {
    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/register`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password, recaptchaToken }),
      })

      const data = await response.json()

      if (!response.ok) {
        return { success: false, error: data.error || 'Registration failed' }
      }

      // Note: Backend now returns generic success message for security (user enumeration prevention)
      // No token or user data is returned, user must verify email first
      return { success: true }
    } catch (error) {
      console.error('Registration error:', error)
      return { success: false, error: 'Network error' }
    }
  },

  /**
   * Logout User
   */
  async logout(): Promise<void> {
    try {
      if (token.value) {
        await fetch(`${API_BASE_URL}/api/v1/auth/logout`, {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${token.value}`,
          },
        })
      }
    } catch (error) {
      console.error('Logout error:', error)
    } finally {
      // Clear local state regardless of API call success
      token.value = null
      user.value = null
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
    }
  },

  /**
   * Get Current User
   */
  async getCurrentUser(): Promise<any | null> {
    // Re-read token from localStorage in case it was updated externally
    const currentToken = token.value || localStorage.getItem('auth_token')
    
    if (!currentToken) {
      return null
    }

    // Update the ref if it was read from localStorage
    if (!token.value && currentToken) {
      token.value = currentToken
    }

    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/me`, {
        headers: {
          'Authorization': `Bearer ${currentToken}`,
        },
      })

      if (!response.ok) {
        // Token invalid, clear auth
        await this.logout()
        return null
      }

      const data = await response.json()
      user.value = data.user
      localStorage.setItem('auth_user', JSON.stringify(data.user))

      return data.user
    } catch (error) {
      console.error('Get user error:', error)
      return null
    }
  },

  /**
   * Refresh Token
   */
  async refreshToken(): Promise<boolean> {
    if (!token.value) {
      return false
    }

    try {
      const response = await fetch(`${API_BASE_URL}/api/v1/auth/refresh`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token.value}`,
        },
      })

      if (!response.ok) {
        await this.logout()
        return false
      }

      const data = await response.json()
      token.value = data.token
      localStorage.setItem('auth_token', data.token)

      return true
    } catch (error) {
      console.error('Token refresh error:', error)
      return false
    }
  },

  /**
   * Check if user is authenticated
   */
  isAuthenticated(): boolean {
    return token.value !== null
  },

  /**
   * Get Auth Token
   */
  getToken(): string | null {
    return token.value
  },

  /**
   * Get Current User (reactive)
   */
  getUser() {
    return user
  },
}

