import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useIncognitoStore } from '@/stores/incognito'
import { httpClient, getApiBaseUrl } from '@/services/api/httpClient'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn().mockResolvedValue({}),
  getApiBaseUrl: vi.fn(() => 'http://backend.test'),
}))

describe('Incognito Store', () => {
  let activeStore: ReturnType<typeof useIncognitoStore> | null = null

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    activeStore = null
  })

  afterEach(async () => {
    // startSession() registers a window `pagehide` listener; end any session
    // left open by a test so listeners never leak into the next test.
    if (activeStore?.active) {
      await activeStore.endSession()
    }
    vi.restoreAllMocks()
  })

  it('is inactive with no tracked files initially', () => {
    const store = useIncognitoStore()
    activeStore = store
    expect(store.active).toBe(false)
    expect(store.ephemeralFileIds).toEqual([])
    expect(store.hasEphemeralFiles).toBe(false)
  })

  it('startSession activates and resets tracked files', () => {
    const store = useIncognitoStore()
    activeStore = store
    store.startSession()

    expect(store.active).toBe(true)
    expect(store.ephemeralFileIds).toEqual([])
  })

  it('registerFile tracks ids only during an active session, without duplicates', () => {
    const store = useIncognitoStore()
    activeStore = store

    store.registerFile(5)
    expect(store.ephemeralFileIds).toEqual([])

    store.startSession()
    store.registerFile(5)
    store.registerFile(5)
    store.registerFile(9)
    store.registerFile(0)
    store.registerFile(-3)

    expect(store.ephemeralFileIds).toEqual([5, 9])
    expect(store.hasEphemeralFiles).toBe(true)
  })

  it('endSession deletes every tracked file and clears state', async () => {
    const store = useIncognitoStore()
    activeStore = store
    store.startSession()
    store.registerFile(11)
    store.registerFile(22)

    await store.endSession()

    expect(store.active).toBe(false)
    expect(store.ephemeralFileIds).toEqual([])
    expect(httpClient).toHaveBeenCalledTimes(2)
    expect(httpClient).toHaveBeenCalledWith('/api/v1/files/11', { method: 'DELETE' })
    expect(httpClient).toHaveBeenCalledWith('/api/v1/files/22', { method: 'DELETE' })
  })

  it('endSession swallows individual delete failures (reaper is the safety net)', async () => {
    vi.mocked(httpClient).mockRejectedValueOnce(new Error('network down'))

    const store = useIncognitoStore()
    activeStore = store
    store.startSession()
    store.registerFile(11)
    store.registerFile(22)

    await expect(store.endSession()).resolves.toBeUndefined()
    expect(httpClient).toHaveBeenCalledTimes(2)
  })

  it('endSession is a no-op when not active', async () => {
    const store = useIncognitoStore()
    activeStore = store
    await store.endSession()

    expect(httpClient).not.toHaveBeenCalled()
  })

  it('pagehide fires keepalive DELETE requests for tracked files', () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response())

    const store = useIncognitoStore()
    activeStore = store
    store.startSession()
    store.registerFile(7)

    window.dispatchEvent(new Event('pagehide'))

    expect(getApiBaseUrl).toHaveBeenCalled()
    expect(fetchSpy).toHaveBeenCalledWith('http://backend.test/api/v1/files/7', {
      method: 'DELETE',
      credentials: 'include',
      keepalive: true,
    })
  })

  it('pagehide listener is removed after endSession', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response())

    const store = useIncognitoStore()
    activeStore = store
    store.startSession()
    store.registerFile(7)
    await store.endSession()

    window.dispatchEvent(new Event('pagehide'))

    expect(fetchSpy).not.toHaveBeenCalled()
  })
})
