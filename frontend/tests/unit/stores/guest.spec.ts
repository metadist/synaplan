import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useGuestStore } from '@/stores/guest'

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://localhost:8000',
}))

describe('Guest Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('should initialize with default values', () => {
    const store = useGuestStore()

    expect(store.sessionId).toBeNull()
    expect(store.chatId).toBeNull()
    expect(store.messageCount).toBe(0)
    expect(store.maxMessages).toBe(5)
    expect(store.limitReached).toBe(false)
    expect(store.initialized).toBe(false)
    expect(store.bannerDismissed).toBe(false)
  })

  it('should compute remainingMessages correctly', () => {
    const store = useGuestStore()

    expect(store.remainingMessages).toBe(5)

    store.messageCount = 3
    expect(store.remainingMessages).toBe(2)

    store.messageCount = 5
    expect(store.remainingMessages).toBe(0)

    store.messageCount = 7
    expect(store.remainingMessages).toBe(0)
  })

  it('should compute isGuestMode correctly', () => {
    const store = useGuestStore()

    expect(store.isGuestMode).toBe(false)

    store.sessionId = 'some-session-id'
    expect(store.isGuestMode).toBe(true)

    store.sessionId = null
    expect(store.isGuestMode).toBe(false)
  })

  it('should compute shouldShowBanner correctly', () => {
    const store = useGuestStore()

    expect(store.shouldShowBanner).toBe(false)

    store.sessionId = 'abc'
    expect(store.shouldShowBanner).toBe(true)

    store.limitReached = true
    expect(store.shouldShowBanner).toBe(false)

    store.limitReached = false
    store.bannerDismissed = true
    expect(store.shouldShowBanner).toBe(false)
  })

  it('should update count via updateCount()', () => {
    const store = useGuestStore()

    store.updateCount(3, 5, false)
    expect(store.messageCount).toBe(2)
    expect(store.maxMessages).toBe(5)
    expect(store.limitReached).toBe(false)

    store.updateCount(0, 5, true)
    expect(store.messageCount).toBe(5)
    expect(store.limitReached).toBe(true)
  })

  it('should dismiss and show banner', () => {
    const store = useGuestStore()

    expect(store.bannerDismissed).toBe(false)

    store.dismissBanner()
    expect(store.bannerDismissed).toBe(true)

    store.showBanner()
    expect(store.bannerDismissed).toBe(false)
  })

  it('should reset all state', () => {
    const store = useGuestStore()
    store.sessionId = 'test-id'
    store.chatId = 42
    store.messageCount = 3
    store.maxMessages = 10
    store.limitReached = true
    store.initialized = true
    store.bannerDismissed = true

    store.$reset()

    expect(store.sessionId).toBeNull()
    expect(store.chatId).toBeNull()
    expect(store.messageCount).toBe(0)
    expect(store.maxMessages).toBe(5)
    expect(store.limitReached).toBe(false)
    expect(store.initialized).toBe(false)
    expect(store.bannerDismissed).toBe(false)
  })

  it('should clear localStorage on reset', () => {
    const store = useGuestStore()
    localStorage.setItem('synaplan_guest_session', 'old-id')

    store.$reset()

    expect(localStorage.getItem('synaplan_guest_session')).toBeNull()
  })

  describe('initSession()', () => {
    it('should fetch and set session data', async () => {
      const mockResponse = {
        sessionId: 'new-session-uuid',
        remaining: 5,
        maxMessages: 5,
        limitReached: false,
      }
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve(mockResponse),
      })

      const store = useGuestStore()
      await store.initSession()

      expect(store.sessionId).toBe('new-session-uuid')
      expect(store.messageCount).toBe(0)
      expect(store.maxMessages).toBe(5)
      expect(store.limitReached).toBe(false)
      expect(store.initialized).toBe(true)
      expect(localStorage.getItem('synaplan_guest_session')).toBe('new-session-uuid')
    })

    it('should not reinitialize if already initialized', async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            sessionId: 'first-call',
            remaining: 5,
            maxMessages: 5,
            limitReached: false,
          }),
      })

      const store = useGuestStore()
      await store.initSession()
      expect(store.sessionId).toBe('first-call')

      // Second call should be a no-op
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            sessionId: 'second-call',
            remaining: 4,
            maxMessages: 5,
            limitReached: false,
          }),
      })

      await store.initSession()
      expect(store.sessionId).toBe('first-call')
    })

    it('should send stored sessionId from localStorage', async () => {
      localStorage.setItem('synaplan_guest_session', 'stored-uuid')

      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () =>
          Promise.resolve({
            sessionId: 'stored-uuid',
            remaining: 3,
            maxMessages: 5,
            limitReached: false,
          }),
      })

      const store = useGuestStore()
      await store.initSession()

      expect(global.fetch).toHaveBeenCalledWith(
        'http://localhost:8000/api/v1/guest/session',
        expect.objectContaining({
          body: JSON.stringify({ sessionId: 'stored-uuid' }),
        })
      )
      expect(store.messageCount).toBe(2)
    })

    it('should handle API failure gracefully', async () => {
      global.fetch = vi.fn().mockResolvedValue({ ok: false, status: 500 })

      const store = useGuestStore()
      await store.initSession()

      expect(store.initialized).toBe(false)
      expect(store.sessionId).toBeNull()
    })
  })

  describe('ensureChat()', () => {
    it('should return existing chatId without API call', async () => {
      const store = useGuestStore()
      store.sessionId = 'abc'
      store.chatId = 42

      global.fetch = vi.fn()

      const result = await store.ensureChat()

      expect(result).toBe(42)
      expect(global.fetch).not.toHaveBeenCalled()
    })

    it('should create a new chat and return its id', async () => {
      global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ chatId: 99 }),
      })

      const store = useGuestStore()
      store.sessionId = 'session-xyz'

      const result = await store.ensureChat()

      expect(result).toBe(99)
      expect(store.chatId).toBe(99)
      expect(global.fetch).toHaveBeenCalledWith(
        'http://localhost:8000/api/v1/guest/chat',
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({ sessionId: 'session-xyz' }),
        })
      )
    })

    it('should return null when no sessionId is set', async () => {
      const store = useGuestStore()

      const result = await store.ensureChat()

      expect(result).toBeNull()
    })

    it('should return null on API failure', async () => {
      global.fetch = vi.fn().mockResolvedValue({ ok: false, status: 500 })

      const store = useGuestStore()
      store.sessionId = 'abc'

      const result = await store.ensureChat()

      expect(result).toBeNull()
    })
  })
})
