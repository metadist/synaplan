import { describe, it, expect, beforeEach, vi } from 'vitest'
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
} from '@/services/api/userMemoriesApi'
import { httpClient } from '@/services/api/httpClient'

vi.mock('@/services/api/httpClient')

describe('User Memories API', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('getMemories', () => {
    it('should fetch all memories', async () => {
      const mockResponse = {
        memories: [
          {
            id: 1,
            category: 'preferences',
            key: 'tech_stack',
            value: 'TypeScript',
            source: 'user_created' as const,
            messageId: null,
            created: 1705234567,
            updated: 1705234567,
          },
        ],
        total: 1,
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      const result = await getMemories()

      expect(httpClient).toHaveBeenCalledWith('/api/v1/user/memories', {
        schema: expect.any(Object),
      })
      expect(result).toEqual(mockResponse.memories)
    })

    it('should fetch memories by category', async () => {
      const mockResponse = {
        memories: [
          {
            id: 1,
            category: 'preferences',
            key: 'tech_stack',
            value: 'TypeScript',
            source: 'user_created' as const,
            messageId: null,
            created: 1705234567,
            updated: 1705234567,
          },
        ],
        total: 1,
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      const result = await getMemories('preferences')

      expect(httpClient).toHaveBeenCalledWith('/api/v1/user/memories?category=preferences', {
        schema: expect.any(Object),
      })
      expect(result).toEqual(mockResponse.memories)
    })
  })

  describe('getCategories', () => {
    it('should fetch all categories with counts', async () => {
      const mockResponse = {
        categories: [
          { category: 'preferences', count: 5 },
          { category: 'work', count: 3 },
        ],
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      const result = await getCategories()

      expect(httpClient).toHaveBeenCalledWith('/api/v1/user/memories/categories', {
        schema: expect.any(Object),
      })
      expect(result).toEqual(mockResponse.categories)
    })
  })

  describe('createMemory', () => {
    it('should create a new memory', async () => {
      const newMemory: CreateMemoryRequest = {
        category: 'preferences',
        key: 'editor',
        value: 'VS Code',
      }

      const mockResponse = {
        memory: {
          id: 1,
          ...newMemory,
          source: 'user_created' as const,
          messageId: null,
          created: 1705234567,
          updated: 1705234567,
        },
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      const result = await createMemory(newMemory)

      expect(httpClient).toHaveBeenCalledWith('/api/v1/user/memories', {
        method: 'POST',
        body: JSON.stringify(newMemory),
        schema: expect.any(Object),
      })
      expect(result).toEqual(mockResponse.memory)
    })
  })

  describe('updateMemory', () => {
    it('should update an existing memory', async () => {
      const memoryId = 1
      const updates: UpdateMemoryRequest = {
        value: 'Vue 3 with TypeScript',
      }

      const mockResponse = {
        memory: {
          id: memoryId,
          category: 'preferences',
          key: 'tech_stack',
          value: 'Vue 3 with TypeScript',
          source: 'user_edited' as const,
          messageId: null,
          created: 1705234567,
          updated: 1705234600,
        },
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      const result = await updateMemory(memoryId, updates)

      expect(httpClient).toHaveBeenCalledWith(`/api/v1/user/memories/${memoryId}`, {
        method: 'PUT',
        body: JSON.stringify(updates),
        schema: expect.any(Object),
      })
      expect(result).toEqual(mockResponse.memory)
    })

    it('should update only the category', async () => {
      const memoryId = 1
      const updates: UpdateMemoryRequest = {
        category: 'work',
      }

      const mockResponse = {
        memory: {
          id: memoryId,
          category: 'work',
          key: 'tech_stack',
          value: 'TypeScript',
          source: 'user_edited' as const,
          messageId: null,
          created: 1705234567,
          updated: 1705234600,
        },
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      const result = await updateMemory(memoryId, updates)

      expect(result.category).toBe('work')
    })
  })

  describe('deleteMemory', () => {
    it('should delete a memory', async () => {
      const memoryId = 1

      vi.mocked(httpClient).mockResolvedValue({ success: true })

      await deleteMemory(memoryId)

      expect(httpClient).toHaveBeenCalledWith(`/api/v1/user/memories/${memoryId}`, {
        method: 'DELETE',
        schema: expect.any(Object),
      })
    })
  })

  describe('searchMemories', () => {
    it('should search memories with query', async () => {
      const searchRequest = {
        query: 'TypeScript',
        limit: 10,
      }

      const mockResponse = {
        memories: [
          {
            id: 1,
            category: 'preferences',
            key: 'tech_stack',
            value: 'TypeScript with Vue 3',
            source: 'user_created' as const,
            messageId: null,
            created: 1705234567,
            updated: 1705234567,
          },
        ],
        total: 1,
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      const result = await searchMemories(searchRequest)

      expect(httpClient).toHaveBeenCalledWith('/api/v1/user/memories/search', {
        method: 'POST',
        body: JSON.stringify(searchRequest),
        schema: expect.any(Object),
      })
      expect(result).toEqual(mockResponse.memories)
    })

    it('should search with category filter', async () => {
      const searchRequest = {
        query: 'TypeScript',
        category: 'preferences',
        limit: 5,
      }

      const mockResponse = {
        memories: [],
        total: 0,
      }

      vi.mocked(httpClient).mockResolvedValue(mockResponse)

      await searchMemories(searchRequest)

      expect(httpClient).toHaveBeenCalledWith('/api/v1/user/memories/search', {
        method: 'POST',
        body: JSON.stringify(searchRequest),
        schema: expect.any(Object),
      })
    })
  })
})
