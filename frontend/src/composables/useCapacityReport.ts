import { onMounted, onUnmounted, ref, type Ref } from 'vue'
import { ApiError } from '@/services/api/httpClient'

export interface CapacityReportState<T> {
  report: Ref<T | null>
  loading: Ref<boolean>
  unavailable: Ref<boolean>
  failed: Ref<boolean>
  /** Reads again unless a read is already running. */
  load: () => Promise<void>
  /** Reads again now; an answer to an earlier read is dropped. */
  refresh: () => Promise<void>
}

/**
 * One capacity report, read on mount and every `pollMs` while the tab is
 * open. One poll at a time; an answer that is not the latest is dropped, so
 * a read started before a change never overwrites what the change showed.
 * A 503 means the shared cache does not answer, anything else is a failure.
 */
export function useCapacityReport<T>(
  fetcher: () => Promise<T>,
  pollMs = 30_000
): CapacityReportState<T> {
  const report = ref<T | null>(null) as Ref<T | null>
  const loading = ref(false)
  const unavailable = ref(false)
  const failed = ref(false)
  let timer: ReturnType<typeof setInterval> | null = null
  let seq = 0
  let running = 0

  async function read(): Promise<void> {
    const mine = ++seq
    running++
    loading.value = true
    try {
      const next = await fetcher()
      if (mine !== seq) return
      report.value = next
      unavailable.value = false
      failed.value = false
    } catch (error) {
      if (mine !== seq) return
      unavailable.value = error instanceof ApiError && error.status === 503
      failed.value = !unavailable.value
    } finally {
      running--
      if (running === 0) loading.value = false
    }
  }

  const load = (): Promise<void> => (running > 0 ? Promise.resolve() : read())

  onMounted(() => {
    void load()
    if (pollMs > 0) timer = setInterval(() => void load(), pollMs)
  })

  onUnmounted(() => {
    if (timer) clearInterval(timer)
  })

  return { report, loading, unavailable, failed, load, refresh: read }
}
