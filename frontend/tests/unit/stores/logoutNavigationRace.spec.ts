/**
 * Regression guard for the logout navigation race.
 *
 * Logout hands the browser a destination (for OIDC a full-page navigation to
 * the provider's end-session endpoint). Store code that reacted to the
 * momentarily missing user by assigning `window.location.href` replaced that
 * pending navigation: the end-session request died as `net::ERR_ABORTED`, the
 * user landed on /login instead of /logged-out, and the provider session
 * stayed open. It also let a chat be created after the logout click.
 *
 * The contract asserted here: while a teardown is in progress the stores stay
 * silent — no navigation, no protected request — and ordinary unauthenticated
 * handling is untouched outside that window.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore } from '@/stores/history'
import {
  beginSessionTeardown,
  endSessionTeardown,
  isSessionTerminating,
} from '@/services/sessionTeardown'

const isAuthenticatedMock = vi.hoisted(() => vi.fn(() => true))
vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => isAuthenticatedMock(),
  },
}))

const httpClientMock = vi.hoisted(() => vi.fn())
vi.mock('@/services/api/httpClient', () => ({
  httpClient: httpClientMock,
}))

vi.mock('@/composables/useIamFeature', () => ({
  isIamSharingEnabled: () => false,
  isIamGroupsEnabled: () => false,
}))

const incomingLoaded = ref(false)
vi.mock('@/stores/incoming', () => ({
  useIncomingStore: () => ({
    get loaded() {
      return incomingLoaded.value
    },
    isOpenable: () => false,
  }),
}))

let hrefWrites: string[] = []

describe('logout navigation race', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.clearAllMocks()
    isAuthenticatedMock.mockReturnValue(true)
    httpClientMock.mockResolvedValue({ chats: [] })

    // The stores navigate by assigning window.location.href; record the
    // assignments instead of letting the environment act on them.
    hrefWrites = []
    Object.defineProperty(window, 'location', {
      configurable: true,
      writable: true,
      value: {
        href: 'http://localhost:5173/',
        pathname: '/',
        origin: 'http://localhost:5173',
      },
    })
    Object.defineProperty(window.location, 'href', {
      configurable: true,
      get: () => 'http://localhost:5173/',
      set: (next: string) => {
        hrefWrites.push(next)
      },
    })
  })

  afterEach(() => {
    endSessionTeardown()
  })

  it('keeps the unauthenticated redirect outside a teardown', async () => {
    isAuthenticatedMock.mockReturnValue(false)

    await useChatsStore().loadChats()

    expect(hrefWrites).toEqual(['/login?reason=auth_required'])
    expect(httpClientMock).not.toHaveBeenCalled()
  })

  it('reports a prior session as expired rather than missing', async () => {
    isAuthenticatedMock.mockReturnValue(false)
    localStorage.setItem('sh', '1')

    await useChatsStore().loadChats()

    expect(hrefWrites).toEqual(['/login?reason=session_expired'])
  })

  it('does not navigate while a logout owns the next navigation', async () => {
    isAuthenticatedMock.mockReturnValue(false)
    beginSessionTeardown()

    await useChatsStore().loadChats()
    await useHistoryStore().loadMessages(7)

    expect(hrefWrites).toEqual([])
    expect(httpClientMock).not.toHaveBeenCalled()
  })

  it('does not create a chat once a logout has started', async () => {
    // The logout POST is still in flight, so the client still counts as
    // authenticated — this is the window in which a stray chat was created.
    beginSessionTeardown()

    const chat = await useChatsStore().createChat('after logout')

    expect(chat).toBeNull()
    expect(httpClientMock).not.toHaveBeenCalled()
  })

  it('restores ordinary handling once a principal is signed in again', async () => {
    beginSessionTeardown()
    expect(isSessionTerminating()).toBe(true)

    endSessionTeardown()
    isAuthenticatedMock.mockReturnValue(false)
    await useChatsStore().loadChats()

    expect(hrefWrites).toEqual(['/login?reason=auth_required'])
  })
})
