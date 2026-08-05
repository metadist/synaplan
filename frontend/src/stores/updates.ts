import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { updatesApi, type UpdateSeverity, type UpdateStatus } from '@/services/api/updates'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { useAuthStore } from '@/stores/auth'

/**
 * Release notice: "a newer Synaplan exists".
 *
 * Display only — Synaplan never updates itself. Everything here either reads
 * the stored result of the daily check or records a display preference
 * (acknowledged version, master switch); the update itself is performed
 * manually by the operator following `guideUrl`.
 *
 * Two invariants the whole UI depends on:
 *   - The endpoints are ROLE_ADMIN, so a non-admin must never trigger a
 *     request. Every action is gated on {@link canRead}.
 *   - The status is fetched ONCE per session and cached, because the sidebar
 *     reads it on every route: {@link ensureLoaded} is idempotent and
 *     de-duplicates concurrent callers.
 *
 * The running version shown to ALL users does NOT come from here — it is the
 * public runtime config's `build.version` (useConfigStore).
 */
export const useUpdatesStore = defineStore('updates', () => {
  const authStore = useAuthStore()

  const status = ref<UpdateStatus | null>(null)
  const loading = ref(false)

  /**
   * True once an attempt finished, successfully or not. A failed check must not
   * make the sidebar retry on every navigation, so failure also counts as
   * "asked".
   */
  const loaded = ref(false)

  /** De-duplicates concurrent ensureLoaded() calls (sidebar + admin card). */
  let inFlight: Promise<void> | null = null

  /**
   * Only an admin may talk to the release-notice endpoints.
   *
   * MOBILE-APP SEAM: the native app is excluded. Updating means changing a
   * version on the server and redeploying it, which nobody can do from a phone,
   * so the notice would only ever be a dead end there.
   */
  const canRead = computed(() => authStore.isAdmin && !isNativeApp())

  const latestVersion = computed(() => status.value?.latestVersion ?? null)
  const severity = computed<UpdateSeverity>(() => status.value?.severity ?? 'normal')
  const guideUrl = computed(() => status.value?.guideUrl ?? null)
  const checkEnabled = computed(() => status.value?.checkEnabled ?? true)

  /** A newer release is known and it is not the one the admin acknowledged. */
  const showBadge = computed(() => {
    const current = status.value
    if (!current || !canRead.value) return false
    if (!current.updateAvailable || current.latestVersion === null) return false

    return current.latestVersion !== current.dismissedVersion
  })

  /**
   * Load the stored status once. Safe to call from anywhere: a non-admin, an
   * already-loaded store and a request in flight all return without a second
   * network call.
   */
  async function ensureLoaded(): Promise<void> {
    if (!canRead.value || loaded.value) return
    if (inFlight) return inFlight

    inFlight = (async () => {
      loading.value = true
      try {
        status.value = await updatesApi.getStatus()
      } catch {
        // A release notice is never important enough to break a page: without a
        // status the sidebar simply shows the plain version number.
        status.value = null
      } finally {
        loaded.value = true
        loading.value = false
        inFlight = null
      }
    })()

    return inFlight
  }

  /**
   * Manual "check now". Unlike {@link ensureLoaded} this performs an outbound
   * request on the backend, so failures are the caller's to report — a
   * transport error rejects, while an unreachable manifest comes back as a
   * normal payload carrying `lastError`.
   */
  async function checkNow(): Promise<void> {
    if (!canRead.value) return

    loading.value = true
    try {
      status.value = await updatesApi.check()
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  /** Acknowledge the currently offered release so the badge disappears. */
  async function dismissLatest(): Promise<void> {
    const version = latestVersion.value
    if (!canRead.value || version === null || status.value === null) return

    const response = await updatesApi.dismiss(version)
    status.value = { ...status.value, dismissedVersion: response.dismissedVersion ?? version }
  }

  /** Turn the periodic check on or off. */
  async function setCheckEnabled(enabled: boolean): Promise<void> {
    if (!canRead.value || status.value === null) return

    const response = await updatesApi.setCheckEnabled(enabled)
    status.value = { ...status.value, checkEnabled: response.checkEnabled }
  }

  return {
    status,
    loading,
    canRead,
    latestVersion,
    severity,
    guideUrl,
    checkEnabled,
    showBadge,
    ensureLoaded,
    checkNow,
    dismissLatest,
    setCheckEnabled,
  }
})
