/**
 * Native app-lifecycle handling (Epic 7.2).
 *
 * Mobile OSes freeze a backgrounded WebView: timers pause, the realtime socket
 * can die silently, and the access token may expire while the app is away. When
 * the user returns we therefore:
 *   1. re-validate the session (this transparently refreshes the Bearer token
 *      via httpClient's 401 handling), and
 *   2. nudge an existing realtime connection back to life.
 *
 * To avoid hammering the backend on quick app switches we only run this when the
 * app was backgrounded for at least RESUME_RECHECK_AFTER_MS. Web is unaffected
 * (no Capacitor App plugin, no listener registered).
 */
import { isNativeApp } from '@/services/api/nativeRuntime'

const RESUME_RECHECK_AFTER_MS = 30_000

let registered = false
let backgroundedAt: number | null = null

/** Register the resume handler once. No-op on web or if already registered. */
export async function initNativeLifecycle(): Promise<void> {
  if (registered || !isNativeApp()) {
    return
  }
  registered = true

  const { App: CapacitorApp } = await import('@capacitor/app')

  await CapacitorApp.addListener('appStateChange', ({ isActive }) => {
    if (!isActive) {
      backgroundedAt = Date.now()
      return
    }

    // Only act on a genuine resume after a meaningful time away.
    if (null === backgroundedAt) {
      return
    }
    const awayMs = Date.now() - backgroundedAt
    backgroundedAt = null
    if (awayMs >= RESUME_RECHECK_AFTER_MS) {
      void handleResume()
    }
  })
}

async function handleResume(): Promise<void> {
  // 1) Re-validate the session for authenticated users. refreshUser() hits
  //    /auth/me, which triggers a token refresh on 401 (httpClient) and clears
  //    the user if the session is truly gone.
  try {
    const { useAuthStore } = await import('@/stores/auth')
    const auth = useAuthStore()
    if (auth.isAuthenticated) {
      await auth.refreshUser()
    }
  } catch (err) {
    console.error('Resume session re-check failed', err)
  }

  // 2) Reconnect realtime if it was in use (no-op otherwise).
  try {
    const { useRealtimeStore } = await import('@/stores/realtime')
    await useRealtimeStore().reconnect()
  } catch (err) {
    console.error('Resume realtime reconnect failed', err)
  }
}
