import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CONTROL_BLUR_MS, initKeyboardFollow } from '@/services/keyboardFollow'

type Bridge = { setDocked: (enabled: boolean) => void }

function installBridge(): boolean[] {
  const calls: boolean[] = []
  ;(globalThis as { SynaplanChatKeyboard?: Bridge }).SynaplanChatKeyboard = {
    setDocked: (enabled) => {
      calls.push(enabled)
    },
  }
  return calls
}

function pointerDown(el: Element): void {
  el.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
}

function focusIn(el: Element): void {
  el.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
}

function focusOut(el: Element, relatedTarget: Element | null): void {
  el.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget }))
}

function keyboardClosed(): void {
  window.dispatchEvent(
    new CustomEvent('synaplan:keyboardinset', { detail: { height: 0, keyboardHeight: 0 } })
  )
}

describe('initKeyboardFollow', () => {
  let stop: () => void = () => {}

  beforeEach(() => {
    document.body.replaceChildren()
    delete (globalThis as { SynaplanChatKeyboard?: unknown }).SynaplanChatKeyboard
    vi.useFakeTimers()
  })

  afterEach(() => {
    stop()
    vi.useRealTimers()
  })

  it('does nothing when the native bridge is missing', () => {
    const field = document.createElement('input')
    document.body.appendChild(field)
    expect(() => {
      stop = initKeyboardFollow()
    }).not.toThrow()
    pointerDown(field)
    focusIn(field)
  })

  it('requests frames for any text field, not only the chat composer', () => {
    const calls = installBridge()
    const composer = document.createElement('div')
    composer.className = 'chat-composer-sticky'
    const textarea = document.createElement('textarea')
    composer.appendChild(textarea)
    const send = document.createElement('button')
    composer.appendChild(send)
    const profile = document.createElement('input')
    const checkbox = document.createElement('input')
    checkbox.type = 'checkbox'
    document.body.append(composer, profile, checkbox)

    stop = initKeyboardFollow()

    // A tap on a button before any field is focused asks for nothing.
    pointerDown(send)
    focusIn(send)
    expect(calls).toEqual([])

    // The profile field is a text field like any other.
    pointerDown(profile)
    profile.focus()
    focusIn(profile)
    expect(calls).toEqual([true])

    // Jumping to the composer keeps the frames on; no duplicate request.
    focusOut(profile, textarea)
    pointerDown(textarea)
    textarea.focus()
    focusIn(textarea)
    expect(calls).toEqual([true])

    // A checkbox never shows a keyboard: frames are released.
    focusOut(textarea, checkbox)
    checkbox.focus()
    focusIn(checkbox)
    expect(calls).toEqual([true, false])

    // The keyboard closed on its own; the next field starts over.
    keyboardClosed()
    pointerDown(profile)
    expect(calls).toEqual([true, false, true])
  })

  it('keeps the frames across a tap on a control that refocuses the field', () => {
    const calls = installBridge()
    const textarea = document.createElement('textarea')
    const send = document.createElement('button')
    document.body.append(textarea, send)
    stop = initKeyboardFollow()

    pointerDown(textarea)
    textarea.focus()
    focusIn(textarea)
    expect(calls).toEqual([true])

    // iOS: the send button blurs the field with relatedTarget null, the page
    // refocuses it right away.
    pointerDown(send)
    focusOut(textarea, null)
    textarea.focus()
    focusIn(textarea)
    vi.advanceTimersByTime(CONTROL_BLUR_MS + 50)
    expect(calls).toEqual([true])

    // Same tap, but nothing refocuses: released once the gesture is over.
    pointerDown(send)
    send.focus()
    focusOut(textarea, null)
    expect(calls).toEqual([true])
    vi.advanceTimersByTime(CONTROL_BLUR_MS + 50)
    expect(calls).toEqual([true, false])
  })

  it('releases the frames when the field is blurred by a tap on the page', () => {
    const calls = installBridge()
    const input = document.createElement('input')
    const page = document.createElement('div')
    document.body.append(input, page)
    stop = initKeyboardFollow()

    pointerDown(input)
    input.focus()
    focusIn(input)
    pointerDown(page)
    focusOut(input, null)
    expect(calls).toEqual([true, false])
  })
})
