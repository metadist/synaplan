import { onUnmounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
import { useResearchStore } from '@/stores/research'

/**
 * Keeps the research store on the open chat: a run that chat started is
 * followed (or picked up again after a reload) and its reply bubble shows
 * it. Signing in asks once whether research is available at all.
 */
export function useResearchBinding(): void {
  const auth = useAuthStore()
  const research = useResearchStore()
  const { activeChatId } = storeToRefs(useChatsStore())

  watch(activeChatId, (chatId) => research.attachChat(chatId), { immediate: true })

  watch(
    () => auth.isAuthenticated,
    (signedIn) => {
      if (signedIn) void research.ensureCapabilities()
    },
    { immediate: true }
  )

  onUnmounted(() => {
    research.pause()
  })
}
