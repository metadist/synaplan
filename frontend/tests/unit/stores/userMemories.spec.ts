import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useMemoriesStore } from '@/stores/userMemories'
import * as userMemoriesApi from '@/services/api/userMemoriesApi'
import type { UserMemory } from '@/services/api/userMemoriesApi'

// Mock the API
vi.mock('@/services/api/userMemoriesApi')

describe('Memories Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('should initialize with empty memories', () => {
    const store = useMemoriesStore()
    expect(store.memories).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('should fetch memories successfully', async () => {
    const mockMemories: UserMemory[] = [
      {
        id: 1,
        category: 'preferences',
        key: 'tech_stack',
        value: 'TypeScript with Vue 3',
        source: 'user_created',
        messageId: null,
        created: 1705234567,
        updated: 1705234567,
      },
      {
        id: 2,
        category: 'work',
        key: 'role',
        value: 'Developer',
        source: 'auto_detected',
        messageId: 123,
        created: 1705234568,
        updated: 1705234568,
      },
    ]

    vi.mocked(userMemoriesApi.getMemories).mockResolvedValue(mockMemories)
    vi.mocked(userMemoriesApi.getCategories).mockResolvedValue([
      { category: 'preferences', count: 1 },
      { category: 'work', count: 1 },
    ])

    const store = useMemoriesStore()
    await store.init()

    expect(store.memories).toEqual(mockMemories)
    expect(store.loading).toBe(false)
    expect(store.totalCount).toBe(2)
  })

  it('should handle fetch error', async () => {
    const errorMessage = 'Network error'
    vi.mocked(userMemoriesApi.getMemories).mockRejectedValue(new Error(errorMessage))

    const store = useMemoriesStore()
    await store.fetchMemories()

    expect(store.loading).toBe(false)
    expect(store.error).toBeTruthy()
  })

  it('should create memory successfully', async () => {
    const newMemory: UserMemory = {
      id: 1,
      category: 'preferences',
      key: 'editor',
      value: 'VS Code',
      source: 'user_created',
      messageId: null,
      created: 1705234567,
      updated: 1705234567,
    }

    vi.mocked(userMemoriesApi.createMemory).mockResolvedValue(newMemory)
    vi.mocked(userMemoriesApi.getCategories).mockResolvedValue([
      { category: 'preferences', count: 1 },
    ])

    const store = useMemoriesStore()
    const result = await store.addMemory({
      category: 'preferences',
      key: 'editor',
      value: 'VS Code',
    })

    expect(result).toEqual(newMemory)
    expect(store.memories).toContainEqual(newMemory)
  })

  it('should update memory successfully', async () => {
    const existingMemory: UserMemory = {
      id: 1,
      category: 'preferences',
      key: 'tech_stack',
      value: 'React',
      source: 'user_created',
      messageId: null,
      created: 1705234567,
      updated: 1705234567,
    }

    const updatedMemory: UserMemory = {
      ...existingMemory,
      value: 'Vue 3 with TypeScript',
      source: 'user_edited',
      updated: 1705234600,
    }

    vi.mocked(userMemoriesApi.updateMemory).mockResolvedValue(updatedMemory)

    const store = useMemoriesStore()
    store.memories = [existingMemory]

    await store.editMemory(1, { value: 'Vue 3 with TypeScript' })

    expect(store.memories[0].value).toBe('Vue 3 with TypeScript')
    expect(store.memories[0].source).toBe('user_edited')
  })

  it('should delete memory successfully', async () => {
    const memory: UserMemory = {
      id: 1,
      category: 'preferences',
      key: 'tech_stack',
      value: 'TypeScript',
      source: 'user_created',
      messageId: null,
      created: 1705234567,
      updated: 1705234567,
    }

    vi.mocked(userMemoriesApi.deleteMemory).mockResolvedValue(undefined)
    vi.mocked(userMemoriesApi.getCategories).mockResolvedValue([])

    const store = useMemoriesStore()
    store.memories = [memory]

    await store.removeMemory(1)

    expect(store.memories).toEqual([])
  })

  it('should filter memories by category', () => {
    const memories: UserMemory[] = [
      {
        id: 1,
        category: 'preferences',
        key: 'tech',
        value: 'TypeScript',
        source: 'user_created',
        messageId: null,
        created: 1705234567,
        updated: 1705234567,
      },
      {
        id: 2,
        category: 'work',
        key: 'role',
        value: 'Developer',
        source: 'user_created',
        messageId: null,
        created: 1705234568,
        updated: 1705234568,
      },
      {
        id: 3,
        category: 'preferences',
        key: 'editor',
        value: 'VS Code',
        source: 'user_created',
        messageId: null,
        created: 1705234569,
        updated: 1705234569,
      },
    ]

    const store = useMemoriesStore()
    store.memories = memories
    store.selectCategory('preferences')

    expect(store.filteredMemories).toHaveLength(2)
    expect(store.filteredMemories.every((m) => m.category === 'preferences')).toBe(true)
  })

  it('should return all memories when category is "all"', () => {
    const memories: UserMemory[] = [
      {
        id: 1,
        category: 'preferences',
        key: 'tech',
        value: 'TypeScript',
        source: 'user_created',
        messageId: null,
        created: 1705234567,
        updated: 1705234567,
      },
      {
        id: 2,
        category: 'work',
        key: 'role',
        value: 'Developer',
        source: 'user_created',
        messageId: null,
        created: 1705234568,
        updated: 1705234568,
      },
    ]

    const store = useMemoriesStore()
    store.memories = memories
    store.selectCategory(null)

    expect(store.filteredMemories).toHaveLength(2)
  })

  it('should count memories by category', () => {
    const memories: UserMemory[] = [
      {
        id: 1,
        category: 'preferences',
        key: 'tech',
        value: 'TypeScript',
        source: 'user_created',
        messageId: null,
        created: 1705234567,
        updated: 1705234567,
      },
      {
        id: 2,
        category: 'work',
        key: 'role',
        value: 'Developer',
        source: 'user_created',
        messageId: null,
        created: 1705234568,
        updated: 1705234568,
      },
      {
        id: 3,
        category: 'preferences',
        key: 'editor',
        value: 'VS Code',
        source: 'user_created',
        messageId: null,
        created: 1705234569,
        updated: 1705234569,
      },
    ]

    const store = useMemoriesStore()
    store.memories = memories

    expect(store.categoryCount('preferences')).toBe(2)
    expect(store.categoryCount('work')).toBe(1)
    expect(store.categoryCount('all')).toBe(3)
  })

  it('should get unique categories', () => {
    const memories: UserMemory[] = [
      {
        id: 1,
        category: 'preferences',
        key: 'tech',
        value: 'TypeScript',
        source: 'user_created',
        messageId: null,
        created: 1705234567,
        updated: 1705234567,
      },
      {
        id: 2,
        category: 'work',
        key: 'role',
        value: 'Developer',
        source: 'user_created',
        messageId: null,
        created: 1705234568,
        updated: 1705234568,
      },
      {
        id: 3,
        category: 'preferences',
        key: 'editor',
        value: 'VS Code',
        source: 'user_created',
        messageId: null,
        created: 1705234569,
        updated: 1705234569,
      },
    ]

    const store = useMemoriesStore()
    store.memories = memories

    expect(store.categories).toEqual(['preferences', 'work'])
  })

  it('should group memories by category', () => {
    const memories: UserMemory[] = [
      {
        id: 1,
        category: 'preferences',
        key: 'tech',
        value: 'TypeScript',
        source: 'user_created',
        messageId: null,
        created: 1705234567,
        updated: 1705234567,
      },
      {
        id: 2,
        category: 'work',
        key: 'role',
        value: 'Developer',
        source: 'user_created',
        messageId: null,
        created: 1705234568,
        updated: 1705234568,
      },
      {
        id: 3,
        category: 'preferences',
        key: 'editor',
        value: 'VS Code',
        source: 'user_created',
        messageId: null,
        created: 1705234569,
        updated: 1705234569,
      },
    ]

    const store = useMemoriesStore()
    store.memories = memories

    const grouped = store.memoriesByCategory

    expect(grouped.get('preferences')).toHaveLength(2)
    expect(grouped.get('work')).toHaveLength(1)
  })
})
