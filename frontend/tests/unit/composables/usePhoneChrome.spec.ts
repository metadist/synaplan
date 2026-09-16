import { describe, expect, it } from 'vitest'
import { isPhoneChromeSize } from '@/composables/usePhoneChrome'

describe('isPhoneChromeSize', () => {
  it('keeps the drawer on a portrait phone', () => {
    expect(isPhoneChromeSize(390, 844)).toBe(true)
  })

  it('keeps the drawer on a landscape phone (the clipped-rail repro)', () => {
    expect(isPhoneChromeSize(844, 390)).toBe(true)
    expect(isPhoneChromeSize(932, 430)).toBe(true)
  })

  it('uses the desktop rail on a tablet or desktop', () => {
    expect(isPhoneChromeSize(768, 1024)).toBe(false)
    expect(isPhoneChromeSize(1024, 768)).toBe(false)
    expect(isPhoneChromeSize(1280, 720)).toBe(false)
  })

  it('falls back to the drawer on a very short landscape window', () => {
    expect(isPhoneChromeSize(1280, 480)).toBe(true)
  })
})
