import { describe, expect, it } from 'vitest'
import {
  applyDropdownPlacement,
  chooseDropdownPlacement,
  scrollStartedInsideMenu,
} from '@/utils/dropdownPlacement'

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

  it('leaves a menu that fits its items at that height, without a scrollbar', () => {
    const parent = document.createElement('div')
    const button = document.createElement('button')
    const panel = document.createElement('div')
    panel.className = 'dropdown-up'
    parent.append(button, panel)
    document.body.append(parent)

    const box = (top: number, height: number): DOMRect =>
      ({
        x: 0,
        y: top,
        top,
        left: 0,
        right: 80,
        bottom: top + height,
        width: 80,
        height,
        toJSON: () => ({}),
      }) as DOMRect
    button.getBoundingClientRect = () => box(500, 40)
    parent.getBoundingClientRect = () => box(500, 40)
    Object.defineProperty(panel, 'scrollHeight', { configurable: true, value: 164 })

    applyDropdownPlacement(panel)

    expect(panel.style.maxHeight).toBe('none')
    expect(panel.style.overflowY).toBe('visible')
    panel.remove()
    parent.remove()
  })

  it('sizes a nested menu against the page, not the parent menu', () => {
    const shell = document.createElement('div')
    const plusButton = document.createElement('button')
    const plus = document.createElement('div')
    plus.className = 'dropdown-up'
    plus.style.overflowY = 'auto'
    const toolsButton = document.createElement('button')
    const tools = document.createElement('div')
    tools.className = 'dropdown-up'
    plus.append(toolsButton, tools)
    shell.append(plusButton, plus)
    document.body.append(shell)

    const box = (top: number, height: number): DOMRect =>
      ({
        x: 0,
        y: top,
        top,
        left: 0,
        right: 80,
        bottom: top + height,
        width: 80,
        height,
        toJSON: () => ({}),
      }) as DOMRect
    plusButton.getBoundingClientRect = () => box(700, 40)
    toolsButton.getBoundingClientRect = () => box(700, 36)
    plus.getBoundingClientRect = () => box(560, 180)
    shell.getBoundingClientRect = () => box(700, 40)
    Object.defineProperty(plus, 'scrollHeight', { configurable: true, value: 164 })
    Object.defineProperty(tools, 'scrollHeight', { configurable: true, value: 438 })

    applyDropdownPlacement(tools)

    expect(tools.style.maxHeight).toBe('none')
    expect(tools.style.overflowY).toBe('visible')
    shell.remove()
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
