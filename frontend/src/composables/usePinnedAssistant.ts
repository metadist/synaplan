import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { agentsApi } from '@/services/api/agentsApi'
import { useHistoryStore } from '@/stores/history'
import { isAgentsEnabled } from './useAgentsFeature'

export function usePinnedAssistant() {
  const route = useRoute()
  const historyStore = useHistoryStore()
  const name = ref<string | null>(null)

  const agentId = computed(() => {
    if (!isAgentsEnabled()) {
      return null
    }
    const fromQuery = Number(route.query.agentId)
    if (Number.isFinite(fromQuery) && fromQuery > 0) {
      return fromQuery
    }
    for (const message of historyStore.messages) {
      if (message.agentId && message.agentId > 0) {
        return message.agentId
      }
    }
    return null
  })

  watch(
    agentId,
    async (id) => {
      if (!id) {
        name.value = null
        return
      }
      try {
        const agent = await agentsApi.get(id)
        name.value = agent.name ?? null
      } catch {
        name.value = null
      }
    },
    { immediate: true }
  )

  return { agentId, name }
}
