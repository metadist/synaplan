import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

interface FakeClient {
  connect: ReturnType<typeof vi.fn>
  disconnect: ReturnType<typeof vi.fn>
  options: { onStateChange?: (state: string, error?: string) => void }
}

const fakeClients: FakeClient[] = []

vi.mock('@/services/realtime/RealtimeClient', () => {
  function RealtimeClient(options: FakeClient['options']) {
    const client: FakeClient = {
      connect: vi.fn().mockResolvedValue(undefined),
      disconnect: vi.fn().mockResolvedValue(undefined),
      options,
    }
    fakeClients.push(client)
    return client
  }
  return { RealtimeClient }
})

const getConfigSyncMock = vi.fn().mockReturnValue({
  realtime: { enabled: true, wsUrl: '' },
})

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSyncMock(),
}))

import { useRealtimeStore } from '@/stores/realtime'

describe('useRealtimeStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fakeClients.length = 0
    getConfigSyncMock.mockReturnValue({
      realtime: { enabled: true, wsUrl: '' },
    })
  })

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('starts in disconnected state with no error when realtime is enabled', () => {
    const store = useRealtimeStore()
    expect(store.state).toBe('disconnected')
    expect(store.lastError).toBeNull()
  })

  it('starts in the disabled state when REALTIME_ENABLED is false', () => {
    // Re-mock BEFORE first store access — the store reads the runtime
    // config in its setup function and freezes the initial state.
    getConfigSyncMock.mockReturnValue({
      realtime: { enabled: false, wsUrl: '' },
    })
    const store = useRealtimeStore()
    expect(store.state).toBe('disabled')
  })

  it('reuses the same RealtimeClient across getOrCreateClient calls', () => {
    const store = useRealtimeStore()
    const a = store.getOrCreateClient()
    const b = store.getOrCreateClient()

    expect(a).toBe(b)
    expect(fakeClients).toHaveLength(1)
  })

  it('mirrors RealtimeClient state changes into the reactive store', async () => {
    const store = useRealtimeStore()
    store.getOrCreateClient()
    const onStateChange = fakeClients[0].options.onStateChange!

    onStateChange('connecting')
    expect(store.state).toBe('connecting')

    onStateChange('connected')
    expect(store.state).toBe('connected')
    expect(store.lastError).toBeNull()

    onStateChange('error', 'token-invalid')
    expect(store.state).toBe('error')
    expect(store.lastError).toBe('token-invalid')
  })

  it('ensureConnected calls connect on the underlying client', async () => {
    const store = useRealtimeStore()
    await store.ensureConnected()

    expect(fakeClients[0].connect).toHaveBeenCalledOnce()
  })

  it('disconnect tears down client and resets state', async () => {
    const store = useRealtimeStore()
    store.getOrCreateClient()
    fakeClients[0].options.onStateChange?.('connected')
    expect(store.state).toBe('connected')

    await store.disconnect()
    expect(fakeClients[0].disconnect).toHaveBeenCalledOnce()
    expect(store.state).toBe('disconnected')
    expect(store.lastError).toBeNull()

    // After disconnect, the next getOrCreateClient must build a fresh instance.
    store.getOrCreateClient()
    expect(fakeClients).toHaveLength(2)
  })
})
