import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useNotification } from '@/composables/useNotification'
import { i18n } from '@/i18n'
import {
  getMemories,
  getCategories,
  createMemory,
  updateMemory,
  deleteMemory,
  searchMemories,
  type UserMemory,
  type CreateMemoryRequest,
  type UpdateMemoryRequest,
  type SearchMemoriesRequest,
} from '@/services/api/userMemoriesApi'

// Use global i18n instance for translations outside Vue setup context
const t = (key: string) => i18n.global.t(key)

export const useMemoriesStore = defineStore('memories', () => {
  // Lazy-load composables to avoid calling them outside setup context
  const getNotifications = () => {
    try {
      return useNotification()
    } catch {
      return { success: () => {}, error: () => {} }
    }
  }

  /**
   * Format error message for notification.
   * Backend only sends debug info to admins, so we just need to combine
   * the translated message with the technical error (which may contain [Debug] for admins).
   */
  const formatErrorMessage = (translationKey: string, technicalError: string): string => {
    const userMessage = t(translationKey)
    // If the error contains [Debug], it's from the backend for admins
    // Otherwise, just show the user-friendly message
    if (technicalError.includes('[Debug]')) {
      return `${userMessage}\n\n${technicalError}`
    }
    return userMessage
  }

  // State
  const memories = ref<UserMemory[]>([])
  const categoriesData = ref<Array<{ category: string; count: number }>>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const selectedCategory = ref<string | null>(null)

  // Computed
  const filteredMemories = computed(() => {
    if (!selectedCategory.value || selectedCategory.value === 'all') {
      return memories.value
    }
    return memories.value.filter((m) => m.category === selectedCategory.value)
  })

  const memoriesByCategory = computed(() => {
    const grouped = new Map<string, UserMemory[]>()

    for (const memory of memories.value) {
      if (!grouped.has(memory.category)) {
        grouped.set(memory.category, [])
      }
      grouped.get(memory.category)!.push(memory)
    }

    return grouped
  })

  const totalCount = computed(() => memories.value.length)

  const categoryCount = computed(() => {
    return (category: string) => {
      if (category === 'all') {
        return totalCount.value
      }
      return memories.value.filter((m) => m.category === category).length
    }
  })

  const categories = computed(() => {
    return Array.from(new Set(memories.value.map((m) => m.category)))
  })

  // Actions
  async function fetchMemories(
    category?: string,
    options: { timeoutMs?: number; silent?: boolean } = {}
  ) {
    loading.value = true
    error.value = null
    const timeoutMs = options.timeoutMs ?? 1500
    const silent = options.silent ?? false

    try {
      const timeoutPromise = new Promise((_, reject) => {
        setTimeout(() => reject(new Error('Memory service timeout')), timeoutMs)
      })

      const fetchPromise = getMemories(category)

      memories.value = (await Promise.race([fetchPromise, timeoutPromise])) as UserMemory[]
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to load memories'
      error.value = errorMsg

      // Silent fail if service unavailable - don't show notification on page load
      if (!silent) {
        if (
          !errorMsg.includes('timeout') &&
          !errorMsg.includes('503') &&
          !errorMsg.includes('unavailable')
        ) {
          const { error: showError } = getNotifications()
          showError(formatErrorMessage('memories.loadError', errorMsg))
        } else {
          console.warn(
            '⚠️ Memory service unavailable (timeout or down), continuing without memories'
          )
        }
      }

      // Set empty array so page can continue
      memories.value = []
    } finally {
      loading.value = false
    }
  }

  async function fetchCategories() {
    try {
      const result = await getCategories()
      categoriesData.value = result
    } catch (err) {
      console.error('Failed to load categories:', err)
    }
  }

  async function addMemory(request: CreateMemoryRequest, options: { silent?: boolean } = {}) {
    loading.value = true
    error.value = null

    try {
      const newMemory = await createMemory(request)
      memories.value.unshift(newMemory)

      if (!options.silent) {
        const { success } = getNotifications()
        success(t('memories.createSuccess'))
      }

      // Refresh categories
      await fetchCategories()

      return newMemory
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to create memory'
      error.value = errorMsg

      if (!options.silent) {
        const { error: showError } = getNotifications()
        showError(formatErrorMessage('memories.createError', errorMsg))
      }

      throw err
    } finally {
      loading.value = false
    }
  }

  async function editMemory(
    id: number,
    request: UpdateMemoryRequest,
    options: { silent?: boolean } = {}
  ) {
    loading.value = true
    error.value = null

    try {
      const updatedMemory = await updateMemory(id, request)

      // Update in local state
      const index = memories.value.findIndex((m) => m.id === id)
      if (index !== -1) {
        memories.value[index] = updatedMemory
      }

      if (!options.silent) {
        const { success } = getNotifications()
        success(t('memories.editSuccess'))
      }

      return updatedMemory
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to update memory'
      error.value = errorMsg

      if (!options.silent) {
        const { error: showError } = getNotifications()
        showError(formatErrorMessage('memories.editError', errorMsg))
      }

      throw err
    } finally {
      loading.value = false
    }
  }

  async function removeMemory(id: number, options: { silent?: boolean } = {}) {
    loading.value = true
    error.value = null

    try {
      await deleteMemory(id)

      // Remove from local state
      memories.value = memories.value.filter((m) => m.id !== id)

      if (!options.silent) {
        const { success } = getNotifications()
        success(t('memories.deleteSuccess'))
      }

      // Refresh categories
      await fetchCategories()
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to delete memory'
      error.value = errorMsg

      if (!options.silent) {
        const { error: showError } = getNotifications()
        showError(formatErrorMessage('memories.deleteError', errorMsg))
      }

      throw err
    } finally {
      loading.value = false
    }
  }

  async function search(request: SearchMemoriesRequest) {
    loading.value = true
    error.value = null

    try {
      const results = await searchMemories(request)
      memories.value = results
      return results
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Search failed'
      error.value = errorMsg

      const { error: showError } = getNotifications()
      showError(formatErrorMessage('memories.searchError', errorMsg))

      return []
    } finally {
      loading.value = false
    }
  }

  function selectCategory(category: string | null) {
    selectedCategory.value = category
  }

  function clearError() {
    error.value = null
  }

  // Initialize
  async function init() {
    await Promise.all([fetchMemories(), fetchCategories()])
  }

  return {
    // State
    memories,
    categories,
    loading,
    error,
    selectedCategory,

    // Computed
    filteredMemories,
    memoriesByCategory,
    totalCount,
    categoryCount,

    // Actions
    fetchMemories,
    fetchCategories,
    addMemory,
    editMemory,
    removeMemory,
    search,
    selectCategory,
    clearError,
    init,
  }
})
