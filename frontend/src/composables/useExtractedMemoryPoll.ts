import { onBeforeUnmount, ref, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { chatApi } from '@/services/api'
import { useMemoriesStore } from '@/stores/userMemories'

/** Cheap extractions show up almost at once; slow ones (a queue backlog) are still picked up. */
const POLL_DELAYS_MS = [2_000, 4_000, 7_000, 12_000, 20_000]
const NOTICE_MS = 3_000

export interface ExtractedMemoryPoll {
  /** True while the notice about saved memories shows. */
  memoryToastVisible: Ref<boolean>
  memoryToastMessage: Ref<string>
  /** Follow the memory extraction of one stored answer. */
  scheduleMemoryPoll: (messageId: number) => void
}

/**
 * Picks up the memories the background worker extracted from a stored answer.
 *
 * The worker needs a few seconds for the extraction call and the vector
 * writes, so an answer completes before its memories exist. This polls the
 * outcome on a backoff, adds what was saved to the memory store, so the
 * answer's `[Memory:ID]` badges resolve without a reload, and shows a short
 * notice. Every timer stops with the component.
 */
export function useExtractedMemoryPoll(): ExtractedMemoryPoll {
  const { t } = useI18n()
  const memoriesStore = useMemoriesStore()
  const memoryToastVisible = ref(false)
  const memoryToastMessage = ref('')
  const timers = new Set<ReturnType<typeof setTimeout>>()
  let stopped = false

  const later = (delayMs: number, run: () => void): void => {
    const handle = setTimeout(() => {
      timers.delete(handle)
      if (!stopped) run()
    }, delayMs)
    timers.add(handle)
  }

  const showNotice = (message: string): void => {
    memoryToastMessage.value = message
    memoryToastVisible.value = true
    later(NOTICE_MS, () => {
      memoryToastVisible.value = false
    })
  }

  /** True once the extraction has settled or the view is gone; false to ask again. */
  const pollOnce = async (messageId: number): Promise<boolean> => {
    try {
      const result = await chatApi.getExtractedMemories(messageId)
      if (stopped) return true
      if (result.status === 'pending') return false

      const known = new Set(memoriesStore.memories.map((memory) => memory.id))
      for (const memory of result.saved) {
        if (known.has(memory.id)) continue
        known.add(memory.id)
        memoriesStore.memories.push(memory)
      }
      if (result.saved.length > 0 || result.delete_suggestions.length > 0) {
        showNotice(
          t(
            result.saved.length > 0
              ? 'processing.memoriesCompleteTitle'
              : 'processing.analyzingMemoriesTitle'
          )
        )
      }
      return true
    } catch (error) {
      console.warn('Memory extraction poll failed:', error)
      return false
    }
  }

  const scheduleMemoryPoll = (messageId: number): void => {
    const attempt = async (index: number): Promise<void> => {
      const settled = await pollOnce(messageId)
      if (!settled && !stopped && index + 1 < POLL_DELAYS_MS.length) {
        later(POLL_DELAYS_MS[index + 1], () => void attempt(index + 1))
      }
    }
    later(POLL_DELAYS_MS[0], () => void attempt(0))
  }

  onBeforeUnmount(() => {
    stopped = true
    for (const handle of timers) clearTimeout(handle)
    timers.clear()
  })

  return { memoryToastVisible, memoryToastMessage, scheduleMemoryPoll }
}
