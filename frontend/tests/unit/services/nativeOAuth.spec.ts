import { describe, expect, it, vi } from 'vitest'

vi.mock('@capacitor/app', () => ({ App: { addListener: vi.fn() } }))
vi.mock('@capacitor/browser', () => ({
  Browser: { open: vi.fn(), close: vi.fn(), addListener: vi.fn() },
}))
vi.mock('@/i18n', () => ({ i18n: { global: { locale: { value: 'en' } } } }))
vi.mock('@/services/api/nativeRuntime', () => ({
  getNativeApiBaseUrl: () => 'https://api.example',
}))
vi.mock('@/services/api/nativeAuth', () => ({ setNativeTokens: vi.fn() }))

import { nativeProviderLoginUrl } from '@/services/api/nativeOAuth'

describe('nativeProviderLoginUrl', () => {
  it('sends the human-check token and keeps native as the return-path flag', () => {
    const url = new URL(
      nativeProviderLoginUrl('https://api.example', 'github', 'de', 'challenge-token')
    )

    expect(url.origin + url.pathname).toBe('https://api.example/api/v1/auth/github/login')
    expect(url.searchParams.get('native')).toBe('1')
    expect(url.searchParams.get('language')).toBe('de')
    expect(url.searchParams.get('challenge')).toBe('challenge-token')
  })

  it('omits the challenge when the instance has no human check', () => {
    const url = new URL(nativeProviderLoginUrl('https://api.example', 'google', 'en'))

    expect(url.searchParams.get('native')).toBe('1')
    expect(url.searchParams.has('challenge')).toBe(false)
  })
})
