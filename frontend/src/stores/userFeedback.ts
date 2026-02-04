import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useNotification } from '@/composables/useNotification'
import { i18n } from '@/i18n'
import {
  getFeedbacks,
  updateFeedback,
  deleteFeedback,
  type Feedback,
  type UpdateFeedbackRequest,
} from '@/services/api/userFeedbackApi'

// Use global i18n instance for translations outside Vue setup context
const t = (key: string) => i18n.global.t(key)

export const useFeedbackStore = defineStore('feedback', () => {
  // Lazy-load composables to avoid calling them outside setup context
  const getNotifications = () => {
    try {
      return useNotification()
    } catch {
      return { success: () => {}, error: () => {}, warning: () => {} }
    }
  }

  // State
  const feedbacks = ref<Feedback[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const selectedType = ref<'all' | 'false_positive' | 'positive'>('all')

  // Computed
  const filteredFeedbacks = computed(() => {
    if (selectedType.value === 'all') {
      return feedbacks.value
    }
    return feedbacks.value.filter((f) => f.type === selectedType.value)
  })

  const falsePositives = computed(() => feedbacks.value.filter((f) => f.type === 'false_positive'))

  const positives = computed(() => feedbacks.value.filter((f) => f.type === 'positive'))

  const totalCount = computed(() => feedbacks.value.length)

  const falsePositiveCount = computed(() => falsePositives.value.length)

  const positiveCount = computed(() => positives.value.length)

  // Actions
  async function fetchFeedbacks(options: { silent?: boolean } = {}) {
    loading.value = true
    error.value = null

    try {
      feedbacks.value = await getFeedbacks('all')
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to load feedbacks'
      error.value = errorMsg

      if (!options.silent) {
        if (!errorMsg.includes('503') && !errorMsg.includes('unavailable')) {
          const { error: showError } = getNotifications()
          showError(t('feedback.list.loadError'))
        }
      }

      feedbacks.value = []
    } finally {
      loading.value = false
    }
  }

  async function editFeedback(
    id: number,
    request: UpdateFeedbackRequest,
    options: { silent?: boolean } = {}
  ) {
    loading.value = true
    error.value = null

    try {
      const updated = await updateFeedback(id, request)

      // Update in local state
      const index = feedbacks.value.findIndex((f) => f.id === id)
      if (index !== -1) {
        feedbacks.value[index] = updated
      }

      if (!options.silent) {
        const { success } = getNotifications()
        success(t('feedback.list.editSuccess'))
      }

      return updated
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to update feedback'
      error.value = errorMsg

      if (!options.silent) {
        const { error: showError } = getNotifications()
        showError(t('feedback.list.editError'))
      }

      throw err
    } finally {
      loading.value = false
    }
  }

  async function removeFeedback(id: number, options: { silent?: boolean } = {}) {
    loading.value = true
    error.value = null

    try {
      await deleteFeedback(id)

      // Remove from local state
      feedbacks.value = feedbacks.value.filter((f) => f.id !== id)

      if (!options.silent) {
        const { success } = getNotifications()
        success(t('feedback.list.deleteSuccess'))
      }
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to delete feedback'
      error.value = errorMsg

      if (!options.silent) {
        const { error: showError } = getNotifications()
        showError(t('feedback.list.deleteError'))
      }

      throw err
    } finally {
      loading.value = false
    }
  }

  function selectType(type: 'all' | 'false_positive' | 'positive') {
    selectedType.value = type
  }

  function clearError() {
    error.value = null
  }

  // Get feedback by ID
  function getFeedbackById(id: number): Feedback | undefined {
    return feedbacks.value.find((f) => f.id === id)
  }

  return {
    // State
    feedbacks,
    loading,
    error,
    selectedType,

    // Computed
    filteredFeedbacks,
    falsePositives,
    positives,
    totalCount,
    falsePositiveCount,
    positiveCount,

    // Actions
    fetchFeedbacks,
    editFeedback,
    removeFeedback,
    selectType,
    clearError,
    getFeedbackById,
  }
})
