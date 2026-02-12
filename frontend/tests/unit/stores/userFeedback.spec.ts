import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useFeedbackStore } from '@/stores/userFeedback'
import * as userFeedbackApi from '@/services/api/userFeedbackApi'
import type { Feedback } from '@/services/api/userFeedbackApi'

// Mock the API
vi.mock('@/services/api/userFeedbackApi')

// Mock i18n
vi.mock('@/i18n', () => ({
  i18n: {
    global: {
      t: (key: string) => key,
    },
  },
}))

const mockFeedbacks: Feedback[] = [
  {
    id: 1,
    type: 'false_positive',
    value: 'The AI claimed Sydney is the capital of Australia',
    created: 1705234567,
    updated: 1705234567,
  },
  {
    id: 2,
    type: 'positive',
    value: 'The capital of Australia is Canberra',
    created: 1705234568,
    updated: 1705234568,
  },
  {
    id: 3,
    type: 'false_positive',
    value: 'A circle is rectangular',
    created: 1705234569,
    updated: 1705234569,
  },
]

describe('Feedback Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('should initialize with empty state', () => {
    const store = useFeedbackStore()
    expect(store.feedbacks).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
    expect(store.selectedType).toBe('all')
  })

  describe('fetchFeedbacks', () => {
    it('should fetch and store feedbacks', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()

      expect(store.feedbacks).toEqual(mockFeedbacks)
      expect(store.loading).toBe(false)
      expect(store.error).toBeNull()
    })

    it('should handle fetch error gracefully', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockRejectedValue(new Error('Network error'))

      const store = useFeedbackStore()
      await store.fetchFeedbacks({ silent: true })

      expect(store.feedbacks).toEqual([])
      expect(store.error).toBe('Network error')
      expect(store.loading).toBe(false)
    })

    it('should set loading state during fetch', async () => {
      let resolvePromise: (value: Feedback[]) => void
      vi.mocked(userFeedbackApi.getFeedbacks).mockReturnValue(
        new Promise((resolve) => {
          resolvePromise = resolve
        })
      )

      const store = useFeedbackStore()
      const fetchPromise = store.fetchFeedbacks()

      expect(store.loading).toBe(true)

      resolvePromise!(mockFeedbacks)
      await fetchPromise

      expect(store.loading).toBe(false)
    })
  })

  describe('computed properties', () => {
    it('should filter false positives', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()

      expect(store.falsePositives).toHaveLength(2)
      expect(store.falsePositives.every((f) => f.type === 'false_positive')).toBe(true)
    })

    it('should filter positive feedbacks', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()

      expect(store.positives).toHaveLength(1)
      expect(store.positives[0].type).toBe('positive')
    })

    it('should compute counts correctly', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()

      expect(store.totalCount).toBe(3)
      expect(store.falsePositiveCount).toBe(2)
      expect(store.positiveCount).toBe(1)
    })

    it('should filter by selected type', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()

      store.selectType('false_positive')
      expect(store.filteredFeedbacks).toHaveLength(2)

      store.selectType('positive')
      expect(store.filteredFeedbacks).toHaveLength(1)

      store.selectType('all')
      expect(store.filteredFeedbacks).toHaveLength(3)
    })
  })

  describe('editFeedback', () => {
    it('should update feedback in local state', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)

      const updated: Feedback = { ...mockFeedbacks[0], value: 'Updated value' }
      vi.mocked(userFeedbackApi.updateFeedback).mockResolvedValue(updated)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()
      await store.editFeedback(1, { value: 'Updated value' }, { silent: true })

      expect(store.feedbacks[0].value).toBe('Updated value')
    })

    it('should handle edit error', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)
      vi.mocked(userFeedbackApi.updateFeedback).mockRejectedValue(new Error('Update failed'))

      const store = useFeedbackStore()
      await store.fetchFeedbacks()

      await expect(store.editFeedback(1, { value: 'x' }, { silent: true })).rejects.toThrow(
        'Update failed'
      )
      expect(store.error).toBe('Update failed')
    })
  })

  describe('removeFeedback', () => {
    it('should remove feedback from local state', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)
      vi.mocked(userFeedbackApi.deleteFeedback).mockResolvedValue(undefined)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()
      await store.removeFeedback(1, { silent: true })

      expect(store.feedbacks).toHaveLength(2)
      expect(store.feedbacks.find((f) => f.id === 1)).toBeUndefined()
    })
  })

  describe('bulkRemoveFeedbacks', () => {
    it('should remove multiple feedbacks', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)
      vi.mocked(userFeedbackApi.deleteFeedback).mockResolvedValue(undefined)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()
      await store.bulkRemoveFeedbacks([1, 3])

      expect(store.feedbacks).toHaveLength(1)
      expect(store.feedbacks[0].id).toBe(2)
    })

    it('should handle empty array', async () => {
      const store = useFeedbackStore()
      await store.bulkRemoveFeedbacks([])

      expect(userFeedbackApi.deleteFeedback).not.toHaveBeenCalled()
    })

    it('should continue on individual delete failures', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)
      vi.mocked(userFeedbackApi.deleteFeedback)
        .mockRejectedValueOnce(new Error('fail'))
        .mockResolvedValueOnce(undefined)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()
      await store.bulkRemoveFeedbacks([1, 3])

      // Only id=3 should be removed (id=1 failed)
      expect(store.feedbacks).toHaveLength(2)
      expect(store.feedbacks.find((f) => f.id === 3)).toBeUndefined()
    })
  })

  describe('getFeedbackById', () => {
    it('should return feedback by ID', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockResolvedValue(mockFeedbacks)

      const store = useFeedbackStore()
      await store.fetchFeedbacks()

      const result = store.getFeedbackById(2)
      expect(result).toBeDefined()
      expect(result?.id).toBe(2)
      expect(result?.type).toBe('positive')
    })

    it('should return undefined for non-existent ID', () => {
      const store = useFeedbackStore()
      expect(store.getFeedbackById(999)).toBeUndefined()
    })
  })

  describe('clearError', () => {
    it('should clear error state', async () => {
      vi.mocked(userFeedbackApi.getFeedbacks).mockRejectedValue(new Error('fail'))

      const store = useFeedbackStore()
      await store.fetchFeedbacks({ silent: true })

      expect(store.error).not.toBeNull()

      store.clearError()
      expect(store.error).toBeNull()
    })
  })
})
