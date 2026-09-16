import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { emptyAgentDraft, type Agent } from '@/services/api/agentsApi'
import { ApiError } from '@/services/api/httpClient'
import { useAgentsStore } from '@/stores/agents'

const updateMock = vi.fn()
const errorMock = vi.fn()

vi.mock('@/services/api/agentsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/agentsApi')>()
  return {
    ...actual,
    agentsApi: {
      ...actual.agentsApi,
      update: (...args: unknown[]) => updateMock(...args),
    },
  }
})

vi.mock('@/i18n', () => ({
  i18n: {
    global: {
      t: (key: string) => key,
    },
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: errorMock, success: vi.fn() }),
}))

function agent(overrides: Partial<Agent> = {}): Agent {
  return {
    id: 7,
    slug: 'helper',
    name: 'Helper',
    description: null,
    icon: 'assistant',
    status: 'draft',
    promptId: 1,
    parentId: null,
    source: 'manual',
    routable: false,
    publishedVersionId: null,
    draft: emptyAgentDraft(),
    createdAt: 1,
    updatedAt: 1,
    ...overrides,
  } as Agent
}

describe('agents store autosave', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    updateMock.mockReset()
    errorMock.mockReset()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps edits typed while a save is in flight and saves them afterwards', async () => {
    const store = useAgentsStore()
    store.current = agent()

    let resolveFirst: (value: Agent) => void = () => {}
    updateMock
      .mockImplementationOnce(
        () =>
          new Promise<Agent>((resolve) => {
            resolveFirst = resolve
          })
      )
      .mockImplementationOnce((_id: number, payload: { name: string }) =>
        Promise.resolve(agent({ name: payload.name, updatedAt: 3 }))
      )

    store.current.name = 'Helper v2'
    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)
    expect(updateMock).toHaveBeenCalledTimes(1)
    expect(store.saving).toBe(true)

    // The user keeps typing while request #1 is still open.
    store.current!.name = 'Helper v3'
    store.markDirty()

    resolveFirst(agent({ name: 'Helper v2', updatedAt: 2 }))
    await vi.advanceTimersByTimeAsync(0)

    expect(store.current?.name).toBe('Helper v3')
    expect(store.current?.updatedAt).toBe(2)
    expect(store.dirty).toBe(true)

    await vi.advanceTimersByTimeAsync(600)
    expect(updateMock).toHaveBeenCalledTimes(2)
    expect(updateMock.mock.calls[1][1]).toMatchObject({ name: 'Helper v3' })
    expect(store.current?.name).toBe('Helper v3')
    expect(store.dirty).toBe(false)
  })

  it('takes the server row when nothing changed during the save', async () => {
    const store = useAgentsStore()
    store.current = agent()
    updateMock.mockResolvedValueOnce(agent({ name: 'Helper', updatedAt: 9 }))

    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)

    expect(store.current?.updatedAt).toBe(9)
    expect(store.dirty).toBe(false)
    expect(store.saving).toBe(false)
  })

  it('leaves the draft dirty when the save fails', async () => {
    const store = useAgentsStore()
    store.current = agent()
    updateMock.mockRejectedValueOnce(new Error('offline'))

    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)

    expect(store.dirty).toBe(true)
    expect(store.saving).toBe(false)
    expect(errorMock).toHaveBeenCalledWith('assistants.saveFailed')
  })

  it('omits an empty name so other fields still save', async () => {
    const store = useAgentsStore()
    const draft = emptyAgentDraft()
    draft.behaviour.greeting = 'hello from probe'
    store.current = agent({ name: '   ', description: 'typed then cleared', draft })
    updateMock.mockImplementationOnce((_id: number, payload: Record<string, unknown>) => {
      expect(payload).not.toHaveProperty('name')
      expect(payload.description).toBe('typed then cleared')
      expect((payload.draft as { behaviour: { greeting: string } }).behaviour.greeting).toBe(
        'hello from probe'
      )
      return Promise.resolve(agent({ description: 'typed then cleared', updatedAt: 4 }))
    })

    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)

    expect(updateMock).toHaveBeenCalledTimes(1)
    expect(store.fieldErrors.name).toBe('assistants.nameRequired')
    expect(store.current?.name).toBe('   ')
    expect(store.current?.description).toBe('typed then cleared')
    expect(store.dirty).toBe(true)
    expect(errorMock).not.toHaveBeenCalled()
  })

  it('fills fieldErrors from a 400 that names the path', async () => {
    const store = useAgentsStore()
    store.current = agent()
    updateMock.mockRejectedValueOnce(
      new ApiError(400, 'name must not be empty', 'name must not be empty', {
        error: 'name must not be empty',
        path: 'name',
      })
    )

    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)

    expect(store.fieldErrors.name).toBe('assistants.nameRequired')
    expect(store.dirty).toBe(true)
    expect(errorMock).not.toHaveBeenCalled()
  })

  it('treats 128 accented characters as within the limit', async () => {
    const store = useAgentsStore()
    const name = 'é'.repeat(128)
    store.current = agent({ name })
    updateMock.mockResolvedValueOnce(agent({ name, updatedAt: 5 }))

    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)

    expect(updateMock).toHaveBeenCalledTimes(1)
    expect(updateMock.mock.calls[0][1]).toMatchObject({ name })
    expect(store.fieldErrors.name).toBeUndefined()
    expect(store.dirty).toBe(false)
  })

  it('rejects a 129-character accented name before the request', async () => {
    const store = useAgentsStore()
    store.current = agent({ name: 'é'.repeat(129), description: 'keep me' })
    updateMock.mockResolvedValueOnce(agent({ description: 'keep me', updatedAt: 6 }))

    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)

    expect(updateMock.mock.calls[0][1]).not.toHaveProperty('name')
    expect(store.fieldErrors.name).toBe('assistants.nameTooLong')
    expect(store.dirty).toBe(true)
  })

  it('reschedules when the open assistant changed during the save', async () => {
    const store = useAgentsStore()
    store.current = agent({ id: 7, name: 'Helper' })

    let resolveFirst: (value: Agent) => void = () => {}
    updateMock.mockImplementationOnce(
      () =>
        new Promise<Agent>((resolve) => {
          resolveFirst = resolve
        })
    )

    store.markDirty()
    await vi.advanceTimersByTimeAsync(600)
    expect(store.saving).toBe(true)

    store.current = agent({ id: 8, name: 'Other', description: 'kept' })
    store.dirty = true

    resolveFirst(agent({ id: 7, name: 'Helper', updatedAt: 2 }))
    await vi.advanceTimersByTimeAsync(0)
    expect(store.current?.id).toBe(8)
    expect(store.dirty).toBe(true)

    updateMock.mockResolvedValueOnce(
      agent({ id: 8, name: 'Other', description: 'kept', updatedAt: 3 })
    )
    await vi.advanceTimersByTimeAsync(600)
    expect(updateMock).toHaveBeenCalledTimes(2)
    expect(updateMock.mock.calls[1][0]).toBe(8)
  })
})
