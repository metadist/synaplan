import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/services/api/httpClient'
import { browserTimezone } from '@/utils/zonedDay'
import { ensureAccountTimezone, resetAccountTimezone } from '@/composables/useAccountTimezone'

const authState = vi.hoisted(() => ({
  userId: 4 as number | null,
  impersonating: false,
}))

const { mockGetProfile, mockUpdateProfile } = vi.hoisted(() => ({
  mockGetProfile: vi.fn(),
  mockUpdateProfile: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return authState.userId == null ? null : { id: authState.userId }
    },
    get isImpersonating() {
      return authState.impersonating
    },
  }),
}))

vi.mock('@/services/api/profileApi', () => ({
  profileApi: {
    getProfile: (...args: unknown[]) => mockGetProfile(...args),
    updateProfile: (...args: unknown[]) => mockUpdateProfile(...args),
  },
}))

describe('ensureAccountTimezone', () => {
  beforeEach(() => {
    resetAccountTimezone()
    authState.userId = 4
    authState.impersonating = false
    mockGetProfile.mockReset()
    mockUpdateProfile.mockReset()
    mockUpdateProfile.mockResolvedValue({ success: true })
  })

  it('saves the device zone when the profile has none', async () => {
    mockGetProfile.mockResolvedValue({ profile: { timezone: '' } })

    const result = await ensureAccountTimezone()

    expect(result).toEqual({ tz: browserTimezone(), source: 'device-saved' })
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1)
    expect(mockUpdateProfile).toHaveBeenCalledWith({ timezone: browserTimezone() })
  })

  it('keeps a zone that is already on the profile', async () => {
    mockGetProfile.mockResolvedValue({ profile: { timezone: 'Europe/Berlin' } })

    const result = await ensureAccountTimezone()

    expect(result).toEqual({ tz: 'Europe/Berlin', source: 'profile' })
    expect(mockUpdateProfile).not.toHaveBeenCalled()
  })

  it('does not write while signed in as someone else', async () => {
    authState.impersonating = true
    mockGetProfile.mockResolvedValue({ profile: { timezone: '' } })

    const result = await ensureAccountTimezone()

    expect(result).toEqual({ tz: browserTimezone(), source: 'device-unsaved' })
    expect(mockUpdateProfile).not.toHaveBeenCalled()
  })

  it('returns the device zone without throwing when the server rejects it', async () => {
    mockGetProfile.mockResolvedValue({ profile: { timezone: '' } })
    mockUpdateProfile.mockRejectedValue(new ApiError(400, 'invalid', 'timezone_invalid'))

    const result = await ensureAccountTimezone()

    expect(result).toEqual({ tz: browserTimezone(), source: 'device-unsaved' })
  })

  it('returns null and writes nothing when the profile cannot be loaded', async () => {
    mockGetProfile.mockRejectedValue(new Error('offline'))

    await expect(ensureAccountTimezone()).resolves.toBeNull()
    expect(mockUpdateProfile).not.toHaveBeenCalled()
  })

  it('loads again after the signed-in user changes', async () => {
    mockGetProfile.mockResolvedValueOnce({ profile: { timezone: 'Europe/Berlin' } })
    expect((await ensureAccountTimezone())?.tz).toBe('Europe/Berlin')

    authState.userId = 9
    mockGetProfile.mockResolvedValueOnce({ profile: { timezone: 'Asia/Tokyo' } })

    expect((await ensureAccountTimezone())?.tz).toBe('Asia/Tokyo')
    expect(mockGetProfile).toHaveBeenCalledTimes(2)
    expect(mockUpdateProfile).not.toHaveBeenCalled()
  })

  it('shares one save when two callers run at the same time', async () => {
    let release: (value: unknown) => void = () => {}
    mockGetProfile.mockImplementation(
      () =>
        new Promise((resolve) => {
          release = resolve
        })
    )

    const first = ensureAccountTimezone()
    const second = ensureAccountTimezone()
    release({ profile: { timezone: '' } })
    const [a, b] = await Promise.all([first, second])

    expect(mockGetProfile).toHaveBeenCalledTimes(1)
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1)
    expect(a).toEqual({ tz: browserTimezone(), source: 'device-saved' })
    expect(b).toEqual(a)
  })
})
