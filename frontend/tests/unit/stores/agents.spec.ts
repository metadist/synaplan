import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import type { Agent } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'

const updateMock = vi.fn()
vi.mock('@/services/api/agentsApi', () => ({
  agentsApi: {
    update: (...args: unknown[]) => updateMock(...args),
  },
  agentFieldPath: () => null,
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
    draft: { schema: 'agent.v1', behaviour: { starterPrompts: [] } },
    createdAt: 1,
    updatedAt: 1,
    ...overrides,
  } as Agent
}

describe('agents store autosave', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    updateMock.mockReset()
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
  })
})
