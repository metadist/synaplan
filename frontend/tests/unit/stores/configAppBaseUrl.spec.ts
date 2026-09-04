import { describe, expect, it, vi } from 'vitest'

const runtime = vi.hoisted(() => ({
  native: false,
  baseUrl: 'https://web.synaplan.com',
}))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => runtime.native,
  getNativeApiBaseUrl: () => runtime.baseUrl,
}))

vi.mock('@/services/api/httpClient', () => ({
  getConfig: vi.fn(),
  getConfigSync: () => ({}),
  getApiBaseUrl: () => '',
  reloadConfig: vi.fn(),
  getUnavailableProviders: () => [],
}))

vi.mock('@/services/api/configApi', () => ({
  checkMemoryServiceAvailability: vi.fn(),
}))

vi.mock('@/i18n', () => ({
  i18n: { global: { t: (key: string) => key } },
}))

import { useConfigStore } from '@/stores/config'

describe('config.appBaseUrl', () => {
  it('uses the page origin on the web', () => {
    runtime.native = false
    expect(useConfigStore().appBaseUrl).toBe(window.location.origin)
  })

  it('uses the platform API origin inside the native shell', () => {
    runtime.native = true
    runtime.baseUrl = 'https://web.synaplan.com'
    expect(useConfigStore().appBaseUrl).toBe('https://web.synaplan.com')
    expect(useConfigStore().appBaseUrl).not.toMatch(/^capacitor:/)
    expect(useConfigStore().appBaseUrl).not.toBe(window.location.origin)
  })
})
