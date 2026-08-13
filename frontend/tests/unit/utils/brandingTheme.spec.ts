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

function iconLinks(): HTMLLinkElement[] {
  return Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="icon"]'))
}

function appleTouchIcon(): HTMLLinkElement | null {
  return document.querySelector<HTMLLinkElement>('link[rel="apple-touch-icon"]')
}

describe('applyBrandingTheme — icon', () => {
  beforeEach(() => {
    branding.iconUrl = ''
    document.head.innerHTML = `
      <link rel="icon" type="image/svg+xml" href="/single_bird.svg" />
      <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png" />
      <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
    `
  })

  it('leaves bundled favicons when iconUrl is empty', () => {
    applyBrandingTheme()

    const icons = iconLinks()
    expect(icons).toHaveLength(2)
    expect(icons[0]?.href).toContain('/single_bird.svg')
    expect(icons[0]?.type).toBe('image/svg+xml')
    expect(icons[1]?.href).toContain('/favicon-32.png')
    expect(icons[1]?.type).toBe('image/png')
    expect(appleTouchIcon()?.href).toContain('/apple-touch-icon.png')
  })

  it('replaces bundled favicons with a single correctly-typed branding icon', () => {
    branding.iconUrl = 'https://brand.test/icon.svg'
    applyBrandingTheme()

    const icons = iconLinks()
    expect(icons).toHaveLength(1)
    expect(icons[0]?.href).toContain('https://brand.test/icon.svg')
    expect(icons[0]?.type).toBe('image/svg+xml')
    expect(appleTouchIcon()?.href).toContain('https://brand.test/icon.svg')
  })

  it('sets image/png type when the branding icon is a PNG', () => {
    branding.iconUrl = 'https://brand.test/icon.png'
    applyBrandingTheme()

    const icons = iconLinks()
    expect(icons).toHaveLength(1)
    expect(icons[0]?.href).toContain('https://brand.test/icon.png')
    expect(icons[0]?.type).toBe('image/png')
  })
})
