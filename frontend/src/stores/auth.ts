// synaplan-ui/src/stores/auth.ts
// Cookie-based authentication store
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService, type AuthUser, type ImpersonatorInfo } from '@/services/authService'
import { useConfigStore } from '@/stores/config'

export type User = AuthUser
export type { ImpersonatorInfo } from '@/services/authService'

// Promise that resolves when initial auth check is complete
let authReadyResolve: (() => void) | null = null
export const authReady = new Promise<void>((resolve) => {
  authReadyResolve = resolve
})

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref<User | null>(null)
  const impersonator = ref<ImpersonatorInfo | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const initialized = ref(false)

  // Computed
  const isAuthenticated = computed(() => !!user.value)
  const userLevel = computed(() => user.value?.level || 'NEW')
  /**
   * The CURRENT principal's admin flag. While impersonating, this reflects
   * the IMPERSONATED user (typically false), so route guards correctly stop
   * the admin from navigating to /admin while in another user's shoes.
   */
  const isAdmin = computed(() => user.value?.isAdmin === true || user.value?.level === 'ADMIN')
  /**
   * True whenever an admin is operating as another user. Drives the
   * impersonation banner and unlocks admin-level error diagnostics in
   * ErrorView so the real admin always sees the full failure context.
   */
  const isImpersonating = computed(() => impersonator.value !== null)
  const isPro = computed(() => {
    const config = useConfigStore()
    return (
      !config.billing.enabled ||
      isAdmin.value ||
      ['PRO', 'TEAM', 'BUSINESS'].includes(userLevel.value)
    )
  })
  const isTeam = computed(() => {
    const config = useConfigStore()
    return (
      !config.billing.enabled || isAdmin.value || ['TEAM', 'BUSINESS'].includes(userLevel.value)
    )
  })

  /**
   * Mirror the authService's in-memory state onto the Pinia store.
   * Centralised so we can't accidentally update one and forget the other.
   */
  function syncFromAuthService(): void {
    user.value = authService.getUser().value
    impersonator.value = authService.getImpersonator().value
  }

  // Actions
  async function login(email: string, password: string, recaptchaToken?: string): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      const result = await authService.login(email, password, recaptchaToken)

      if (result.success) {
        syncFromAuthService()
        const { useGuestStore } = await import('./guest')
        useGuestStore().$reset()
        await useConfigStore().reload()
        return true
      } else {
        error.value = result.error || 'Login failed'
        return false
      }
    } catch {
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
    } catch {
      error.value = 'Network error'
      return false
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    // Clear user immediately to prevent any auth checks during logout
    user.value = null
    impersonator.value = null
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
        syncFromAuthService()
      } else {
        // Session invalid
        user.value = null
        impersonator.value = null
      }
    } catch (err) {
      console.error('Failed to refresh user:', err)
      user.value = null
      impersonator.value = null
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
        syncFromAuthService()
        // Reload config to get user-specific data like plugins
        await useConfigStore().reload()
      }
    } catch {
      // Not authenticated or network error - that's fine, just stay logged out
      user.value = null
      impersonator.value = null
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
        syncFromAuthService()
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
    } catch {
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
        impersonator.value = null
      }
      return result
    } finally {
      loading.value = false
    }
  }

  function clearError(): void {
    error.value = null
  }

  /**
   * Start impersonating another user. Delegates to the backend, then refreshes
   * the auth state from /auth/me so all reactive consumers (banner, route
   * guards, sidebar) flip atomically. Returns a typed result so the caller
   * can show success / error notifications.
   */
  async function startImpersonation(userId: number): Promise<{ success: boolean; error?: string }> {
    const { impersonationApi } = await import('@/services/api/impersonationApi')
    const result = await impersonationApi.start(userId)

    if (!result.success) {
      return { success: false, error: result.error }
    }

    // Re-fetch /auth/me so user + impersonator + level + isAdmin all reflect
    // the post-swap session in one consistent step. We also reload the config
    // store, since plugin/feature visibility is user-scoped.
    await refreshUser()
    try {
      await useConfigStore().reload()
    } catch (err) {
      // Non-fatal: the auth state is already correct, config will reload on
      // the next route navigation.
      console.warn('Config reload after impersonation start failed:', err)
    }

    return { success: true }
  }

  /**
   * Exit the active impersonation and restore the admin session. Same
   * refresh pattern as `startImpersonation`.
   */
  async function stopImpersonation(): Promise<{ success: boolean; error?: string }> {
    const { impersonationApi } = await import('@/services/api/impersonationApi')
    const result = await impersonationApi.stop()

    if (!result.success) {
      return { success: false, error: result.error }
    }

    await refreshUser()
    try {
      await useConfigStore().reload()
    } catch (err) {
      console.warn('Config reload after impersonation stop failed:', err)
    }

    return { success: true }
  }

  // Reset store to initial state
  function $reset(): void {
    user.value = null
    impersonator.value = null
    loading.value = false
    error.value = null
    initialized.value = false
  }

  return {
    // State
    user,
    impersonator,
    loading,
    error,
    initialized,
    // Computed
    isAuthenticated,
    userLevel,
    isPro,
    isTeam,
    isAdmin,
    isImpersonating,
    // Actions
    login,
    register,
    logout,
    refreshUser,
    checkAuth,
    handleOAuthCallback,
    revokeAllSessions,
    startImpersonation,
    stopImpersonation,
    clearError,
    $reset,
  }
})
