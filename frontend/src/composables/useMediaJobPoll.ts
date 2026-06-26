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

  // Once we have observed a terminal state (or a 404) for a given job id, that
  // job is DONE for this client — we must never poll it again, even if some
  // other code path momentarily flips the reactive state back to `running`.
  // This is the latch that makes polling idempotent and kills the
  // poll → reconcile → poll flicker loop. It is keyed by job id so a brand new
  // job (different id) starts fresh.
  let settledJobId: string | null = null
  let watchedJobId: string | null = null

  const TERMINAL_STATES = new Set(['done', 'failed', 'cancelled'])

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
    // Never re-poll a job we have already seen reach a terminal state.
    if (settledJobId === id) {
      stop()
      return
    }

    isFetching.value = true
    try {
      const status = await fetchMediaJobStatus(id)
      latest.value = status
      lastCheckedAt = Date.now()
      secondsSinceCheck.value = 0
      fetchError.value = null

      // Latch + stop BEFORE notifying, so the terminal update can't trigger a
      // reactive change that re-arms this poller within the same tick.
      if (TERMINAL_STATES.has(status.state)) {
        settledJobId = id
        stop()
      }
      onUpdate(status)
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        fetchError.value = null
        settledJobId = id
        stop()
        onJobLost?.()
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
      lastCheckedAt = null

      // Only reset the terminal latch when the job id actually changes — a mere
      // toggle of `enabled` (or a transient state flip) for the SAME job must
      // not resurrect a finished poller.
      if (id !== watchedJobId) {
        watchedJobId = id ?? null
        settledJobId = null
        latest.value = null
      }

      if (!active || !id || settledJobId === id) return

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
