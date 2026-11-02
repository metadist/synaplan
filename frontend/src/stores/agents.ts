import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { i18n } from '@/i18n/instance'
import { useNotification } from '@/composables/useNotification'
import {
  agentFieldPath,
  agentsApi,
  type Agent,
  type AgentWritePayload,
  type GalleryCard,
} from '@/services/api/agentsApi'

const SAVE_DEBOUNCE_MS = 600
const NAME_MAX_LENGTH = 128

const t = (key: string) => i18n.global.t(key)

function notifyError(message: string): void {
  try {
    useNotification().error(message)
  } catch {
    // Pinia can construct the store outside of a Vue setup context in tests.
  }
}

function nameValidationError(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') {
    return String(t('assistants.nameRequired'))
  }
  if ([...trimmed].length > NAME_MAX_LENGTH) {
    return String(t('assistants.nameTooLong'))
  }
  return null
}

function messageForPath(path: string, fallback: string): string {
  if (path === 'name') {
    if (/empty|required/i.test(fallback)) {
      return String(t('assistants.nameRequired'))
    }
    if (/128|at most|too long/i.test(fallback)) {
      return String(t('assistants.nameTooLong'))
    }
  }
  return fallback
}

export const useAgentsStore = defineStore('agents', () => {
  const gallery = ref<GalleryCard[]>([])
  const current = ref<Agent | null>(null)
  const ownFileCount = ref(0)
  const dirty = ref(false)
  const saving = ref(false)
  const loading = ref(false)
  const fieldErrors = ref<Record<string, string>>({})
  let debounceTimer: ReturnType<typeof setTimeout> | null = null
  let inFlightSave: Promise<void> | null = null

  const currentId = computed(() => current.value?.id ?? null)
  const hasUnsavedWork = computed(() => dirty.value || saving.value)

  function clearDebounce(): void {
    if (debounceTimer) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
  }

  function resetCurrent(agent: Agent | null): void {
    current.value = agent
    ownFileCount.value = 0
    dirty.value = false
    fieldErrors.value = {}
  }

  function setFieldError(path: string, message: string | null): void {
    if (message) {
      fieldErrors.value = { ...fieldErrors.value, [path]: message }
      return
    }
    if (!(path in fieldErrors.value)) {
      return
    }
    const next = { ...fieldErrors.value }
    delete next[path]
    fieldErrors.value = next
  }

  async function loadGallery(): Promise<void> {
    loading.value = true
    try {
      gallery.value = await agentsApi.gallery()
    } finally {
      loading.value = false
    }
  }

  async function load(id: number): Promise<Agent> {
    loading.value = true
    try {
      const loaded = await agentsApi.get(id)
      resetCurrent(loaded)
      return loaded
    } finally {
      loading.value = false
    }
  }

  async function create(name: string): Promise<Agent> {
    const agent = await agentsApi.create({ name })
    resetCurrent(agent)
    return agent
  }

  async function clone(id: number): Promise<Agent> {
    const agent = await agentsApi.clone(id)
    await loadGallery()
    return agent
  }

  async function remove(id: number): Promise<void> {
    await agentsApi.remove(id)
    if (current.value?.id === id) {
      clearDebounce()
      resetCurrent(null)
    }
    gallery.value = gallery.value.filter((card) => card.id !== id)
  }

  function markDirty(): void {
    dirty.value = true
    scheduleSave()
  }

  function scheduleSave(): void {
    clearDebounce()
    debounceTimer = setTimeout(() => {
      // A failed background save leaves `dirty` set and fieldErrors filled;
      // nameless failures also toast so the builder is never silent.
      saveDraft().catch(() => undefined)
    }, SAVE_DEBOUNCE_MS)
  }

  async function saveDraft(): Promise<void> {
    const agent = current.value
    if (!agent || agent.id == null || !dirty.value || saving.value) {
      return
    }
    const nameError = nameValidationError(agent.name ?? '')
    saving.value = true
    dirty.value = false
    fieldErrors.value = nameError ? { name: nameError } : {}
    const payload: AgentWritePayload = {
      description: agent.description ?? null,
      icon: agent.icon,
      draft: agent.draft,
      routable: agent.routable,
    }
    if (!nameError) {
      payload.name = (agent.name ?? '').trim()
    }
    const run = (async () => {
      try {
        const saved = await agentsApi.update(agent.id!, payload)
        const local = current.value
        if (!local || local.id !== saved.id) {
          if (local?.id != null && dirty.value) scheduleSave()
          return
        }
        if (dirty.value) {
          current.value = {
            ...saved,
            name: local.name,
            description: local.description,
            icon: local.icon,
            draft: local.draft,
          }
          scheduleSave()
          return
        }
        if (nameError) {
          current.value = { ...saved, name: local.name }
          dirty.value = true
          fieldErrors.value = { name: nameError }
          return
        }
        current.value = saved
      } catch (error) {
        dirty.value = true
        const path = agentFieldPath(error)
        const fallback = error instanceof Error ? error.message : String(error)
        if (path) {
          setFieldError(path, messageForPath(path, fallback))
        } else {
          notifyError(String(t('assistants.saveFailed')))
        }
        throw error
      } finally {
        saving.value = false
        inFlightSave = null
      }
    })()
    inFlightSave = run
    await run
  }

  async function flushSave(): Promise<void> {
    clearDebounce()
    if (inFlightSave) {
      await inFlightSave.catch(() => undefined)
      clearDebounce()
    }
    if (dirty.value) {
      await saveDraft()
    }
  }

  function clear(): void {
    clearDebounce()
    resetCurrent(null)
  }

  return {
    gallery,
    current,
    currentId,
    ownFileCount,
    dirty,
    saving,
    loading,
    fieldErrors,
    hasUnsavedWork,
    NAME_MAX_LENGTH,
    nameValidationError,
    setFieldError,
    loadGallery,
    load,
    create,
    clone,
    remove,
    markDirty,
    saveDraft,
    flushSave,
    clear,
  }
})
