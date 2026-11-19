import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const api = vi.hoisted(() => ({
  list: vi.fn(),
  listChats: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
  createChat: vi.fn(),
  joinChat: vi.fn(),
  leaveChat: vi.fn(),
}))

vi.mock('@/services/api/projectsApi', () => ({ projectsApi: api }))

import { useProjectsStore } from '@/stores/projects'
import { useProjectChatsStore } from '@/stores/projectChats'
import { ApiError } from '@/services/api/httpClient'

const project = (id: string, updatedAt: string) => ({
  id,
  name: `Project ${id}`,
  description: null,
  instructions: null,
  createdAt: updatedAt,
  updatedAt,
})

describe('projects store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    Object.values(api).forEach((fn) => fn.mockReset())
  })

  it('loads projects newest first and indexes their chats', async () => {
    api.list.mockResolvedValue([
      project('a', '2026-11-01T10:00:00+00:00'),
      project('b', '2026-11-02T10:00:00+00:00'),
    ])
    api.listChats.mockImplementation(async (id: string) =>
      id === 'a' ? [{ id: 5, title: 'Plan', updatedAt: '2026-11-01T10:00:00+00:00' }] : []
    )
    const store = useProjectsStore()
    await store.load()
    expect(store.projects.map((p) => p.id)).toEqual(['b', 'a'])
    expect(store.projectOfChat(5)?.id).toBe('a')
    expect(store.projectOfChat(6)).toBeUndefined()
    expect(store.projectOfChat(null)).toBeUndefined()
    expect(store.available).toBe(true)
  })

  it('treats the module gate 404 as "not available", not as an error', async () => {
    api.list.mockRejectedValue(
      new ApiError(404, 'x', undefined, {
        error: 'feature_not_configured',
        module: 'projects',
        docs: '',
      })
    )
    const store = useProjectsStore()
    await store.load()
    expect(store.available).toBe(false)
    expect(store.loadFailed).toBe(false)
    expect(store.projects).toEqual([])
  })

  it('flags a real failure and keeps the module available', async () => {
    api.list.mockRejectedValue(new ApiError(500, 'boom'))
    const store = useProjectsStore()
    await store.load()
    expect(store.available).toBe(true)
    expect(store.loadFailed).toBe(true)
  })

  it('moving a chat refreshes its old and its new project', async () => {
    api.list.mockResolvedValue([
      project('a', '2026-11-01T10:00:00+00:00'),
      project('b', '2026-11-02T10:00:00+00:00'),
    ])
    const chat = { id: 5, title: 'Plan', updatedAt: '2026-11-01T10:00:00+00:00' }
    let inB = false
    api.listChats.mockImplementation(async (id: string) => {
      if (id === 'a') return inB ? [] : [chat]
      return inB ? [chat] : []
    })
    api.joinChat.mockImplementation(async () => {
      inB = true
    })
    const store = useProjectsStore()
    await store.load()
    expect(store.projectOfChat(5)?.id).toBe('a')
    await useProjectChatsStore().move('b', 5)
    expect(api.joinChat).toHaveBeenCalledWith('b', 5)
    expect(store.projectOfChat(5)?.id).toBe('b')
  })

  it('removing a chat drops it from the index', async () => {
    api.list.mockResolvedValue([project('a', '2026-11-01T10:00:00+00:00')])
    api.listChats.mockResolvedValue([
      { id: 5, title: 'Plan', updatedAt: '2026-11-01T10:00:00+00:00' },
    ])
    api.leaveChat.mockResolvedValue(undefined)
    const store = useProjectsStore()
    await store.load()
    await useProjectChatsStore().remove('a', 5)
    expect(store.projectOfChat(5)).toBeUndefined()
  })

  it('deleting a project forgets it and its chats', async () => {
    api.list.mockResolvedValue([project('a', '2026-11-01T10:00:00+00:00')])
    api.listChats.mockResolvedValue([
      { id: 5, title: 'Plan', updatedAt: '2026-11-01T10:00:00+00:00' },
    ])
    api.remove.mockResolvedValue(undefined)
    const store = useProjectsStore()
    await store.load()
    await store.remove('a', 'keep')
    expect(api.remove).toHaveBeenCalledWith('a', 'keep')
    expect(store.projects).toEqual([])
    expect(store.projectOfChat(5)).toBeUndefined()
  })

  it('reset clears everything so no project outlives a sign-out', async () => {
    api.list.mockResolvedValue([project('a', '2026-11-01T10:00:00+00:00')])
    api.listChats.mockResolvedValue([])
    const store = useProjectsStore()
    await store.load()
    store.reset()
    expect(store.projects).toEqual([])
    expect(store.loaded).toBe(false)
  })

  it('refresh re-reads one project so a renamed chat shows its new title', async () => {
    const chats = useProjectChatsStore()
    chats.set('a', [{ id: 5, title: 'New chat', updatedAt: '2026-11-01T10:00:00+00:00' }])
    chats.set('b', [{ id: 6, title: 'Other', updatedAt: '2026-11-01T10:00:00+00:00' }])
    api.listChats.mockResolvedValue([
      { id: 5, title: 'Kitchen budget', updatedAt: '2026-11-02T10:00:00+00:00' },
    ])
    await chats.refresh('a')
    expect(api.listChats).toHaveBeenCalledWith('a')
    expect(chats.chatsOf('a').map((c) => c.title)).toEqual(['Kitchen budget'])
    expect(chats.chatsOf('b').map((c) => c.title)).toEqual(['Other'])
  })
})
