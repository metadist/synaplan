import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { getApiBaseUrl, redirectToSetupWizard } from '@/services/api/httpClient'
import type { ApiActiveRun, ApiLoadedMessageRow } from '@/utils/messageMapper'

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
  // GUEST_CHAT_ENABLED=false on the backend: the trial does not exist here.
  const guestChatDisabled = ref(false)
  // Persisted so a dismissed banner stays gone across app restarts / reloads
  // for the same browser profile (cleared only on logout / session reset).
  const bannerDismissed = ref(loadBannerDismissed())
  /** A turn of this session that is still generating on the server. */
  const activeRun = ref<ApiActiveRun | null>(null)

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

      // A virgin installation answers 503 SETUP_REQUIRED everywhere. Rendering
      // the "trial unavailable" card here would strand the visitor: nothing on
      // that screen leads anywhere, because every other endpoint is shut too.
      // The router's setup gate normally catches this earlier, but it depends on
      // a runtime config that may not have loaded yet.
      if (response.status === 503) {
        const body = await response.json().catch(() => null)
        if (body?.code === 'SETUP_REQUIRED') {
          clearExpiredStorage()
          initialized.value = true
          await redirectToSetupWizard()
          return
        }
        throw new Error('Failed to init guest session')
      }

      if (response.status === 403) {
        const body = await response.json().catch(() => null)
        if (body?.code === 'GUEST_CHAT_DISABLED') {
          // Guest chat disabled on this instance (GUEST_CHAT_ENABLED=false):
          // drop the stored key so future navigations route to login instead
          // of retrying a trial that no longer exists.
          clearExpiredStorage()
          guestChatDisabled.value = true
          initFailed.value = true
          initialized.value = true
          return
        }
        throw new Error('Failed to init guest session')
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

  // Returns the canonical API message shape (same serializer as the
  // authenticated chat-history endpoint, issue #1070) so the guest reload path
  // can map rows through `mapApiMessageRow` and keep full parity — including
  // task-plan cards and generated media (video/image/audio).
  async function loadMessages(): Promise<ApiLoadedMessageRow[]> {
    if (!sessionId.value || !chatId.value) return []

    try {
      const response = await fetch(`${getApiBaseUrl()}/api/v1/guest/messages/${sessionId.value}`)
      if (!response.ok) return []

      const data = await response.json()
      // A turn still generating for this session: reloading the page detached
      // the client, but the backend kept the turn alive and buffered its
      // events, so the chat view can re-attach and keep rendering it.
      activeRun.value = (data.activeRun as ApiActiveRun | undefined) ?? null
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
    guestChatDisabled.value = false
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
    activeRun,
    remainingMessages,
    isGuestMode,
    shouldShowBanner,
    guestChatDisabled,
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
