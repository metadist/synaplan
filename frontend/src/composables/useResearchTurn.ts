import { computed, type ComputedRef } from 'vue'
import { useResearchStore } from '@/stores/research'
import { isTerminalStatus } from '@/services/chat/stream/handlers/research'

/**
 * Whether the research a reply bubble carries is still working. While it
 * is, the bubble shows the run instead of the one-sentence fallback text
 * the stream wrote; once the run is done the report is the text.
 */
export function useResearchTurn(runId: () => string | null | undefined): {
  running: ComputedRef<boolean>
} {
  const research = useResearchStore()
  const running = computed(() => {
    const id = runId()
    const progress = research.progress
    return !!id && progress !== null && progress.id === id && !isTerminalStatus(progress.status)
  })
  return { running }
}
