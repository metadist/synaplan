import { beforeEach, describe, expect, it, vi } from 'vitest'

const getToken = vi.fn(async () => 'challenge-token')
let challengeEnabled = true
const startNativeOAuth = vi.fn(async () => ({ success: true }))
const startNativeAppleSignIn = vi.fn(async () => ({ success: true }))
const handleOAuthCallback = vi.fn(async () => true)
let native = true
let platform = 'android'

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ appBaseUrl: 'https://app.example' }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ handleOAuthCallback }),
}))
vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => native,
  getNativePlatform: () => platform,
}))
vi.mock('@/services/api/nativeOAuth', () => ({
  startNativeOAuth: (...args: unknown[]) => startNativeOAuth(...args),
}))
vi.mock('@/services/api/nativeAppleAuth', () => ({
  startNativeAppleSignIn: () => startNativeAppleSignIn(),
}))
vi.mock('@/utils/pendingAuthRedirect', () => ({ setPendingRedirect: vi.fn() }))
vi.mock('@/composables/useSignupChallenge', () => ({
  useSignupChallenge: () => ({ getToken, isEnabled: challengeEnabled, provider: 'turnstile' }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'en' }, t: (key: string) => key }),
}))

import { useSocialAuth } from '@/composables/useSocialAuth'

describe('useSocialAuth native human check', () => {
  beforeEach(() => {
    getToken.mockReset()
    getToken.mockResolvedValue('challenge-token')
    challengeEnabled = true
    startNativeOAuth.mockReset()
    startNativeOAuth.mockResolvedValue({ success: true })
    startNativeAppleSignIn.mockReset()
    startNativeAppleSignIn.mockResolvedValue({ success: true })
    handleOAuthCallback.mockClear()
    native = true
    platform = 'android'
  })

  it('solves the human check before opening the system browser', async () => {
    const auth = useSocialAuth()

    await expect(auth.signInWith('github')).resolves.toBe(true)

    expect(getToken).toHaveBeenCalledWith('signup')
    expect(startNativeOAuth).toHaveBeenCalledWith('github', 'challenge-token')
    expect(startNativeAppleSignIn).not.toHaveBeenCalled()
  })

  it('does not open the browser when the human check produces no token', async () => {
    getToken.mockResolvedValue('')
    const auth = useSocialAuth()

    await expect(auth.signInWith('google')).resolves.toBe(false)

    expect(auth.error.value).toBe('auth.signupBlocked.challengeFailed')
    expect(startNativeOAuth).not.toHaveBeenCalled()
    expect(auth.busy.value).toBe(false)
  })

  it('leaves the iOS Apple sheet on its signed-token path', async () => {
    platform = 'ios'
    const auth = useSocialAuth()

    await expect(auth.signInWith('apple')).resolves.toBe(true)

    expect(getToken).not.toHaveBeenCalled()
    expect(startNativeAppleSignIn).toHaveBeenCalledOnce()
    expect(startNativeOAuth).not.toHaveBeenCalled()
  })
})
