import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

/**
 * MOBILE-APP SEAM (custom-server purchase gate): Apple/Google require in-app
 * purchases to run through the store. A custom (self-hosted) server has no
 * store purchase channel, so `isPurchaseAllowed()` must be false there —
 * hiding every price and purchase path in the app. On the web build (and on
 * the app's build-default server) purchasing stays fully available.
 */

const mockIsNativeApp = vi.fn(() => false)
const mockIsNonProdBuild = vi.fn(() => false)

vi.mock('@/services/api/nativeRuntime', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/nativeRuntime')>()
  return {
    ...actual,
    isNativeApp: () => mockIsNativeApp(),
    isNonProdBuild: () => mockIsNonProdBuild(),
  }
})

import { isPurchaseAllowed } from '@/services/api/nativeServer'

type SynaplanServerBridge = {
  get: () => string
  getDefault: () => string
  open: () => void
  save: (url: string) => Promise<{ ok: boolean }>
  reset: () => void
  reload: () => void
}

function installBridge(current: string, defaultUrl: string) {
  const bridge: SynaplanServerBridge = {
    get: () => current,
    getDefault: () => defaultUrl,
    open: () => {},
    save: () => Promise.resolve({ ok: true }),
    reset: () => {},
    reload: () => {},
  }
  ;(globalThis as { SynaplanServer?: SynaplanServerBridge }).SynaplanServer = bridge
}

function removeBridge() {
  delete (globalThis as { SynaplanServer?: SynaplanServerBridge }).SynaplanServer
}

describe('isPurchaseAllowed (custom-server purchase gate)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    removeBridge()
    // Default to a production build so the server-comparison branch is exercised;
    // individual tests flip this to assert the non-prod leniency.
    mockIsNonProdBuild.mockReturnValue(false)
  })

  afterEach(() => {
    removeBridge()
  })

  it('is always true on the plain web build', () => {
    mockIsNativeApp.mockReturnValue(false)
    installBridge('https://self-hosted.example.com', 'https://web.synaplan.com')

    expect(isPurchaseAllowed()).toBe(true)
  })

  it('is true in the native app while on the build-default server', () => {
    mockIsNativeApp.mockReturnValue(true)
    installBridge('https://web.synaplan.com', 'https://web.synaplan.com')

    expect(isPurchaseAllowed()).toBe(true)
  })

  it('is false in the native app on a custom server', () => {
    mockIsNativeApp.mockReturnValue(true)
    installBridge('https://self-hosted.example.com', 'https://web.synaplan.com')

    expect(isPurchaseAllowed()).toBe(false)
  })

  it('is true in a non-prod build even on a custom server (local dev)', () => {
    // dev/staging device builds and the Vite dev server must always show prices
    // & purchase UI regardless of the configured server, for local testing.
    mockIsNativeApp.mockReturnValue(true)
    mockIsNonProdBuild.mockReturnValue(true)
    installBridge('https://self-hosted.example.com', 'https://web.synaplan.com')

    expect(isPurchaseAllowed()).toBe(true)
  })

  it('normalizes trailing slashes and casing before comparing', () => {
    mockIsNativeApp.mockReturnValue(true)
    installBridge('https://WEB.synaplan.com/', 'https://web.synaplan.com')

    expect(isPurchaseAllowed()).toBe(true)
  })

  it('treats an empty current URL as the default (fresh install)', () => {
    mockIsNativeApp.mockReturnValue(true)
    installBridge('', 'https://web.synaplan.com')

    expect(isPurchaseAllowed()).toBe(true)
  })

  it('is true when the native bridge is absent (no way to switch servers)', () => {
    mockIsNativeApp.mockReturnValue(true)

    expect(isPurchaseAllowed()).toBe(true)
  })
})
