import { describe, expect, it, vi } from 'vitest'

const appBaseUrl = vi.hoisted(() => ({ value: 'https://web.synaplan.com' }))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    get appBaseUrl() {
      return appBaseUrl.value
    },
  }),
}))

import { buildChatShareUrl, normalizeMediaUrl } from '@/utils/urlHelper'

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
