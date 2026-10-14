import { describe, expect, it, vi } from 'vitest'

const appBaseUrl = vi.hoisted(() => ({ value: 'https://web.synaplan.com' }))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    get appBaseUrl() {
      return appBaseUrl.value
    },
  }),
}))

import { buildChatShareUrl, normalizeMediaUrl, publicAppOrigin } from '@/utils/urlHelper'

const pageOrigin = vi.hoisted(() => ({ value: 'http://localhost:5173' }))

vi.stubGlobal('location', {
  get origin() {
    return pageOrigin.value
  },
})

describe('buildChatShareUrl', () => {
  it('builds the public chat page on the platform origin', () => {
    appBaseUrl.value = 'https://web.synaplan.com'
    expect(buildChatShareUrl('abc123')).toBe('https://web.synaplan.com/shared/abc123')
  })

  it('does not use the Capacitor WebView origin', () => {
    appBaseUrl.value = 'https://web.synaplan.com'
    const url = buildChatShareUrl('tok_native')
    expect(url).not.toContain('capacitor://')
    expect(url).not.toBe(`${window.location.origin}/shared/tok_native`)
    expect(url).toBe('https://web.synaplan.com/shared/tok_native')
  })

  it('strips a trailing slash from the platform origin', () => {
    appBaseUrl.value = 'https://web.synaplan.com/'
    expect(buildChatShareUrl('tok')).toBe('https://web.synaplan.com/shared/tok')
  })

  it('uses the configured service when the page is the Capacitor WebView', () => {
    pageOrigin.value = 'capacitor://localhost'
    appBaseUrl.value = 'capacitor://localhost'
    vi.stubGlobal('__SYNAPLAN_API_BASE_URL__', 'https://chat.example.test')
    expect(buildChatShareUrl('8ff39ffae77d4d9c0dfb7ccb')).toBe(
      'https://chat.example.test/shared/8ff39ffae77d4d9c0dfb7ccb'
    )
    expect(publicAppOrigin()).not.toContain('capacitor://')
    pageOrigin.value = 'http://localhost:5173'
    vi.stubGlobal('__SYNAPLAN_API_BASE_URL__', undefined)
  })

  it('keeps a normal site origin even when a native server URL is set', () => {
    pageOrigin.value = 'https://staging.synaplan.com'
    appBaseUrl.value = 'https://staging.synaplan.com'
    vi.stubGlobal('__SYNAPLAN_API_BASE_URL__', 'https://chat.example.test')
    expect(buildChatShareUrl('tok')).toBe('https://staging.synaplan.com/shared/tok')
    vi.stubGlobal('__SYNAPLAN_API_BASE_URL__', undefined)
  })
})

describe('normalizeMediaUrl', () => {
  it('prefixes relative media paths with the platform origin', () => {
    appBaseUrl.value = 'https://web.synaplan.com'
    expect(normalizeMediaUrl('/up/file.pdf')).toBe('https://web.synaplan.com/up/file.pdf')
  })

  it('leaves already-absolute http(s) URLs unchanged', () => {
    expect(normalizeMediaUrl('https://cdn.example.com/a.png')).toBe('https://cdn.example.com/a.png')
  })
})
