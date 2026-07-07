import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getApiBaseUrl } from '@/services/api/httpClient'

export const GUEST_STORAGE_KEY = 'synaplan_guest_session'
export const GUEST_BANNER_DISMISSED_KEY = 'synaplan_guest_banner_dismissed'

function loadBannerDismissed(): boolean {
  try {
    return localStorage.getItem(GUEST_BANNER_DISMISSED_KEY) === '1'
  } catch {
    return false
  }
}

export const useGuestStore = defineStore('guest', () => {
  const sessionId = ref<string | null>(null)
  const chatId = ref<number | null>(null)
  const messageCount = ref(0)
  const maxMessages = ref(5)
  const limitReached = ref(false)
  const initialized = ref(false)
  const initFailed = ref(false)
  const rateLimited = ref(false)
  const sessionExpired = ref(false)
  // Persisted so a dismissed banner stays gone across app restarts / reloads
  // for the same browser profile (cleared only on logout / session reset).
  const bannerDismissed = ref(loadBannerDismissed())

  const remainingMessages = computed(() => Math.max(0, maxMessages.value - messageCount.value))
  const isGuestMode = computed(() => !!sessionId.value)
  const shouldShowBanner = computed(
    () => isGuestMode.value && !limitReached.value && !bannerDismissed.value
  )

  function loadFromStorage(): string | null {
    try {
      return localStorage.getItem(GUEST_STORAGE_KEY)
    } catch {
      return null
    }
  }

  function saveToStorage(id: string): void {
    try {
      localStorage.setItem(GUEST_STORAGE_KEY, id)
    } catch {
      // localStorage unavailable
    }
  }

  function clearExpiredStorage(): void {
    sessionId.value = null
    chatId.value = null
    try {
      localStorage.removeItem(GUEST_STORAGE_KEY)
    } catch {
      // ignore
    }
  }

  async function initSession(): Promise<void> {
    if (initialized.value) return

    const storedId = loadFromStorage()
    initFailed.value = false

    try {
      const response = await fetch(`${getApiBaseUrl()}/api/v1/guest/session`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sessionId: storedId }),
      })

      if (response.status === 429) {
        rateLimited.value = true
        initFailed.value = true
        initialized.value = true
        return
      }

      if (!response.ok) throw new Error('Failed to init guest session')

      const data = await response.json()
      sessionId.value = data.sessionId
      chatId.value = data.chatId ?? null
      messageCount.value = data.maxMessages - data.remaining
      maxMessages.value = data.maxMessages
      limitReached.value = data.limitReached

      saveToStorage(data.sessionId)
      initialized.value = true
    } catch (err) {
      console.error('Guest session init failed:', err)
      initFailed.value = true
      initialized.value = true
    }
  }

  async function retryInit(): Promise<void> {
    initialized.value = false
    initFailed.value = false
    await initSession()
  }

  async function ensureChat(): Promise<number | null> {
    if (chatId.value) return chatId.value
    if (!sessionId.value) return null

    try {
      const response = await fetch(`${getApiBaseUrl()}/api/v1/guest/chat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sessionId: sessionId.value }),
      })

      if (response.status === 410) {
        sessionExpired.value = true
        initFailed.value = true
        clearExpiredStorage()
        return null
      }

      if (response.status === 404) {
        initFailed.value = true
        return null
      }

      if (!response.ok) throw new Error('Failed to create guest chat')

      const data = await response.json()
      chatId.value = data.chatId
      return data.chatId
    } catch (err) {
      console.error('Guest chat creation failed:', err)
      initFailed.value = true
      return null
    }
  }

  async function loadMessages(): Promise<
    Array<{
      id: number
      text: string
      direction: string
      timestamp: number
      provider: string | null
      topic: string | null
      language: string | null
      createdAt: string | null
      aiModels: Record<string, unknown> | null
      webSearch: Record<string, unknown> | null
      searchResults: Array<Record<string, unknown>> | null
      files: Array<{
        id: number
        filename: string
        fileType: string
        filePath: string
        fileSize: number | null
        fileMime: string | null
      }> | null
    }>
  > {
    if (!sessionId.value || !chatId.value) return []

    try {
      const response = await fetch(`${getApiBaseUrl()}/api/v1/guest/messages/${sessionId.value}`)
      if (!response.ok) return []

      const data = await response.json()
      return data.messages ?? []
    } catch {
      return []
    }
  }

  function updateCount(remaining: number, max: number, reached: boolean): void {
    messageCount.value = max - remaining
    maxMessages.value = max
    limitReached.value = reached
  }

  function dismissBanner(): void {
    bannerDismissed.value = true
    try {
      localStorage.setItem(GUEST_BANNER_DISMISSED_KEY, '1')
    } catch {
      // localStorage unavailable - dismissal just won't persist this session
    }
  }

  function showBanner(): void {
    bannerDismissed.value = false
    try {
      localStorage.removeItem(GUEST_BANNER_DISMISSED_KEY)
    } catch {
      // ignore
    }
  }

  function $reset(): void {
    sessionId.value = null
    chatId.value = null
    messageCount.value = 0
    maxMessages.value = 5
    limitReached.value = false
    initialized.value = false
    initFailed.value = false
    rateLimited.value = false
    sessionExpired.value = false
    bannerDismissed.value = false
    try {
      localStorage.removeItem(GUEST_STORAGE_KEY)
      localStorage.removeItem(GUEST_BANNER_DISMISSED_KEY)
    } catch {
      // ignore
    }
  }

  return {
    sessionId,
    chatId,
    messageCount,
    maxMessages,
    limitReached,
    initialized,
    initFailed,
    rateLimited,
    sessionExpired,
    bannerDismissed,
    remainingMessages,
    isGuestMode,
    shouldShowBanner,
    initSession,
    retryInit,
    ensureChat,
    loadMessages,
    updateCount,
    dismissBanner,
    showBanner,
    $reset,
  }
})
