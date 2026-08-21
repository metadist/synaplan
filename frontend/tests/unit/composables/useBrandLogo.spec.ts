import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

const branding = {
  logoUrl: '',
  logoDarkUrl: '',
  iconUrl: '',
}

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({ branding }),
}))

import { useBrandLogo } from '@/composables/useBrandLogo'

describe('useBrandLogo', () => {
  const isDark = ref(false)

  beforeEach(() => {
    branding.logoUrl = ''
    branding.logoDarkUrl = ''
    branding.iconUrl = ''
    isDark.value = false
  })

  it('falls back to the bundled wordmark and bird when branding is empty', () => {
    const { logoSrc, iconSrc } = useBrandLogo(isDark)

    expect(logoSrc.value).toMatch(/synaplan-dark\.svg$/)
    expect(iconSrc.value).toMatch(/single_bird-dark\.svg$/)

    isDark.value = true
    expect(logoSrc.value).toMatch(/synaplan-light\.svg$/)
    expect(iconSrc.value).toMatch(/single_bird-light\.svg$/)
  })

  it('uses the configured wordmark in both themes when only logoUrl is set', () => {
    branding.logoUrl = 'https://brand.test/logo.svg'
    const { logoSrc, iconSrc } = useBrandLogo(isDark)

    expect(logoSrc.value).toBe('https://brand.test/logo.svg')
    expect(iconSrc.value).toBe('https://brand.test/logo.svg')

    isDark.value = true
    expect(logoSrc.value).toBe('https://brand.test/logo.svg')
    expect(iconSrc.value).toBe('https://brand.test/logo.svg')
  })

  it('prefers the dark wordmark in dark mode when both logos are set', () => {
    branding.logoUrl = 'https://brand.test/logo.svg'
    branding.logoDarkUrl = 'https://brand.test/logo-dark.svg'
    isDark.value = true
    const { logoSrc } = useBrandLogo(isDark)

    expect(logoSrc.value).toBe('https://brand.test/logo-dark.svg')
  })

  it('prefers iconUrl for the compact mark over the wordmark', () => {
    branding.logoUrl = 'https://brand.test/logo.svg'
    branding.iconUrl = 'https://brand.test/icon.svg'
    const { logoSrc, iconSrc } = useBrandLogo(isDark)

    expect(logoSrc.value).toBe('https://brand.test/logo.svg')
    expect(iconSrc.value).toBe('https://brand.test/icon.svg')
  })
})
