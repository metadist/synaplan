import { describe, expect, it } from 'vitest'
import { chooseBesidePlacement, type BesideFrame } from '@/composables/useBesideMenu'

function frame(overrides: Partial<BesideFrame> = {}): BesideFrame {
  const merged = {
    boxTop: 400,
    boxRight: 236,
    boxBottom: 560,
    boxLeft: 16,
    contentHeight: 300,
    viewLeft: 0,
    viewTop: 0,
    viewWidth: 1200,
    viewHeight: 800,
    leadingInset: 0,
    trailingInset: 0,
    ...overrides,
  }
  return {
    ...merged,
    anchorTop: overrides.anchorTop ?? merged.boxTop,
    anchorBottom: overrides.anchorBottom ?? merged.boxBottom,
  }
}

describe('chooseBesidePlacement', () => {
  it('opens to the right of the box with the bottom item beside the row', () => {
    const placement = chooseBesidePlacement(frame())
    expect(placement.opensLeft).toBe(false)
    expect(placement.left).toBe(224)
    expect(placement.top).toBe(260)
    expect(placement.width).toBe(320)
    expect(placement.minHeight).toBe(0)
    expect(placement.maxHeight).toBeNull()
  })

  it('nudges up near the screen bottom without leaving the row', () => {
    const placement = chooseBesidePlacement(
      frame({ boxTop: 600, boxBottom: 760, contentHeight: 300 })
    )
    expect(placement.top).toBe(460)
    expect(placement.top).toBeLessThan(680)
    expect(placement.top + 300).toBeGreaterThan(680)
  })

  it('opens to the left when that side has more room', () => {
    const placement = chooseBesidePlacement(frame({ boxLeft: 900, boxRight: 1100 }))
    expect(placement.opensLeft).toBe(true)
    expect(placement.left).toBe(592)
    expect(placement.width).toBe(320)
  })

  it('keeps a full-height list inside a narrow screen', () => {
    const placement = chooseBesidePlacement(
      frame({
        boxLeft: 12,
        boxRight: 200,
        boxTop: 80,
        boxBottom: 240,
        contentHeight: 420,
        viewWidth: 320,
        viewHeight: 700,
      })
    )
    expect(placement.width).toBe(304)
    expect(placement.left).toBe(8)
    expect(placement.left + placement.width).toBeLessThanOrEqual(312)
    expect(placement.top).toBe(8)
  })

  it('grows upward from the box when the list is taller than the room below', () => {
    const placement = chooseBesidePlacement(
      frame({
        boxTop: 500,
        boxBottom: 640,
        contentHeight: 400,
        viewHeight: 700,
      })
    )
    expect(placement.top).toBe(240)
    expect(placement.top).toBeLessThan(570)
    expect(placement.top + 400).toBeGreaterThan(570)
    expect(placement.maxHeight).toBeNull()
  })

  it('puts the bottom item beside the row that opened it', () => {
    const placement = chooseBesidePlacement(
      frame({
        boxTop: 400,
        boxBottom: 560,
        anchorTop: 520,
        anchorBottom: 554,
        leadingInset: 8,
        trailingInset: 8,
        contentHeight: 100,
      })
    )
    expect(placement.top).toBe(462)
    expect(placement.top + 100 - 8).toBe(554)
  })

  it('keeps the hovered row inside the menu when the list is nudged up', () => {
    const placement = chooseBesidePlacement(
      frame({
        anchorTop: 720,
        anchorBottom: 754,
        leadingInset: 8,
        trailingInset: 8,
        contentHeight: 100,
        viewHeight: 800,
      })
    )
    expect(placement.top).toBe(662)
    expect(placement.top).toBeLessThan(737)
    expect(placement.top + 100).toBeGreaterThan(737)
  })

  it('stays as tall as its items when the tools box is taller', () => {
    const placement = chooseBesidePlacement(
      frame({ boxTop: 400, boxBottom: 700, contentHeight: 88 })
    )
    expect(placement.minHeight).toBe(0)
    expect(placement.maxHeight).toBeNull()
    expect(placement.top).toBe(612)
  })

  it('scrolls only when the list is taller than the screen', () => {
    const placement = chooseBesidePlacement(
      frame({
        boxTop: 100,
        boxBottom: 200,
        contentHeight: 700,
        viewHeight: 500,
      })
    )
    expect(placement.maxHeight).toBe(484)
    expect(placement.top).toBe(8)
    expect(placement.minHeight).toBe(0)
  })
})
