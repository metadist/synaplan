import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useNotification } from '@/composables/useNotification'
import { useI18n } from 'vue-i18n'
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

export const useMemoriesStore = defineStore('memories', () => {
  // Lazy-load composables to avoid calling them outside setup context
  const getNotifications = () => {
    try {
      return useNotification()
    } catch {
      return { success: () => {}, error: () => {} }
    }
  }

  const getI18n = () => {
    try {
      return useI18n()
    } catch {
      return { t: (key: string) => key }
    }
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
  async function fetchMemories(category?: string) {
    loading.value = true
    error.value = null

    try {
      // Add timeout to prevent hanging when service is down
      // Very aggressive timeout - fail fast to not block page load!
      const timeoutPromise = new Promise((_, reject) => {
        setTimeout(() => reject(new Error('Memory service timeout')), 1500) // 1.5s timeout
      })

      const fetchPromise = getMemories(category)

      memories.value = (await Promise.race([fetchPromise, timeoutPromise])) as UserMemory[]
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to load memories'
      error.value = errorMsg

      // Silent fail if service unavailable - don't show notification on page load
      if (
        !errorMsg.includes('timeout') &&
        !errorMsg.includes('503') &&
        !errorMsg.includes('unavailable')
      ) {
        const { error: showError } = getNotifications()
        const { t } = getI18n()
        showError(t('memories.errors.loadFailed') || errorMsg)
      } else {
        console.warn('⚠️ Memory service unavailable (timeout or down), continuing without memories')
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

  async function addMemory(request: CreateMemoryRequest) {
    loading.value = true
    error.value = null

    try {
      const newMemory = await createMemory(request)
      memories.value.unshift(newMemory)

      const { success } = getNotifications()
      const { t } = getI18n()
      success(t('memories.create.success'))

      // Refresh categories
      await fetchCategories()

      return newMemory
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to create memory'
      error.value = errorMsg

      const { error: showError } = getNotifications()
      const { t } = getI18n()
      showError(t('memories.create.error') || errorMsg)

      throw err
    } finally {
      loading.value = false
    }
  }

  async function editMemory(id: number, request: UpdateMemoryRequest) {
    loading.value = true
    error.value = null

    try {
      const updatedMemory = await updateMemory(id, request)

      // Update in local state
      const index = memories.value.findIndex((m) => m.id === id)
      if (index !== -1) {
        memories.value[index] = updatedMemory
      }

      const { success } = getNotifications()
      const { t } = getI18n()
      success(t('memories.edit.success'))

      return updatedMemory
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to update memory'
      error.value = errorMsg

      const { error: showError } = getNotifications()
      const { t } = getI18n()
      showError(t('memories.edit.error') || errorMsg)

      throw err
    } finally {
      loading.value = false
    }
  }

  async function removeMemory(id: number) {
    loading.value = true
    error.value = null

    try {
      await deleteMemory(id)

      // Remove from local state
      memories.value = memories.value.filter((m) => m.id !== id)

      const { success } = getNotifications()
      const { t } = getI18n()
      success(t('memories.delete.success'))

      // Refresh categories
      await fetchCategories()
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to delete memory'
      error.value = errorMsg

      const { error: showError } = getNotifications()
      const { t } = getI18n()
      showError(t('memories.delete.error') || errorMsg)

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
      showError(errorMsg)

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
