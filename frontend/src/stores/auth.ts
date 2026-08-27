// synaplan-ui/src/stores/auth.ts
// Cookie-based authentication store
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService, type AuthUser, type ImpersonatorInfo } from '@/services/authService'
import { useConfigStore } from '@/stores/config'
import { clearPendingRedirect } from '@/utils/pendingAuthRedirect'
import { redeemPendingIapPurchaseAfterAuth } from '@/services/iapPostAuthRedemption'

export type User = AuthUser
export type { ImpersonatorInfo } from '@/services/authService'

// Promise that resolves when initial auth check is complete
let authReadyResolve: (() => void) | null = null
export const authReady = new Promise<void>((resolve) => {
  authReadyResolve = resolve
})

/** Cross-tab principal marker (#623). HttpOnly cookies are shared; this key
 * lets other tabs notice when login/logout changed the session owner. */
const AUTH_PRINCIPAL_KEY = 'synaplan_auth_principal'
const AUTH_PRINCIPAL_CHANNEL = 'synaplan_auth_principal'

type AuthPrincipalPayload = { userId: number | null; ts: number }

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref<User | null>(null)
  const impersonator = ref<ImpersonatorInfo | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const initialized = ref(false)

  let applyingRemotePrincipal = false
  let principalChannel: BroadcastChannel | null = null

  function currentPrincipalId(): number | null {
    return user.value?.id ?? null
  }

  function publishPrincipal(userId: number | null = currentPrincipalId()) {
    if (typeof window === 'undefined' || applyingRemotePrincipal) return
    const payload: AuthPrincipalPayload = { userId, ts: Date.now() }
    try {
      localStorage.setItem(AUTH_PRINCIPAL_KEY, JSON.stringify(payload))
    } catch {
      /* ignore quota / private mode */
    }
    try {
      principalChannel?.postMessage(payload)
    } catch {
      /* ignore */
    }
  }

  async function applyRemotePrincipal(nextUserId: number | null) {
    if (applyingRemotePrincipal) return
    if (nextUserId === currentPrincipalId()) return
    applyingRemotePrincipal = true
    try {
      await resetUserScopedClientState()
      const currentUser = await authService.getCurrentUser()
      if (currentUser) {
        syncFromAuthService()
        await useConfigStore().reload()
      } else {
        user.value = null
        impersonator.value = null
      }
    } catch (err) {
      console.warn('Cross-tab auth sync failed', err)
      user.value = null
      impersonator.value = null
    } finally {
      applyingRemotePrincipal = false
    }
  }

  function setupCrossTabAuthSync() {
    if (typeof window === 'undefined') return
    if (typeof BroadcastChannel !== 'undefined') {
      try {
        principalChannel = new BroadcastChannel(AUTH_PRINCIPAL_CHANNEL)
        principalChannel.onmessage = (event: MessageEvent<AuthPrincipalPayload>) => {
          const nextId = event.data?.userId ?? null
          void applyRemotePrincipal(nextId)
        }
      } catch {
        principalChannel = null
      }
    }
    window.addEventListener('storage', (event: StorageEvent) => {
      if (event.key !== AUTH_PRINCIPAL_KEY || event.newValue == null) return
      try {
        const payload = JSON.parse(event.newValue) as AuthPrincipalPayload
        void applyRemotePrincipal(payload.userId ?? null)
      } catch {
        /* ignore malformed */
      }
    })
  }

  setupCrossTabAuthSync()

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
    publishPrincipal()
  }

  /**
   * Wipe any per-user client state that would otherwise survive a principal
   * swap (login, logout, start/stop impersonation, auth failure, revoke-all).
   *
   * Issue #999: `activeChatId` is persisted in localStorage under
   * `synaplan_active_chat_id`. Without this reset, an admin's last chat id
   * leaks into the impersonated user's session, ChatView tries to load it,
   * the backend returns 404 because the impersonated user does not own the
   * chat, and the UI surfaces "Der Chat existiert nicht mehr".
   *
   * Stores are loaded via dynamic import to avoid a circular dependency:
   * the chats store imports the auth service which is initialised from this
   * store.
   */
  async function resetUserScopedClientState(): Promise<void> {
    const [
      { useChatsStore },
      { useHistoryStore },
      { clearSseToken },
      { useMemoriesStore },
      { useFeedbackStore },
    ] = await Promise.all([
      import('./chats'),
      import('./history'),
      import('@/services/api/chatApi'),
      import('./userMemories'),
      import('./userFeedback'),
    ])
    useChatsStore().$reset()
    useHistoryStore().clear()
    useMemoriesStore().$reset()
    useFeedbackStore().$reset()
    clearSseToken()
  }

  /**
   * Tear down the realtime client and its per-user subscriptions on a principal
   * swap (impersonation start/stop). Unlike `logout()`, the two impersonation
   * paths previously kept the Centrifugo client alive, so a subscription minted
   * for the *previous* principal's `user:{id}` channel kept 403-ing forever
   * against the new access cookie (#1381). Dropping the client here forces a
   * fresh connection + subscription for the new principal.
   */
  async function teardownRealtimeState(): Promise<void> {
    const [{ useMediaJobsStore }, { useRealtimeStore }] = await Promise.all([
      import('./mediaJobs'),
      import('@/stores/realtime'),
    ])
    useMediaJobsStore().unsubscribe()
    await useRealtimeStore().disconnect()
  }

  /** Re-open the per-user realtime subscription for the current principal. */
  async function resubscribeRealtimeState(): Promise<void> {
    const userId = user.value?.id
    if (userId == null || userId <= 0) return
    const { useMediaJobsStore } = await import('./mediaJobs')
    await useMediaJobsStore().subscribe(userId)
  }

  // Actions
  async function login(email: string, password: string, recaptchaToken?: string): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      const result = await authService.login(email, password, recaptchaToken)

      if (result.success) {
        await resetUserScopedClientState()
        syncFromAuthService()
        const { useGuestStore } = await import('./guest')
        useGuestStore().$reset()
        await useConfigStore().reload()
        // MOBILE-APP SEAM: link a signed-out store purchase to the freshly
        // authenticated account (no-op unless a redemption is pending).
        void redeemPendingIapPurchaseAfterAuth()
        return true
      } else {
        error.value = result.error || 'Login failed'
        return false
      }
    } catch (err) {
      console.error('Login failed after successful auth:', err)
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

  async function logout(silent = false): Promise<void> {
    // Clear user immediately to prevent any auth checks during logout
    user.value = null
    impersonator.value = null
    publishPrincipal(null)
    loading.value = true
    // Drop any in-flight deep-link intent so the next login isn't hijacked
    // by a stale entry from this session.
    clearPendingRedirect()

    // Wipe all user-scoped client state (SSE token, chats, memories, etc.)
    // Non-fatal: session teardown must proceed even if cleanup fails.
    try {
      await resetUserScopedClientState()
    } catch (cleanupErr) {
      console.warn('User state cleanup failed during logout', cleanupErr)
    }

    // Tear down the realtime client before the server-side session is
    // invalidated. Otherwise the client keeps trying to refresh its
    // connection token with a stale (or absent) auth cookie, generating
    // a noisy reconnect loop until the page reloads.
    //
    // Dynamic import keeps `auth.ts` free of a static dependency on the
    // realtime store (which would force every login screen to bundle
    // centrifuge-js).
    try {
      const { useRealtimeStore } = await import('@/stores/realtime')
      await useRealtimeStore().disconnect()
    } catch (realtimeErr) {
      console.warn('Realtime disconnect failed during logout', realtimeErr)
    }

    try {
      await authService.logout(silent)
    } finally {
      loading.value = false
      error.value = null
    }
  }

  /**
   * Mirror a session that was opened outside `login()` — the setup wizard
   * signs the new administrator in via cookies, then calls this so the rest
   * of the SPA (and the completion-screen navigation) see a signed-in user.
   */
  function adoptCurrentSession(): void {
    syncFromAuthService()
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
        try {
          await resetUserScopedClientState()
        } catch (cleanupErr) {
          console.warn('User state cleanup failed during OAuth callback', cleanupErr)
        }
        syncFromAuthService()
        initialized.value = true
        // Also resolve authReady if not already done
        if (authReadyResolve) {
          authReadyResolve()
          authReadyResolve = null
        }
        // MOBILE-APP SEAM: link a signed-out store purchase to the freshly
        // authenticated account (no-op unless a redemption is pending).
        void redeemPendingIapPurchaseAfterAuth()
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
        try {
          await resetUserScopedClientState()
        } catch (cleanupErr) {
          console.warn('User state cleanup failed during session revocation', cleanupErr)
        }
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
    const [
      { impersonationApi },
      { beginAuthMutation, endAuthMutation, getInFlightRefresh, refreshAccessToken },
    ] = await Promise.all([
      import('@/services/api/impersonationApi'),
      import('@/services/api/httpClient'),
    ])

    // Guard the cookie-swap window (impersonate response -> /auth/me read)
    // against the automatic 401 -> /auth/refresh path. A refresh that fires
    // here still carries the admin's pre-swap cookies (the impersonation stash
    // is not set yet), so the backend mints a REGULAR admin token that clobbers
    // the impersonation cookie and /auth/me then reports no impersonator — the
    // banner never mounts. The lock makes any concurrent 401 wait for the swap
    // and retry with the new cookie instead of racing it.
    beginAuthMutation()
    try {
      // Let a refresh that started *before* the lock settle first, so our
      // impersonate response is guaranteed to be the last writer of the cookie.
      const inFlight = getInFlightRefresh()
      if (inFlight) {
        try {
          await inFlight
        } catch {
          // A failed background refresh must not abort the swap.
        }
      }

      // Mint a fresh admin access token as the guaranteed LAST writer before
      // the swap. impersonationApi.start() is a raw fetch with no 401-retry, so
      // without this it would fail outright if the admin's short-lived access
      // cookie had already expired and no refresh happened to be in flight.
      // Bypass the lock we hold — no competing refresh can run right now.
      // Best-effort: on failure the impersonate call surfaces the real error.
      await refreshAccessToken({ bypassMutationLock: true })

      const result = await impersonationApi.start(userId)
      if (!result.success) {
        return { success: false, error: result.error }
      }

      // Drop any chat / message state the admin had loaded before we swap the
      // principal. The persisted `activeChatId` belongs to the admin and would
      // 404 against the impersonated user (#999). Doing this before
      // refreshUser() guarantees that whatever route re-renders next sees a
      // clean slate.
      try {
        await resetUserScopedClientState()
        await teardownRealtimeState()
      } catch (cleanupErr) {
        console.warn('User state cleanup failed during impersonation start', cleanupErr)
      }

      // Re-fetch /auth/me so user + impersonator + level + isAdmin all reflect
      // the post-swap session in one consistent step.
      await refreshUser()
    } finally {
      // Release before the non-critical follow-ups below: config reload and the
      // realtime resubscribe issue their own httpClient requests and must be
      // able to refresh normally again.
      endAuthMutation()
    }

    // Reload the config store, since plugin/feature visibility is user-scoped.
    try {
      await useConfigStore().reload()
    } catch (err) {
      // Non-fatal: the auth state is already correct, config will reload on
      // the next route navigation.
      console.warn('Config reload after impersonation start failed:', err)
    }

    // Re-open realtime for the now-impersonated principal.
    try {
      await resubscribeRealtimeState()
    } catch (realtimeErr) {
      console.warn('Realtime resubscribe after impersonation start failed:', realtimeErr)
    }

    return { success: true }
  }

  /**
   * Exit the active impersonation and restore the admin session. The
   * cookie-swap window matches `startImpersonation` (mutation lock, token
   * refresh, API, user-state reset, `/auth/me`). Config reload and realtime
   * resubscribe are fire-and-forget so the caller can `router.push('/admin')`
   * as soon as the session is restored — those follow-ups must not delay the
   * landing page past the banner hide (`refreshUser` clears `impersonator`).
   */
  async function stopImpersonation(): Promise<{ success: boolean; error?: string }> {
    const [
      { impersonationApi },
      { beginAuthMutation, endAuthMutation, getInFlightRefresh, refreshAccessToken },
    ] = await Promise.all([
      import('@/services/api/impersonationApi'),
      import('@/services/api/httpClient'),
    ])

    // Symmetric to startImpersonation: while exiting, a concurrent refresh
    // still carrying the impersonation cookies (stash present) would be
    // impersonation-aware and re-mint an impersonation token, clobbering the
    // admin session the exit endpoint just restored. Guard the swap window.
    beginAuthMutation()
    try {
      const inFlight = getInFlightRefresh()
      if (inFlight) {
        try {
          await inFlight
        } catch {
          // A failed background refresh must not abort the swap.
        }
      }

      // Keep the session alive as the last writer before exiting: the exit call
      // is a raw fetch with no 401-retry, so a fresh (still impersonation-aware)
      // token guarantees it can authenticate even if the current access cookie
      // just expired. Bypass the lock we hold; best-effort.
      await refreshAccessToken({ bypassMutationLock: true })

      const result = await impersonationApi.stop()
      if (!result.success) {
        return { success: false, error: result.error }
      }

      // Symmetric to startImpersonation: the chat state currently in memory
      // belongs to the impersonated user and must not bleed back into the
      // admin's own session (#999).
      try {
        await resetUserScopedClientState()
        await teardownRealtimeState()
      } catch (cleanupErr) {
        console.warn('User state cleanup failed during impersonation stop', cleanupErr)
      }

      await refreshUser()
    } finally {
      endAuthMutation()
    }

    // Config reload + realtime resubscribe are user-scoped follow-ups, not
    // part of the session swap. Awaiting them here delayed onExit's
    // router.push('/admin') until after the banner already hid (refreshUser
    // clears impersonator). CI then waited 15s for view-admin on the chat
    // page and timed out. Kick them off without blocking the admin landing.
    void useConfigStore()
      .reload()
      .catch((err) => {
        console.warn('Config reload after impersonation stop failed:', err)
      })
    void resubscribeRealtimeState().catch((realtimeErr) => {
      console.warn('Realtime resubscribe after impersonation stop failed:', realtimeErr)
    })

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
    adoptCurrentSession,
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
