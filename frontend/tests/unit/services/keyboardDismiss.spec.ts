import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { KEYBOARD_TAP_MOVE_TOLERANCE_PX, initKeyboardDismiss } from '@/services/keyboardDismiss'

function pointer(type: string, el: Element, x: number, y: number): void {
  el.dispatchEvent(new PointerEvent(type, { bubbles: true, clientX: x, clientY: y }))
}

describe('initKeyboardDismiss', () => {
  let stop: () => void = () => {}
  let field: HTMLTextAreaElement
  let page: HTMLDivElement

  beforeEach(() => {
    document.body.replaceChildren()
    field = document.createElement('textarea')
    page = document.createElement('div')
    document.body.append(field, page)
    stop = initKeyboardDismiss()
    field.focus()
    expect(document.activeElement).toBe(field)
  })

  afterEach(() => {
    stop()
  })

  it('blurs the focused field on a tap outside it', () => {
    pointer('pointerdown', page, 100, 300)
    pointer('pointerup', page, 104, 296)
    expect(document.activeElement).not.toBe(field)
  })

  it('keeps the keyboard when the gesture was a scroll', () => {
    pointer('pointerdown', page, 100, 300)
    pointer('pointerup', page, 100, 300 - KEYBOARD_TAP_MOVE_TOLERANCE_PX - 1)
    expect(document.activeElement).toBe(field)
  })

  it('keeps the keyboard when the browser claims the gesture', () => {
    pointer('pointerdown', page, 100, 300)
    pointer('pointercancel', page, 100, 300)
    pointer('pointerup', page, 100, 300)
    expect(document.activeElement).toBe(field)
  })

  it('never dismisses for a press that starts on the field itself', () => {
    pointer('pointerdown', field, 10, 10)
    pointer('pointerup', field, 10, 10)
    expect(document.activeElement).toBe(field)
  })

  it('lets a tap on another field hand over focus', () => {
    const other = document.createElement('input')
    document.body.appendChild(other)
    pointer('pointerdown', page, 50, 50)
    pointer('pointerup', other, 50, 50)
    expect(document.activeElement).toBe(field)
  })

  it('does nothing while no text field is focused', () => {
    const checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    document.body.appendChild(checkbox)
    checkbox.focus()
    pointer('pointerdown', page, 100, 300)
    pointer('pointerup', page, 100, 300)
    expect(document.activeElement).toBe(checkbox)
  })
})
