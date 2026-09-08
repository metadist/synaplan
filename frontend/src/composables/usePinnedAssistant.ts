import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { agentsApi } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import { useHistoryStore } from '@/stores/history'
import { isAgentsEnabled } from './useAgentsFeature'

export function parseAgentIdQuery(value: unknown): number | null {
  const raw = Array.isArray(value) ? value[0] : value
  const id = Number(raw)
  return Number.isFinite(id) && id > 0 ? id : null
}

export function resolvePinnedAgentId(
  enabled: boolean,
  queryAgentId: number | null,
  messages: Array<{ agentId?: number | null }>
): number | null {
  if (!enabled) {
    return null
  }
  for (const message of messages) {
    if (message.agentId && message.agentId > 0) {
      return message.agentId
    }
  }
  if (messages.length > 0) {
    return null
  }
  return queryAgentId
}

/** True when ?agentId= points at an assistant this thread is not already using. */
export function shouldOpenFreshAssistantChat(
  queryAgentId: number | null,
  messages: Array<{ agentId?: number | null }>
): boolean {
  if (queryAgentId == null) {
    return false
  }
  if (messages.length === 0) {
    return false
  }
  return !messages.some((message) => message.agentId === queryAgentId)
}

function trimmedStrings(values: unknown): string[] {
  if (!Array.isArray(values)) {
    return []
  }
  const out: string[] = []
  for (const value of values) {
    if (typeof value !== 'string') {
      continue
    }
    const text = value.trim()
    if (text === '') {
      continue
    }
    out.push(text)
    if (out.length === 3) {
      break
    }
  }
  return out
}

export function usePinnedAssistant() {
  const route = useRoute()
  const { t } = useI18n()
  const historyStore = useHistoryStore()
  const agentsStore = useAgentsStore()
  const name = ref<string | null>(null)
  const greeting = ref('')
  const starterPrompts = ref<string[]>([])
  let loadSeq = 0

  const queryAgentId = computed(() => parseAgentIdQuery(route.query.agentId))

  const agentId = computed(() =>
    resolvePinnedAgentId(isAgentsEnabled(), queryAgentId.value, historyStore.messages)
  )

  function resetDetails(): void {
    name.value = null
    greeting.value = ''
    starterPrompts.value = []
  }

  function applyFallbackName(): void {
    if (!name.value) {
      name.value = t('assistants.untitled')
    }
  }

  function applyFromGallery(id: number): boolean {
    const card = agentsStore.gallery.find((row) => row.id === id)
    if (!card) {
      return false
    }
    if (typeof card.name === 'string' && card.name.trim() !== '') {
      name.value = card.name
    }
    const fromCard = trimmedStrings(card.starterPrompts)
    if (fromCard.length > 0) {
      starterPrompts.value = fromCard
    }
    return true
  }

  function applyFromCurrent(id: number): boolean {
    const current = agentsStore.current
    if (!current || current.id !== id) {
      return false
    }
    if (typeof current.name === 'string' && current.name.trim() !== '') {
      name.value = current.name
    }
    const behaviour = current.draft?.behaviour
    if (typeof behaviour?.greeting === 'string') {
      greeting.value = behaviour.greeting.trim()
    }
    const fromDraft = trimmedStrings(behaviour?.starterPrompts)
    if (fromDraft.length > 0) {
      starterPrompts.value = fromDraft
    }
    return true
  }

  watch(
    agentId,
    async (id) => {
      const seq = ++loadSeq
      if (!id) {
        resetDetails()
        return
      }
      applyFallbackName()
      applyFromGallery(id)
      applyFromCurrent(id)
      applyFallbackName()

      try {
        const agent = await agentsApi.get(id)
        if (seq !== loadSeq) {
          return
        }
        if (typeof agent.name === 'string' && agent.name.trim() !== '') {
          name.value = agent.name
        }
        const behaviour = agent.draft?.behaviour
        if (typeof behaviour?.greeting === 'string') {
          greeting.value = behaviour.greeting.trim()
        }
        const fromDraft = trimmedStrings(behaviour?.starterPrompts)
        if (fromDraft.length > 0) {
          starterPrompts.value = fromDraft
        }
        applyFallbackName()
      } catch {
        if (seq !== loadSeq) {
          return
        }
        if (agentsStore.gallery.length === 0) {
          try {
            await agentsStore.loadGallery()
          } catch {
            // Banner still shows the fallback name.
          }
        }
        if (seq !== loadSeq) {
          return
        }
        applyFromGallery(id)
        applyFallbackName()
      }
    },
    { immediate: true }
  )

  return { agentId, queryAgentId, name, greeting, starterPrompts }
}
