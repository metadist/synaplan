import { setActivePinia, createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// Control the admin gate deterministically (no real auth store / no runtime
// config) so the store logic can be exercised in isolation.
let mockIsAdmin = true

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return mockIsAdmin
    },
  }),
}))

let mockIsNativeApp = false

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => mockIsNativeApp,
}))

const getStatus = vi.fn()
const check = vi.fn()
const dismiss = vi.fn()
const setCheckEnabled = vi.fn()

vi.mock('@/services/api/updates', () => ({
  updatesApi: {
    getStatus: () => getStatus(),
    check: () => check(),
    dismiss: (version: string) => dismiss(version),
    setCheckEnabled: (enabled: boolean) => setCheckEnabled(enabled),
  },
}))

import type { UpdateStatus } from '@/services/api/updates'
import { useUpdatesStore } from '@/stores/updates'

/** A pending, non-dismissed, normal-severity update. */
function statusPayload(overrides: Partial<UpdateStatus> = {}): UpdateStatus {
  return {
    currentVersion: '4.0.12',
    latestVersion: '4.0.13',
    updateAvailable: true,
    notesUrl: 'https://example.test/releases/tag/v4.0.13',
    severity: 'normal',
    releasedAt: '2026-08-10T09:00:00Z',
    lastCheckedAt: '2026-08-05T10:24:22+00:00',
    lastError: null,
    dismissedVersion: null,
    checkEnabled: true,
    platform: 'selfhost',
    guideUrl: 'https://example.test/docs/UPDATE_SELFHOST.md',
    ...overrides,
  }
}

describe('updates store', () => {
  beforeEach(() => {
    mockIsAdmin = true
    mockIsNativeApp = false
    getStatus.mockReset()
    check.mockReset()
    dismiss.mockReset()
    setCheckEnabled.mockReset()
    setActivePinia(createPinia())
  })

  describe('admin gate', () => {
    it('never touches the admin endpoints for a non-admin', async () => {
      mockIsAdmin = false
      const store = useUpdatesStore()

      await store.ensureLoaded()
      await store.checkNow()
      await store.dismissLatest()
      await store.setCheckEnabled(false)

      expect(getStatus).not.toHaveBeenCalled()
      expect(check).not.toHaveBeenCalled()
      expect(dismiss).not.toHaveBeenCalled()
      expect(setCheckEnabled).not.toHaveBeenCalled()
      expect(store.status).toBeNull()
      expect(store.showBadge).toBe(false)
    })

    // MOBILE-APP SEAM: an update means changing a version on the server and
    // redeploying it, which cannot be done from the app, so the notice must not
    // appear there at all.
    it('never touches the admin endpoints inside the native app', async () => {
      mockIsNativeApp = true
      const store = useUpdatesStore()

      await store.ensureLoaded()
      await store.checkNow()
      await store.dismissLatest()
      await store.setCheckEnabled(false)

      expect(getStatus).not.toHaveBeenCalled()
      expect(check).not.toHaveBeenCalled()
      expect(dismiss).not.toHaveBeenCalled()
      expect(setCheckEnabled).not.toHaveBeenCalled()
      expect(store.canRead).toBe(false)
      expect(store.showBadge).toBe(false)
    })
  })

  describe('caching', () => {
    it('fetches once, so the sidebar does not refetch on every navigation', async () => {
      getStatus.mockResolvedValue(statusPayload())
      const store = useUpdatesStore()

      await store.ensureLoaded()
      await store.ensureLoaded()
      await store.ensureLoaded()

      expect(getStatus).toHaveBeenCalledTimes(1)
    })

    it('de-duplicates concurrent callers', async () => {
      getStatus.mockResolvedValue(statusPayload())
      const store = useUpdatesStore()

      await Promise.all([store.ensureLoaded(), store.ensureLoaded(), store.ensureLoaded()])

      expect(getStatus).toHaveBeenCalledTimes(1)
    })
  })

  describe('badge visibility', () => {
    it('shows for a pending update and exposes the guide link', async () => {
      getStatus.mockResolvedValue(statusPayload())
      const store = useUpdatesStore()

      await store.ensureLoaded()

      expect(store.showBadge).toBe(true)
      expect(store.latestVersion).toBe('4.0.13')
      expect(store.guideUrl).toBe('https://example.test/docs/UPDATE_SELFHOST.md')
      expect(store.severity).toBe('normal')
    })

    it('stays hidden for the version the admin dismissed', async () => {
      getStatus.mockResolvedValue(statusPayload({ dismissedVersion: '4.0.13' }))
      const store = useUpdatesStore()

      await store.ensureLoaded()

      expect(store.showBadge).toBe(false)
      // The version itself is still known — only the badge is suppressed.
      expect(store.latestVersion).toBe('4.0.13')
    })

    it('reappears once a release newer than the dismissed one is published', async () => {
      getStatus.mockResolvedValue(
        statusPayload({ latestVersion: '4.0.14', dismissedVersion: '4.0.13' })
      )
      const store = useUpdatesStore()

      await store.ensureLoaded()

      expect(store.showBadge).toBe(true)
    })

    it('stays hidden when no release is known yet', async () => {
      getStatus.mockResolvedValue(
        statusPayload({
          latestVersion: null,
          updateAvailable: false,
          notesUrl: null,
          releasedAt: null,
          lastCheckedAt: null,
        })
      )
      const store = useUpdatesStore()

      await store.ensureLoaded()

      expect(store.showBadge).toBe(false)
      expect(store.latestVersion).toBeNull()
    })

    it('needs a version to link to, even if the backend claims an update', async () => {
      getStatus.mockResolvedValue(statusPayload({ latestVersion: null, updateAvailable: true }))
      const store = useUpdatesStore()

      await store.ensureLoaded()

      expect(store.showBadge).toBe(false)
    })

    it('marks a security release as more important', async () => {
      getStatus.mockResolvedValue(statusPayload({ severity: 'security' }))
      const store = useUpdatesStore()

      await store.ensureLoaded()

      expect(store.showBadge).toBe(true)
      expect(store.severity).toBe('security')
    })
  })

  describe('master switch', () => {
    it('reads as on until the real value is known', () => {
      expect(useUpdatesStore().checkEnabled).toBe(true)
    })

    it('exposes a switched-off check and still reports the known release', async () => {
      getStatus.mockResolvedValue(statusPayload({ checkEnabled: false }))
      const store = useUpdatesStore()

      await store.ensureLoaded()

      expect(store.checkEnabled).toBe(false)
      // Detection being off does not retroactively hide what was already found.
      expect(store.showBadge).toBe(true)
    })

    it('stores the value the backend confirmed, not the requested one', async () => {
      getStatus.mockResolvedValue(statusPayload())
      setCheckEnabled.mockResolvedValue({ success: true, checkEnabled: false })
      const store = useUpdatesStore()
      await store.ensureLoaded()

      await store.setCheckEnabled(false)

      expect(setCheckEnabled).toHaveBeenCalledWith(false)
      expect(store.checkEnabled).toBe(false)
    })
  })

  describe('dismissing', () => {
    it('acknowledges the offered version and hides the badge', async () => {
      getStatus.mockResolvedValue(statusPayload())
      dismiss.mockResolvedValue({ success: true, dismissedVersion: '4.0.13' })
      const store = useUpdatesStore()
      await store.ensureLoaded()

      await store.dismissLatest()

      expect(dismiss).toHaveBeenCalledWith('4.0.13')
      expect(store.showBadge).toBe(false)
    })

    it('does nothing when there is no release to acknowledge', async () => {
      getStatus.mockResolvedValue(statusPayload({ latestVersion: null, updateAvailable: false }))
      const store = useUpdatesStore()
      await store.ensureLoaded()

      await store.dismissLatest()

      expect(dismiss).not.toHaveBeenCalled()
    })
  })

  describe('failing requests', () => {
    it('degrades quietly when the stored status cannot be read', async () => {
      getStatus.mockRejectedValue(new Error('network unreachable'))
      const store = useUpdatesStore()

      await expect(store.ensureLoaded()).resolves.toBeUndefined()

      expect(store.status).toBeNull()
      expect(store.showBadge).toBe(false)
      expect(store.loading).toBe(false)
    })

    it('does not retry a failed read on every navigation', async () => {
      getStatus.mockRejectedValue(new Error('network unreachable'))
      const store = useUpdatesStore()

      await store.ensureLoaded()
      await store.ensureLoaded()

      expect(getStatus).toHaveBeenCalledTimes(1)
    })

    it('reports a failed manual check to the caller', async () => {
      getStatus.mockResolvedValue(statusPayload())
      check.mockRejectedValue(new Error('gateway timeout'))
      const store = useUpdatesStore()
      await store.ensureLoaded()

      await expect(store.checkNow()).rejects.toThrow('gateway timeout')

      expect(store.loading).toBe(false)
    })

    it('keeps the known release and surfaces lastError when a check found nothing', async () => {
      getStatus.mockResolvedValue(statusPayload())
      check.mockResolvedValue(
        statusPayload({ lastError: 'Could not reach the release list.', lastCheckedAt: 'now' })
      )
      const store = useUpdatesStore()
      await store.ensureLoaded()

      await store.checkNow()

      expect(store.status?.lastError).toBe('Could not reach the release list.')
      expect(store.showBadge).toBe(true)
    })
  })
})
