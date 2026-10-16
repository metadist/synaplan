import { describe, expect, it } from 'vitest'
import { chooseDropdownPlacement, scrollStartedInsideMenu } from '@/utils/dropdownPlacement'

const frame = { frameTop: 0, frameBottom: 600 }

describe('dropdown placement', () => {
  it('stays below when the menu fits there', () => {
    const placement = chooseDropdownPlacement({
      ...frame,
      anchorTop: 100,
      anchorBottom: 140,
      contentHeight: 120,
    })
    expect(placement.openAbove).toBe(false)
    expect(placement.maxHeight).toBe(120)
  })

  it('opens above when only that side can hold the menu', () => {
    const placement = chooseDropdownPlacement({
      ...frame,
      anchorTop: 500,
      anchorBottom: 540,
      contentHeight: 300,
    })
    expect(placement.openAbove).toBe(true)
    expect(placement.maxHeight).toBe(300)
  })

  it('keeps a composer menu above when that is where it fits', () => {
    const placement = chooseDropdownPlacement({
      ...frame,
      anchorTop: 520,
      anchorBottom: 560,
      contentHeight: 200,
      preferAbove: true,
    })
    expect(placement.openAbove).toBe(true)
  })

  it('uses the larger side and caps the height when the menu fits neither side', () => {
    const above = chooseDropdownPlacement({
      ...frame,
      anchorTop: 340,
      anchorBottom: 380,
      contentHeight: 900,
    })
    expect(above.openAbove).toBe(true)
    expect(above.maxHeight).toBe(332)

    const below = chooseDropdownPlacement({
      ...frame,
      anchorTop: 220,
      anchorBottom: 260,
      contentHeight: 900,
    })
    expect(below.openAbove).toBe(false)
    expect(below.maxHeight).toBe(332)
  })

  it('keeps the preferred side when the menu fits both', () => {
    const placement = chooseDropdownPlacement({
      ...frame,
      anchorTop: 200,
      anchorBottom: 240,
      contentHeight: 80,
      preferAbove: true,
    })
    expect(placement.openAbove).toBe(true)
    expect(placement.maxHeight).toBe(80)
  })

  it('treats scrolling inside the menu as the menu, not the page', () => {
    const menu = document.createElement('div')
    menu.className = 'dropdown-panel'
    const list = document.createElement('div')
    menu.append(list)
    document.body.append(menu)

    expect(scrollStartedInsideMenu(list)).toBe(true)
    expect(scrollStartedInsideMenu(document.body)).toBe(false)
    menu.remove()
  })
})
