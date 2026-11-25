import { beforeEach, describe, expect, it, vi } from 'vitest'

type Listener = (event: { url: string }) => void

const native = vi.hoisted(() => ({ value: false }))
const listeners = vi.hoisted(() => ({
  url: undefined as Listener | undefined,
  finished: undefined as (() => void) | undefined,
}))
const removeUrl = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const browserOpen = vi.hoisted(() => vi.fn())
const browserClose = vi.hoisted(() => vi.fn().mockResolvedValue(undefined))
const mockHttp = vi.hoisted(() => vi.fn())

vi.mock('@/services/api/nativeRuntime', () => ({ isNativeApp: () => native.value }))
vi.mock('@/services/api/httpClient', () => ({ httpClient: mockHttp }))
vi.mock('@capacitor/app', () => ({
  App: {
    addListener: vi.fn(async (_name: string, cb: Listener) => {
      listeners.url = cb
      return { remove: removeUrl }
    }),
  },
}))
vi.mock('@capacitor/browser', () => ({
  Browser: {
    open: browserOpen,
    close: browserClose,
    addListener: vi.fn(async (_name: string, cb: () => void) => {
      listeners.finished = cb
      return { remove: vi.fn().mockResolvedValue(undefined) }
    }),
  },
}))

import { consentStartParams, openConsent } from '@/services/api/connectorConsent'

const CONSENT_URL = 'https://login.example.com/authorize?state=abc'
const assign = vi.fn()

/** Let the listener registrations settle, as they do before a person can act. */
const settle = async () => {
  await Promise.resolve()
  await Promise.resolve()
}

describe('connectorConsent', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useRealTimers()
    native.value = false
    listeners.url = undefined
    listeners.finished = undefined
    browserOpen.mockResolvedValue(undefined)
    Object.defineProperty(window, 'location', { value: { assign }, writable: true })
  })

  describe('on the web', () => {
    it('navigates to the consent page and adds nothing to the start call', async () => {
      expect(consentStartParams()).toBeUndefined()
      await expect(openConsent(CONSENT_URL)).resolves.toBe(true)

      expect(assign).toHaveBeenCalledWith(CONSENT_URL)
      expect(browserOpen).not.toHaveBeenCalled()
    })
  })

  describe('in the native app', () => {
    beforeEach(() => {
      native.value = true
    })

    it('asks the backend for an account-bound state', () => {
      expect(consentStartParams()).toEqual({ native: '1' })
    })

    it('opens the system browser, replays the answer with its own token, and shows the result', async () => {
      mockHttp.mockResolvedValue({ redirect: '/channels/mcp?connected=12' })

      const done = openConsent(CONSENT_URL)
      await settle()
      expect(browserOpen).toHaveBeenCalledWith({ url: CONSENT_URL })
      expect(assign).not.toHaveBeenCalled()

      listeners.url?.({
        url: 'com.synaplan.app://oauth/connect?connector=mcp&code=c0de&state=st&iss=https%3A%2F%2Fmcp.example',
      })

      await expect(done).resolves.toBe(true)
      expect(mockHttp).toHaveBeenCalledWith(
        '/api/v1/mcp-servers/oauth/callback',
        expect.objectContaining({
          params: { code: 'c0de', state: 'st', iss: 'https://mcp.example' },
          headers: { Accept: 'application/json' },
        })
      )
      expect(assign).toHaveBeenCalledWith('/channels/mcp?connected=12')
      expect(removeUrl).toHaveBeenCalled()
      expect(browserClose).toHaveBeenCalled()
    })

    it('ignores deep links that are not a consent return', async () => {
      mockHttp.mockResolvedValue({ redirect: '/channels/connections?m365=connected' })

      const done = openConsent(CONSENT_URL)
      await settle()
      listeners.url?.({ url: 'com.synaplan.app://oauth/callback?handoff=abc' })
      await settle()
      expect(mockHttp).not.toHaveBeenCalled()

      listeners.url?.({ url: 'com.synaplan.app://oauth/connect?connector=m365&code=c&state=s' })
      await expect(done).resolves.toBe(true)
      expect(mockHttp).toHaveBeenCalledTimes(1)
    })

    it('refuses a return link for a callback it does not know', async () => {
      const done = openConsent(CONSENT_URL)
      await settle()
      listeners.url?.({
        url: 'com.synaplan.app://oauth/connect?connector=..%2Fauth%2Flogout&code=c&state=s',
      })

      await expect(done).rejects.toThrow('Unknown connector')
      expect(mockHttp).not.toHaveBeenCalled()
      expect(assign).not.toHaveBeenCalled()
    })

    it('refuses a result that would leave the app', async () => {
      mockHttp.mockResolvedValue({ redirect: '//evil.example/x' })

      const done = openConsent(CONSENT_URL)
      await settle()
      listeners.url?.({ url: 'com.synaplan.app://oauth/connect?connector=dropbox&code=c&state=s' })

      await expect(done).rejects.toThrow('Unexpected return path')
      expect(assign).not.toHaveBeenCalled()
    })

    it('reports a closed browser as not finished, so the button comes back', async () => {
      vi.useFakeTimers()
      const done = openConsent(CONSENT_URL)
      await settle()

      listeners.finished?.()
      await vi.advanceTimersByTimeAsync(800)

      await expect(done).resolves.toBe(false)
      expect(mockHttp).not.toHaveBeenCalled()
      expect(assign).not.toHaveBeenCalled()
    })

    it('lets a deep link that arrives just after the browser closed still win', async () => {
      vi.useFakeTimers()
      mockHttp.mockResolvedValue({ redirect: '/apps/notion?connected=3' })
      const done = openConsent(CONSENT_URL)
      await settle()

      listeners.finished?.()
      await vi.advanceTimersByTimeAsync(300)
      listeners.url?.({ url: 'com.synaplan.app://oauth/connect?connector=mcp&code=c&state=s' })
      await vi.advanceTimersByTimeAsync(800)

      await expect(done).resolves.toBe(true)
      expect(assign).toHaveBeenCalledWith('/apps/notion?connected=3')
    })

    it('fails when the browser cannot be opened', async () => {
      browserOpen.mockRejectedValue(new Error('no browser'))

      await expect(openConsent(CONSENT_URL)).rejects.toThrow('Could not open the browser')
    })
  })
})
