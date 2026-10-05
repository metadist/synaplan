import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SchedulerStatus } from '@/services/api/scheduler'

let mockIsAdmin = true
let mockIsNativeApp = false

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return mockIsAdmin
    },
  }),
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => mockIsNativeApp,
}))

const getStatus = vi.fn()

vi.mock('@/services/api/scheduler', () => ({
  schedulerApi: {
    getStatus: () => getStatus(),
  },
}))

import { useSchedulerStore } from '@/stores/scheduler'

function payload(state: SchedulerStatus['state']): SchedulerStatus {
  return {
    state,
    maxAgeSeconds: 180,
    checkedAt: 1_700_000_200,
    lastRunAt: state === 'never' ? null : 1_700_000_100,
    lanes: [],
  }
}

describe('scheduler store', () => {
  beforeEach(() => {
    mockIsAdmin = true
    mockIsNativeApp = false
    getStatus.mockReset()
    setActivePinia(createPinia())
  })

  it('never calls the endpoint for a non-admin', async () => {
    mockIsAdmin = false
    const store = useSchedulerStore()

    await store.ensureLoaded()
    await store.load()

    expect(getStatus).not.toHaveBeenCalled()
    expect(store.canRead).toBe(false)
    expect(store.isStale).toBe(false)
    expect(store.status).toBeNull()
  })

  it('never calls the endpoint inside the native app', async () => {
    mockIsNativeApp = true
    const store = useSchedulerStore()

    await store.ensureLoaded()
    await store.load()

    expect(getStatus).not.toHaveBeenCalled()
    expect(store.canRead).toBe(false)
    expect(store.isStale).toBe(false)
  })

  it('is stale only for an admin whose jobs have stopped', async () => {
    getStatus.mockResolvedValue(payload('stale'))
    const store = useSchedulerStore()

    await store.load()

    expect(store.canRead).toBe(true)
    expect(store.isStale).toBe(true)
    expect(store.loadFailed).toBe(false)
  })

  it('is not stale while jobs are running', async () => {
    getStatus.mockResolvedValue(payload('running'))
    const store = useSchedulerStore()

    await store.load()

    expect(store.isStale).toBe(false)
    expect(store.status?.state).toBe('running')
  })

  it('sets loadFailed and does not throw when the status cannot be read', async () => {
    getStatus.mockRejectedValue(new Error('status store unreachable'))
    const store = useSchedulerStore()

    await expect(store.load()).resolves.toBeUndefined()

    expect(store.loadFailed).toBe(true)
    expect(store.status).toBeNull()
    expect(store.loading).toBe(false)
    expect(store.isStale).toBe(false)
  })

  it('fetches once from ensureLoaded and again when load refreshes', async () => {
    getStatus.mockResolvedValue(payload('running'))
    const store = useSchedulerStore()

    await store.ensureLoaded()
    await store.ensureLoaded()
    expect(getStatus).toHaveBeenCalledTimes(1)

    await store.load()
    expect(getStatus).toHaveBeenCalledTimes(2)
  })
})
