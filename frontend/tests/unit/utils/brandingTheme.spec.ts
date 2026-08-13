import { describe, it, expect, vi, beforeEach } from 'vitest'

const branding = vi.hoisted(() => ({
  primaryColor: '#003fc7',
  secondaryColor: '',
  accentColor: '',
  primaryColorDark: '',
  secondaryColorDark: '',
  accentColorDark: '',
  fontFamily: '',
  headingFontFamily: '',
  fontUrl: '',
  iconUrl: '',
}))

vi.mock('@/stores/config', () => ({
  default: { branding },
  useConfigStore: () => ({ branding }),
}))

import { applyBrandingTheme } from '@/utils/brandingTheme'

describe('applyBrandingTheme — icon', () => {
  beforeEach(() => {
    branding.iconUrl = ''
    document.head.innerHTML = `
      <link rel="icon" type="image/svg+xml" href="/single_bird.svg" />
      <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
    `
  })

  it('leaves bundled favicons when iconUrl is empty', () => {
    applyBrandingTheme()

    expect(document.querySelector('link[rel="icon"]')?.href).toContain('/single_bird.svg')
    expect(document.querySelector('link[rel="apple-touch-icon"]')?.href).toContain(
      '/apple-touch-icon.png'
    )
  })

  it('points favicon links at branding.iconUrl when configured', () => {
    branding.iconUrl = 'https://brand.test/icon.svg'
    applyBrandingTheme()

    expect(document.querySelector('link[rel="icon"]')?.href).toContain('https://brand.test/icon.svg')
    expect(document.querySelector('link[rel="apple-touch-icon"]')?.href).toContain(
      'https://brand.test/icon.svg'
    )
  })
})
