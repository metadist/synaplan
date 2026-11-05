import { describe, expect, it } from 'vitest'
import {
  isFieldShovedToTop,
  keyboardRevealDelta,
  keyboardRevealTarget,
  keyboardVisibleBottom,
} from '@/services/keyboardReveal'

const band = { visibleTop: 16, visibleBottom: 400 }

describe('keyboardRevealTarget', () => {
  it('keeps a field that is already in the visible band', () => {
    expect(keyboardRevealTarget({ anchorTop: 180, fieldHeight: 40, ...band })).toBe(180)
  })

  it('puts a covered field just above the keyboard', () => {
    expect(keyboardRevealTarget({ anchorTop: 640, fieldHeight: 48, ...band })).toBe(352)
  })

  it('aims a top-pinned field just above the keyboard, not at the top edge', () => {
    expect(keyboardRevealTarget({ anchorTop: 420, fieldHeight: 40, ...band })).toBe(360)
  })

  it('keeps a small gap below the top edge', () => {
    expect(keyboardRevealTarget({ anchorTop: 0, fieldHeight: 40, ...band })).toBe(16)
  })
})

describe('keyboardRevealDelta', () => {
  it('is zero when the field is already on target', () => {
    expect(
      keyboardRevealDelta({
        anchorTop: 180,
        fieldTop: 180,
        fieldHeight: 40,
        ...band,
      })
    ).toBe(0)
  })

  it('scrolls a top-pinned field back down to just above the keyboard', () => {
    expect(
      keyboardRevealDelta({
        anchorTop: 640,
        fieldTop: 0,
        fieldHeight: 40,
        ...band,
      })
    ).toBe(-360)
  })

  it('ignores sub-pixel noise', () => {
    expect(
      keyboardRevealDelta({
        anchorTop: 180,
        fieldTop: 181,
        fieldHeight: 40,
        ...band,
      })
    ).toBe(0)
  })
})

describe('isFieldShovedToTop', () => {
  it('recognizes a field pinned to the top edge', () => {
    expect(isFieldShovedToTop(0, 420)).toBe(true)
  })

  it('leaves a field the user tapped near the top', () => {
    expect(isFieldShovedToTop(4, 30)).toBe(false)
  })
})

describe('keyboardVisibleBottom', () => {
  it('subtracts the CSS inset when the keyboard overlays a full-height viewport', () => {
    expect(
      keyboardVisibleBottom({
        innerHeight: 800,
        visualHeight: 800,
        cssInset: 350,
        marginPx: 16,
      })
    ).toBe(434)
  })

  it('does not subtract the keyboard a second time when the WebView already shrank', () => {
    expect(
      keyboardVisibleBottom({
        innerHeight: 450,
        visualHeight: 450,
        cssInset: 0,
        marginPx: 16,
      })
    ).toBe(434)
  })
})
