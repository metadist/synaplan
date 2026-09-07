import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { agentFieldPath, agentsApi, type Agent, type GalleryCard } from '@/services/api/agentsApi'

const SAVE_DEBOUNCE_MS = 600

export const useAgentsStore = defineStore('agents', () => {
  const gallery = ref<GalleryCard[]>([])
  const current = ref<Agent | null>(null)
  const dirty = ref(false)
  const saving = ref(false)
  const loading = ref(false)
  const fieldErrors = ref<Record<string, string>>({})
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  const currentId = computed(() => current.value?.id ?? null)

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
      current.value = await agentsApi.get(id)
      dirty.value = false
      fieldErrors.value = {}
      return current.value
    } finally {
      loading.value = false
    }
  }

  async function create(name: string): Promise<Agent> {
    const agent = await agentsApi.create({ name })
    current.value = agent
    dirty.value = false
    fieldErrors.value = {}
    return agent
  }

  async function clone(id: number): Promise<Agent> {
    const agent = await agentsApi.clone(id)
    await loadGallery()
    return agent
  }

  function markDirty(): void {
    dirty.value = true
    scheduleSave()
  }

  function scheduleSave(): void {
    if (debounceTimer) {
      clearTimeout(debounceTimer)
    }
    debounceTimer = setTimeout(() => {
      void saveDraft()
    }, SAVE_DEBOUNCE_MS)
  }

  async function saveDraft(): Promise<void> {
    const agent = current.value
    if (!agent || agent.id == null || !dirty.value || saving.value) {
      return
    }
    saving.value = true
    fieldErrors.value = {}
    try {
      const saved = await agentsApi.update(agent.id, {
        name: agent.name,
        description: agent.description ?? null,
        icon: agent.icon,
        draft: (agent.draft ?? {}) as Record<string, unknown>,
      })
      current.value = saved
      dirty.value = false
    } catch (error) {
      const path = agentFieldPath(error)
      if (path) {
        fieldErrors.value = { [path]: error instanceof Error ? error.message : String(error) }
      }
      throw error
    } finally {
      saving.value = false
    }
  }

  function clear(): void {
    if (debounceTimer) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    current.value = null
    dirty.value = false
    fieldErrors.value = {}
  }

  return {
    gallery,
    current,
    currentId,
    dirty,
    saving,
    loading,
    fieldErrors,
    loadGallery,
    load,
    create,
    clone,
    markDirty,
    saveDraft,
    clear,
  }
})
