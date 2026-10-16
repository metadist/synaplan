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

import {
  applyBrandingTheme,
  contrastRatio,
  ensureFillForWhiteText,
  pickOnBrandColor,
} from '@/utils/brandingTheme'

function iconLinks(): HTMLLinkElement[] {
  return Array.from(document.querySelectorAll<HTMLLinkElement>('link[rel="icon"]'))
}

function appleTouchIcon(): HTMLLinkElement | null {
  return document.querySelector<HTMLLinkElement>('link[rel="apple-touch-icon"]')
}

describe('applyBrandingTheme — colors', () => {
  beforeEach(() => {
    branding.primaryColor = '#003fc7'
    branding.primaryColorDark = ''
    branding.secondaryColor = ''
    branding.secondaryColorDark = ''
    branding.accentColor = ''
    branding.accentColorDark = ''
    document.getElementById('brand-color-vars')?.remove()
  })

  it('leaves the stock palette when the primary is the default', () => {
    applyBrandingTheme()

    expect(document.getElementById('brand-color-vars')).toBeNull()
  })

  it('keeps the saturated primary as the dark bubble fill', () => {
    branding.primaryColor = '#0b3d91'
    applyBrandingTheme()

    const css = document.getElementById('brand-color-vars')?.textContent ?? ''
    expect(css).toContain('--brand-fill:#0b3d91')
    expect(css).toContain('color-mix(in srgb, #0b3d91 58%, white)')
    expect(css).not.toContain('--brand-fill:color-mix')
  })

  it('removes an injected palette when the brand returns to stock', () => {
    branding.primaryColor = '#0b3d91'
    applyBrandingTheme()
    branding.primaryColor = '#003fc7'
    applyBrandingTheme()

    expect(document.getElementById('brand-color-vars')).toBeNull()
  })

  it('picks white ink for a dark custom brand and dark ink for a light one', () => {
    expect(pickOnBrandColor('#0b3d91')).toBe('#ffffff')
    expect(pickOnBrandColor('#93c5fd')).toBe('#0a0e1a')
    expect(pickOnBrandColor('#003fc7')).toBe('#ffffff')
    expect(pickOnBrandColor('#6d9ae0')).toBe('#0a0e1a')
  })

  it('uses pure black when neither design token reaches AA on a mid grey', () => {
    const ink = pickOnBrandColor('#787878')

    expect(ink).toBe('#000000')
    expect(contrastRatio('#787878', ink)).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio('#ffffff', pickOnBrandColor('#ffffff'))).toBeGreaterThanOrEqual(4.5)
  })

  it('keeps a dark fill but darkens a light fill until white passes', () => {
    expect(ensureFillForWhiteText('#0b3d91')).toBe('#0b3d91')
    const lightened = ensureFillForWhiteText('#93c5fd')
    expect(lightened).not.toBe('#93c5fd')
    // White must reach AA on the darkened fill; verified via the picker.
    expect(pickOnBrandColor(lightened)).toBe('#ffffff')
  })

  it('writes the winning ink next to a custom light brand', () => {
    branding.primaryColor = '#93c5fd'
    applyBrandingTheme()

    const css = document.getElementById('brand-color-vars')?.textContent ?? ''
    expect(css).toContain('--on-brand:#0a0e1a')
    expect(css).not.toContain('--brand-fill:#93c5fd')
  })

  it('writes white ink next to a dark explicit dark-mode brand', () => {
    branding.primaryColorDark = '#003fc7'
    applyBrandingTheme()

    const css = document.getElementById('brand-color-vars')?.textContent ?? ''
    expect(css).toContain('--on-brand:#ffffff')
  })
})

describe('applyBrandingTheme — icon', () => {
  beforeEach(() => {
    branding.primaryColor = '#003fc7'
    branding.primaryColorDark = ''
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
