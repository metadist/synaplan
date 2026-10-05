import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { schedulerApi, type SchedulerStatus } from '@/services/api/scheduler'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { useAuthStore } from '@/stores/auth'

/**
 * Whether this server's scheduler is running, stale, or has never run.
 *
 * The status endpoint is ROLE_ADMIN. A non-admin must never trigger a request.
 * {@link ensureLoaded} fetches once per session for the sidebar; {@link load}
 * always refreshes and is what the status card and the five-minute poll use.
 */
export const useSchedulerStore = defineStore('scheduler', () => {
  const authStore = useAuthStore()

  const status = ref<SchedulerStatus | null>(null)
  const loading = ref(false)
  const loadFailed = ref(false)

  /** True after the first attempt, success or failure, so the sidebar does not retry on every navigation. */
  const settled = ref(false)

  let inFlight: Promise<void> | null = null

  /**
   * Only an admin may read scheduler status.
   *
   * MOBILE-APP SEAM: the native app is excluded. Restarting the scheduler is a
   * server operation, so the hint would only be a dead end on a phone.
   */
  const canRead = computed(() => authStore.isAdmin && !isNativeApp())

  const isStale = computed(() => canRead.value && status.value?.state === 'stale')

  async function load(): Promise<void> {
    if (!canRead.value) return
    if (inFlight) return inFlight

    inFlight = (async () => {
      loading.value = true
      try {
        status.value = await schedulerApi.getStatus()
        loadFailed.value = false
      } catch {
        status.value = null
        loadFailed.value = true
      } finally {
        loading.value = false
        settled.value = true
        inFlight = null
      }
    })()

    return inFlight
  }

  async function ensureLoaded(): Promise<void> {
    if (!canRead.value || settled.value) return
    await load()
  }

  return {
    status,
    loading,
    loadFailed,
    canRead,
    isStale,
    load,
    ensureLoaded,
  }
})
