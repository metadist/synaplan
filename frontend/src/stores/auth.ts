// synaplan-ui/src/stores/auth.ts
// Cookie-based authentication store
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/authService'

export interface User {
  id: number
  email: string
  level: string
  roles?: string[]
  created?: string
  isAdmin?: boolean
  emailVerified?: boolean
}

// Promise that resolves when initial auth check is complete
let authReadyResolve: (() => void) | null = null
export const authReady = new Promise<void>((resolve) => {
  authReadyResolve = resolve
})

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref<User | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const initialized = ref(false)

  // Computed
  const isAuthenticated = computed(() => !!user.value)
  const userLevel = computed(() => user.value?.level || 'NEW')
  const isPro = computed(() => ['PRO', 'TEAM', 'BUSINESS'].includes(userLevel.value))
  const isAdmin = computed(() => user.value?.isAdmin === true || user.value?.level === 'ADMIN')

  // Actions
  async function login(email: string, password: string, recaptchaToken?: string): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      const result = await authService.login(email, password, recaptchaToken)

      if (result.success) {
        user.value = authService.getUser().value
        return true
      } else {
        error.value = result.error || 'Login failed'
        return false
      }
    } catch (err) {
      error.value = 'Network error'
      return false
    } finally {
      loading.value = false
    }
  }

  async function register(
    email: string,
    password: string,
    recaptchaToken?: string
  ): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      const result = await authService.register(email, password, recaptchaToken)

      if (result.success) {
        return true
      } else {
        error.value = result.error || 'Registration failed'
        return false
      }
    } catch (err) {
      error.value = 'Network error'
      return false
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    // Clear user immediately to prevent any auth checks during logout
    user.value = null
    loading.value = true

    try {
      await authService.logout()
    } finally {
      loading.value = false
      error.value = null
    }
  }

  async function refreshUser(): Promise<void> {
    loading.value = true
    try {
      const currentUser = await authService.getCurrentUser()
      if (currentUser) {
        user.value = currentUser
      } else {
        // Session invalid
        user.value = null
      }
    } catch (err) {
      console.error('Failed to refresh user:', err)
      user.value = null
    } finally {
      loading.value = false
    }
  }

  async function checkAuth(): Promise<void> {
    // Don't check auth multiple times
    if (initialized.value) return

    try {
      loading.value = true
      const currentUser = await authService.getCurrentUser()
      if (currentUser) {
        user.value = currentUser
      }
    } catch (err) {
      // Not authenticated or network error - that's fine, just stay logged out
      user.value = null
    } finally {
      loading.value = false
      initialized.value = true
      // Signal that auth check is complete
      if (authReadyResolve) {
        authReadyResolve()
        authReadyResolve = null
      }
    }
  }

  /**
   * Handle OAuth callback - user just returned from OAuth provider
   */
  async function handleOAuthCallback(): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      const result = await authService.handleOAuthCallback()

      if (result.success) {
        user.value = authService.getUser().value
        initialized.value = true
        // Also resolve authReady if not already done
        if (authReadyResolve) {
          authReadyResolve()
          authReadyResolve = null
        }
        return true
      } else {
        error.value = result.error || 'OAuth login failed'
        return false
      }
    } catch (err) {
      error.value = 'OAuth callback failed'
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Revoke all sessions (logout everywhere)
   */
  async function revokeAllSessions(): Promise<{ success: boolean; sessionsRevoked?: number }> {
    loading.value = true

    try {
      const result = await authService.revokeAllSessions()
      if (result.success) {
        user.value = null
      }
      return result
    } finally {
      loading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  // Reset store to initial state
  function $reset(): void {
    user.value = null
    loading.value = false
    error.value = null
    initialized.value = false
  }

  return {
    // State
    user,
    loading,
    error,
    initialized,
    // Computed
    isAuthenticated,
    userLevel,
    isPro,
    isAdmin,
    // Actions
    login,
    register,
    logout,
    refreshUser,
    checkAuth,
    handleOAuthCallback,
    revokeAllSessions,
    clearError,
    $reset,
  }
})
