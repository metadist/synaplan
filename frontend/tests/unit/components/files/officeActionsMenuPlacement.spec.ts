import { describe, expect, it } from 'vitest'

import {
  MENU_GAP_PX,
  MENU_WIDTH_PX,
  parseCssPx,
  placeOfficeActionsMenu,
  visibleViewportBounds,
} from '@/components/files/officeActionsMenuPlacement'

const desktop = {
  innerWidth: 1280,
  innerHeight: 800,
  keyboardInsetPx: 0,
  visualViewport: null,
}

describe('parseCssPx', () => {
  it('reads a px custom property', () => {
    expect(parseCssPx('320px')).toBe(320)
    expect(parseCssPx(' 48.5px ')).toBe(48.5)
  })

  it('treats empty, zero, and garbage as no inset', () => {
    expect(parseCssPx('')).toBe(0)
    expect(parseCssPx('0px')).toBe(0)
    expect(parseCssPx('none')).toBe(0)
  })
})

describe('visibleViewportBounds', () => {
  it('subtracts the native keyboard inset from the layout viewport', () => {
    expect(
      visibleViewportBounds({ innerHeight: 800, keyboardInsetPx: 300, visualViewport: null })
    ).toEqual({ top: 0, bottom: 500 })
  })

  it('uses the visual viewport when it is shorter than the layout viewport', () => {
    expect(
      visibleViewportBounds({
        innerHeight: 800,
        keyboardInsetPx: 0,
        visualViewport: { offsetTop: 0, height: 480 },
      })
    ).toEqual({ top: 0, bottom: 480 })
  })

  it('takes the tighter of inset and visual viewport', () => {
    expect(
      visibleViewportBounds({
        innerHeight: 800,
        keyboardInsetPx: 200,
        visualViewport: { offsetTop: 40, height: 500 },
      })
    ).toEqual({ top: 40, bottom: 540 })
  })
})

describe('placeOfficeActionsMenu', () => {
  const menuHeight = 160

  it('opens above when both sides have room (kebab default)', () => {
    const placed = placeOfficeActionsMenu({
      ...desktop,
      menuHeight,
      trigger: { top: 400, bottom: 432, right: 240 },
    })
    expect(placed.openAbove).toBe(true)
    expect(placed.bottom).toBe(`${800 - 400 + MENU_GAP_PX}px`)
    expect(placed.top).toBe('auto')
    expect(placed.maxHeight).toBe(400 - MENU_GAP_PX)
  })

  it('flips below only when there is not enough room above', () => {
    const placed = placeOfficeActionsMenu({
      ...desktop,
      menuHeight,
      trigger: { top: 40, bottom: 72, right: 240 },
    })
    expect(placed.openAbove).toBe(false)
    expect(placed.top).toBe(`${72 + MENU_GAP_PX}px`)
    expect(placed.bottom).toBe('auto')
    expect(placed.maxHeight).toBe(800 - 72 - MENU_GAP_PX)
  })

  it('stays above a last-row kebab instead of covering the composer', () => {
    const placed = placeOfficeActionsMenu({
      ...desktop,
      menuHeight,
      trigger: { top: 620, bottom: 652, right: 240 },
    })
    expect(placed.openAbove).toBe(true)
    expect(placed.maxHeight).toBe(620 - MENU_GAP_PX)
  })

  it('does not treat the area behind a native keyboard as free space', () => {
    const placed = placeOfficeActionsMenu({
      ...desktop,
      keyboardInsetPx: 300,
      menuHeight,
      trigger: { top: 450, bottom: 482, right: 240 },
    })
    expect(placed.openAbove).toBe(true)
    expect(placed.maxHeight).toBe(450 - MENU_GAP_PX)
  })

  it('caps height when flipping down toward a native keyboard', () => {
    const placed = placeOfficeActionsMenu({
      ...desktop,
      keyboardInsetPx: 300,
      menuHeight,
      trigger: { top: 24, bottom: 56, right: 240 },
    })
    expect(placed.openAbove).toBe(false)
    expect(placed.maxHeight).toBe(500 - 56 - MENU_GAP_PX)
  })

  it('aligns the menu to the trigger and clamps it inside the viewport', () => {
    const placed = placeOfficeActionsMenu({
      ...desktop,
      menuHeight,
      trigger: { top: 400, bottom: 432, right: 40 },
    })
    expect(placed.left).toBe(8)
    const wide = placeOfficeActionsMenu({
      ...desktop,
      menuHeight,
      trigger: { top: 400, bottom: 432, right: 1280 },
    })
    expect(wide.left).toBe(1280 - MENU_WIDTH_PX - 8)
  })
})
