import { ref, watch, onBeforeUnmount, type Ref } from 'vue'
import { ApiError } from '@/services/api/httpClient'
import { fetchMediaJobStatus, type MediaJobPollResult } from '@/services/api/mediaJobApi'

/** Client poll cadence — balances freshness vs. server load. */
export const MEDIA_JOB_POLL_INTERVAL_MS = 25_000

const TICK_MS = 1_000

export interface MediaJobPollState {
  /** Seconds since the last successful status fetch. */
  secondsSinceCheck: Ref<number>
  /** True while a fetch is in flight. */
  isFetching: Ref<boolean>
  /** Last fetch error (transient — next poll retries). */
  fetchError: Ref<string | null>
  /** Latest status payload from the server. */
  latest: Ref<MediaJobPollResult | null>
  /** Trigger an immediate status check (manual refresh). */
  refreshNow: () => Promise<void>
}

/**
 * Poll a background media job while `enabled` is true.
 * Fires immediately on mount, then every {@link MEDIA_JOB_POLL_INTERVAL_MS}.
 */
export function useMediaJobPoll(
  jobId: Ref<string | undefined>,
  enabled: Ref<boolean>,
  onUpdate: (status: MediaJobPollResult) => void,
  onJobLost?: () => void
): MediaJobPollState {
  const secondsSinceCheck = ref(0)
  const isFetching = ref(false)
  const fetchError = ref<string | null>(null)
  const latest = ref<MediaJobPollResult | null>(null)
  let lastCheckedAt: number | null = null
  let pollTimer: ReturnType<typeof setInterval> | null = null
  let tickTimer: ReturnType<typeof setInterval> | null = null

  const stop = () => {
    if (pollTimer !== null) {
      clearInterval(pollTimer)
      pollTimer = null
    }
    if (tickTimer !== null) {
      clearInterval(tickTimer)
      tickTimer = null
    }
  }

  const pollOnce = async () => {
    const id = jobId.value
    if (!id) return

    isFetching.value = true
    try {
      const status = await fetchMediaJobStatus(id)
      latest.value = status
      lastCheckedAt = Date.now()
      secondsSinceCheck.value = 0
      fetchError.value = null
      onUpdate(status)
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        fetchError.value = null
        onJobLost?.()
        stop()
        return
      }
      fetchError.value = err instanceof Error ? err.message : 'Poll failed'
    } finally {
      isFetching.value = false
    }
  }

  watch(
    [enabled, jobId],
    ([active, id]) => {
      stop()
      secondsSinceCheck.value = 0
      fetchError.value = null
      latest.value = null
      lastCheckedAt = null

      if (!active || !id) return

      void pollOnce()
      pollTimer = setInterval(() => void pollOnce(), MEDIA_JOB_POLL_INTERVAL_MS)
      tickTimer = setInterval(() => {
        if (lastCheckedAt !== null) {
          secondsSinceCheck.value = Math.floor((Date.now() - lastCheckedAt) / 1000)
        }
      }, TICK_MS)
    },
    { immediate: true }
  )

  onBeforeUnmount(stop)

  return { secondsSinceCheck, isFetching, fetchError, latest, refreshNow: pollOnce }
}

export function formatElapsedDuration(totalSeconds: number): string {
  const seconds = Math.max(0, Math.floor(totalSeconds))
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  const remainder = seconds % 60
  if (minutes < 60) {
    return remainder > 0 ? `${minutes}m ${remainder}s` : `${minutes}m`
  }
  const hours = Math.floor(minutes / 60)
  const mins = minutes % 60
  return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`
}
