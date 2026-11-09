import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { initKeyboardScrollAssist } from '@/services/keyboardScrollAssist'

vi.mock('@/services/api/nativeRuntime', () => ({ isNativeApp: () => true }))

const FIELD_HEIGHT = 40
/** Where the user tapped the field, with the keyboard closed. */
const TAP_TOP = 600
const KEYBOARD_PX = 300

/**
 * happy-dom has no layout. The scroller reports the field where the tap left
 * it minus its own scroll offset, which is all the assist reads.
 */
function page(): { scroller: HTMLDivElement; field: HTMLInputElement } {
  const scroller = document.createElement('div')
  scroller.style.overflowY = 'auto'
  const field = document.createElement('input')
  scroller.appendChild(field)
  document.body.appendChild(scroller)
  let scrollTop = 0
  Object.defineProperty(scroller, 'scrollTop', {
    configurable: true,
    get: () => scrollTop,
    set: (value: number) => {
      scrollTop = value
    },
  })
  scroller.scrollBy = ((options: ScrollToOptions) => {
    scrollTop += options.top ?? 0
  }) as typeof scroller.scrollBy
  field.getBoundingClientRect = () =>
    ({
      top: TAP_TOP - scrollTop,
      bottom: TAP_TOP - scrollTop + FIELD_HEIGHT,
      height: FIELD_HEIGHT,
      left: 0,
      right: 300,
      width: 300,
      x: 0,
      y: TAP_TOP - scrollTop,
      toJSON: () => ({}),
    }) as DOMRect
  return { scroller, field }
}

function keyboard(height: number, keyboardHeight = height): void {
  window.dispatchEvent(
    new CustomEvent('synaplan:keyboardinset', { detail: { height, keyboardHeight } })
  )
}

function tap(field: HTMLElement): void {
  field.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
  field.focus()
}

function setInnerHeight(px: number): void {
  Object.defineProperty(window, 'innerHeight', { configurable: true, value: px })
}

describe('initKeyboardScrollAssist', () => {
  let stop: () => void = () => {}

  beforeEach(() => {
    document.body.replaceChildren()
    setInnerHeight(800)
    vi.useFakeTimers()
    stop = initKeyboardScrollAssist()
  })

  afterEach(() => {
    stop()
    vi.useRealTimers()
  })

  it('lifts a field the keyboard would cover just above the keyboard', () => {
    const { scroller, field } = page()
    tap(field)
    keyboard(KEYBOARD_PX)
    // Band bottom 800 - 300 - 16 = 484; the field's top lands at 484 - 40.
    expect(scroller.scrollTop).toBe(TAP_TOP - (800 - KEYBOARD_PX - 16 - FIELD_HEIGHT))
  })

  it('leaves a field alone that is already clear of the keyboard', () => {
    const { scroller, field } = page()
    tap(field)
    keyboard(100)
    expect(scroller.scrollTop).toBe(0)
  })

  it('undoes a Samsung pan that pinned the field to the top edge', () => {
    const { scroller, field } = page()
    tap(field)
    // The WebView scrolls the field to the top before the inset arrives.
    scroller.scrollTop = TAP_TOP - 2
    keyboard(KEYBOARD_PX)
    expect(scroller.scrollTop).toBe(TAP_TOP - (800 - KEYBOARD_PX - 16 - FIELD_HEIGHT))
  })

  it('reads the keyboard height when the WebView itself already shrank', () => {
    const { scroller, field } = page()
    tap(field)
    // Shrunk layout: the shell publishes a 0 inset, innerHeight is the room left.
    setInnerHeight(800 - KEYBOARD_PX)
    keyboard(0, KEYBOARD_PX)
    expect(scroller.scrollTop).toBe(TAP_TOP - (800 - KEYBOARD_PX - 16 - FIELD_HEIGHT))
  })

  it('keeps correcting while the keyboard animation pans the page', () => {
    const { scroller, field } = page()
    tap(field)
    keyboard(KEYBOARD_PX)
    const settled = scroller.scrollTop
    scroller.scrollTop = 0
    vi.advanceTimersByTime(400)
    expect(scroller.scrollTop).toBe(settled)
  })

  it('stops once the keyboard is gone', () => {
    const { scroller, field } = page()
    tap(field)
    keyboard(KEYBOARD_PX)
    keyboard(0)
    scroller.scrollTop = 0
    vi.advanceTimersByTime(400)
    field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
    expect(scroller.scrollTop).toBe(0)
  })
})
