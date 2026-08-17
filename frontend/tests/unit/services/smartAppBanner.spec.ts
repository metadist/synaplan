import { beforeEach, describe, expect, it, vi } from 'vitest'

const native = vi.hoisted(() => ({ value: false }))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => native.value,
}))

import { appleAppIdFromStoreUrl, installSmartAppBanner } from '@/services/smartAppBanner'

const banner = () => document.head.querySelector('meta[name="apple-itunes-app"]')

const STORE_URL = 'https://apps.apple.com/de/app/id6784278288'

describe('appleAppIdFromStoreUrl', () => {
  it('reads the id from the shapes App Store links actually take', () => {
    expect(appleAppIdFromStoreUrl(STORE_URL)).toBe('6784278288')
    expect(appleAppIdFromStoreUrl('https://apps.apple.com/app/id6784278288')).toBe('6784278288')
    expect(
      appleAppIdFromStoreUrl('https://apps.apple.com/us/app/synaplan-ai-control/id6784278288?uo=4')
    ).toBe('6784278288')
  })

  it('returns nothing for a link that carries no id', () => {
    expect(appleAppIdFromStoreUrl('https://www.synaplan.com')).toBe('')
    expect(appleAppIdFromStoreUrl('')).toBe('')
  })
})

describe('installSmartAppBanner', () => {
  beforeEach(() => {
    document.head.innerHTML = ''
    native.value = false
  })

  it('advertises the listing the operator configured', () => {
    expect(installSmartAppBanner(STORE_URL)).toBe(true)
    expect(banner()?.getAttribute('content')).toBe('app-id=6784278288')
  })

  it('stays out of the way when no app is configured', () => {
    expect(installSmartAppBanner('')).toBe(false)
    expect(banner()).toBeNull()
  })

  it('never advertises the app to the app itself', () => {
    native.value = true

    expect(installSmartAppBanner(STORE_URL)).toBe(false)
    expect(banner()).toBeNull()
  })

  it('leaves an existing tag alone instead of stacking a second one', () => {
    installSmartAppBanner(STORE_URL)

    expect(installSmartAppBanner('https://apps.apple.com/app/id999')).toBe(false)
    expect(document.head.querySelectorAll('meta[name="apple-itunes-app"]')).toHaveLength(1)
    expect(banner()?.getAttribute('content')).toBe('app-id=6784278288')
  })
})
