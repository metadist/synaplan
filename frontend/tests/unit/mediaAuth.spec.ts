import { describe, it, expect, beforeEach, vi } from 'vitest'
import { computed } from 'vue'
import { flushPromises } from '@vue/test-utils'

const runtime = vi.hoisted(() => ({ native: false, baseUrl: 'https://web.synaplan.com' }))
const auth = vi.hoisted(() => ({ token: null as string | null }))
const refreshAccessToken = vi.hoisted(() => vi.fn(async () => ({ success: true })))
const saveOrDownloadBlob = vi.hoisted(() => vi.fn(async () => undefined))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => runtime.native,
  getNativeApiBaseUrl: () => runtime.baseUrl,
}))

vi.mock('@/services/api/nativeAuth', () => ({ getNativeAccessToken: () => auth.token }))

vi.mock('@/services/api/httpClient', () => ({ refreshAccessToken }))
vi.mock('@/services/api/nativeDownload', () => ({ saveOrDownloadBlob }))

import {
  authenticatedMediaSrc,
  downloadMediaUrl,
  fetchMediaBlob,
  needsAuthenticatedMediaFetch,
  resolveMediaUrl,
  useMediaSrc,
} from '@/services/api/mediaAuth'

const UPLOAD_PATH = '/api/v1/files/uploads/13/000/cat.png'

/**
 * Mirror `TokenService::encodeToken`: `base64(jsonPayload).hmacSignature`.
 * mediaAuth reads the `exp` claim to refresh before a URL goes stale.
 */
const signedToken = (secondsUntilExpiry: number): string =>
  `${btoa(JSON.stringify({ user_id: 1, exp: Math.floor(Date.now() / 1000) + secondsUntilExpiry }))}.sig`

/** A minimal Response stand-in; only the fields mediaAuth reads. */
const mediaResponse = (status: number): Response =>
  ({
    ok: status >= 200 && status < 300,
    status,
    statusText: `status ${status}`,
    blob: () => Promise.resolve(new Blob(['bytes'])),
  }) as unknown as Response

describe('mediaAuth', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    runtime.native = false
    runtime.baseUrl = 'https://web.synaplan.com'
    auth.token = null
    refreshAccessToken.mockClear()
    refreshAccessToken.mockResolvedValue({ success: true })
    saveOrDownloadBlob.mockClear()
  })

  describe('resolveMediaUrl', () => {
    it('leaves a root-relative path untouched on web (same origin)', () => {
      expect(resolveMediaUrl(UPLOAD_PATH)).toBe(UPLOAD_PATH)
    })

    it('prefixes the configured server on native, where the origin is the app bundle', () => {
      runtime.native = true
      expect(resolveMediaUrl(UPLOAD_PATH)).toBe(`https://web.synaplan.com${UPLOAD_PATH}`)
    })

    it('honours a server override', () => {
      runtime.native = true
      runtime.baseUrl = 'https://staging.example.com'
      expect(resolveMediaUrl(UPLOAD_PATH)).toBe(`https://staging.example.com${UPLOAD_PATH}`)
    })

    it('passes absolute, data and blob URLs through unchanged', () => {
      runtime.native = true
      expect(resolveMediaUrl('https://cdn.example.com/a.png')).toBe('https://cdn.example.com/a.png')
      expect(resolveMediaUrl('data:image/png;base64,AAA')).toBe('data:image/png;base64,AAA')
      expect(resolveMediaUrl('blob:capacitor://localhost/x')).toBe('blob:capacitor://localhost/x')
    })

    it('returns an empty string for missing input', () => {
      expect(resolveMediaUrl(null)).toBe('')
      expect(resolveMediaUrl(undefined)).toBe('')
    })

    it('rewrites the legacy /up/ route to the token-aware uploads route when signed in', () => {
      runtime.native = true
      auth.token = 'tok'
      expect(resolveMediaUrl('/up/13/000/cat.png')).toBe(
        'https://web.synaplan.com/api/v1/files/uploads/13/000/cat.png'
      )
    })

    it('keeps /up/ for an anonymous native viewer, whose share link still works there', () => {
      runtime.native = true
      expect(resolveMediaUrl('/up/13/000/cat.png')).toBe(
        'https://web.synaplan.com/up/13/000/cat.png'
      )
    })

    it('never rewrites /up/ on web', () => {
      auth.token = 'tok'
      expect(resolveMediaUrl('/up/13/000/cat.png')).toBe('/up/13/000/cat.png')
    })
  })

  describe('authenticatedMediaSrc', () => {
    it('is a no-op on web', () => {
      auth.token = 'tok'
      expect(authenticatedMediaSrc(UPLOAD_PATH)).toBe(UPLOAD_PATH)
    })

    it('appends the access token for backend URLs on native', () => {
      runtime.native = true
      auth.token = 'tok en/+'
      expect(authenticatedMediaSrc(UPLOAD_PATH)).toBe(
        `https://web.synaplan.com${UPLOAD_PATH}?token=${encodeURIComponent('tok en/+')}`
      )
    })

    it('uses & when the URL already carries a query', () => {
      runtime.native = true
      auth.token = 'tok'
      expect(authenticatedMediaSrc(`${UPLOAD_PATH}?v=2`)).toContain('?v=2&token=tok')
    })

    it('never leaks the token to an external host', () => {
      runtime.native = true
      auth.token = 'tok'
      expect(authenticatedMediaSrc('https://cdn.example.com/a.png')).toBe(
        'https://cdn.example.com/a.png'
      )
    })

    it('returns the plain URL when no token is stored', () => {
      runtime.native = true
      expect(authenticatedMediaSrc(UPLOAD_PATH)).toBe(`https://web.synaplan.com${UPLOAD_PATH}`)
    })
  })

  describe('needsAuthenticatedMediaFetch', () => {
    it('is false on web', () => {
      expect(needsAuthenticatedMediaFetch(UPLOAD_PATH)).toBe(false)
    })

    it('is true for backend URLs on native, false for external ones', () => {
      runtime.native = true
      expect(needsAuthenticatedMediaFetch(UPLOAD_PATH)).toBe(true)
      expect(needsAuthenticatedMediaFetch(`https://web.synaplan.com${UPLOAD_PATH}`)).toBe(true)
      expect(needsAuthenticatedMediaFetch('https://cdn.example.com/a.png')).toBe(false)
    })
  })

  describe('fetchMediaBlob', () => {
    it('sends cookies on web and no Authorization header', async () => {
      const fetchMock = vi.fn(async () => mediaResponse(200))
      global.fetch = fetchMock as unknown as typeof fetch

      await fetchMediaBlob(UPLOAD_PATH)

      expect(fetchMock).toHaveBeenCalledWith(
        UPLOAD_PATH,
        expect.objectContaining({ credentials: 'include', headers: {} })
      )
    })

    it('sends the Bearer token and omits cookies on native', async () => {
      runtime.native = true
      auth.token = 'tok'
      const fetchMock = vi.fn(async () => mediaResponse(200))
      global.fetch = fetchMock as unknown as typeof fetch

      await fetchMediaBlob(UPLOAD_PATH)

      expect(fetchMock).toHaveBeenCalledWith(
        `https://web.synaplan.com${UPLOAD_PATH}`,
        expect.objectContaining({
          credentials: 'omit',
          headers: { Authorization: 'Bearer tok' },
        })
      )
    })

    it('refreshes the credential before the request when its exp claim is near', async () => {
      runtime.native = true
      auth.token = signedToken(5)
      global.fetch = vi.fn(async () => mediaResponse(200)) as unknown as typeof fetch

      await fetchMediaBlob(UPLOAD_PATH)

      expect(refreshAccessToken).toHaveBeenCalledTimes(1)
    })

    it('leaves a token with plenty of life alone', async () => {
      runtime.native = true
      auth.token = signedToken(600)
      global.fetch = vi.fn(async () => mediaResponse(200)) as unknown as typeof fetch

      await fetchMediaBlob(UPLOAD_PATH)

      expect(refreshAccessToken).not.toHaveBeenCalled()
    })

    it('refreshes and retries once on 401 — the access token outlives no idle chat', async () => {
      vi.useFakeTimers()
      const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(mediaResponse(401))
        .mockResolvedValueOnce(mediaResponse(200))
      global.fetch = fetchMock as unknown as typeof fetch

      const pending = fetchMediaBlob(UPLOAD_PATH)
      await vi.runAllTimersAsync()
      await expect(pending).resolves.toBeInstanceOf(Blob)

      expect(refreshAccessToken).toHaveBeenCalledTimes(1)
      expect(fetchMock).toHaveBeenCalledTimes(2)
      vi.useRealTimers()
    })

    it('does not retry a 404 — a missing file is not an auth problem', async () => {
      const fetchMock = vi.fn(async () => mediaResponse(404))
      global.fetch = fetchMock as unknown as typeof fetch

      await expect(fetchMediaBlob(UPLOAD_PATH)).rejects.toThrow('HTTP 404')
      expect(fetchMock).toHaveBeenCalledTimes(1)
      expect(refreshAccessToken).not.toHaveBeenCalled()
    })

    it('throws when the retry is rejected too', async () => {
      vi.useFakeTimers()
      global.fetch = vi.fn(async () => mediaResponse(401)) as unknown as typeof fetch

      const pending = fetchMediaBlob(UPLOAD_PATH)
      const assertion = expect(pending).rejects.toThrow('HTTP 401')
      await vi.runAllTimersAsync()
      await assertion
      vi.useRealTimers()
    })
  })

  describe('downloadMediaUrl', () => {
    it('hands the authenticated blob to the platform saver', async () => {
      global.fetch = vi.fn(async () => mediaResponse(200)) as unknown as typeof fetch

      await downloadMediaUrl(UPLOAD_PATH, 'cat.png')

      expect(saveOrDownloadBlob).toHaveBeenCalledWith(expect.any(Blob), 'cat.png')
    })
  })

  describe('useMediaSrc', () => {
    it('does not rebuild the URL when the token rotates, which would restart playback', async () => {
      runtime.native = true
      auth.token = 'tok'
      const { mediaSrc } = useMediaSrc()
      const src = computed(() => mediaSrc(UPLOAD_PATH))
      await flushPromises()

      const first = src.value
      expect(first).toContain('token=tok')

      auth.token = 'rotated'

      expect(src.value).toBe(first)
    })

    it('mints a fresh credential and busts the cache on reload', async () => {
      runtime.native = true
      auth.token = 'tok'
      const { mediaSrc, reloadMedia } = useMediaSrc()
      const src = computed(() => mediaSrc(UPLOAD_PATH))
      await flushPromises()
      expect(src.value).not.toContain('_retry=')

      auth.token = 'rotated'
      await reloadMedia()

      expect(refreshAccessToken).toHaveBeenCalledTimes(1)
      expect(src.value).toContain('token=rotated')
      expect(src.value).toContain('_retry=')
    })

    it('refreshes an expiring token once at setup so the first render is usable', async () => {
      runtime.native = true
      auth.token = signedToken(5)

      useMediaSrc()
      await flushPromises()

      expect(refreshAccessToken).toHaveBeenCalledTimes(1)
    })
  })
})
