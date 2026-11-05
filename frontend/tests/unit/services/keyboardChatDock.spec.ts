import { beforeEach, describe, expect, it, vi } from 'vitest'
import { initKeyboardChatDock, isChatComposerTarget } from '@/services/keyboardChatDock'

function composerField(): HTMLTextAreaElement {
  const composer = document.createElement('div')
  composer.className = 'chat-composer-sticky'
  const field = document.createElement('textarea')
  composer.appendChild(field)
  document.body.appendChild(composer)
  return field
}

describe('isChatComposerTarget', () => {
  beforeEach(() => {
    document.body.replaceChildren()
  })

  it('matches a field inside the chat composer', () => {
    expect(isChatComposerTarget(composerField())).toBe(true)
  })

  it('ignores composer buttons and file inputs', () => {
    const field = composerField()
    const send = document.createElement('button')
    const fileInput = document.createElement('input')
    fileInput.type = 'file'
    field.parentElement?.append(send, fileInput)
    expect(isChatComposerTarget(send)).toBe(false)
    expect(isChatComposerTarget(fileInput)).toBe(false)
  })

  it('ignores a profile field', () => {
    const field = document.createElement('input')
    document.body.appendChild(field)
    expect(isChatComposerTarget(field)).toBe(false)
  })
})

describe('initKeyboardChatDock', () => {
  beforeEach(() => {
    document.body.replaceChildren()
    delete (globalThis as { SynaplanChatKeyboard?: unknown }).SynaplanChatKeyboard
  })

  it('does nothing when the native bridge is missing', () => {
    const field = composerField()
    expect(() => initKeyboardChatDock()).not.toThrow()
    field.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
  })

  it('docks only while the chat composer is the active field', () => {
    vi.useFakeTimers()
    try {
      const calls: boolean[] = []
      ;(
        globalThis as { SynaplanChatKeyboard?: { setDocked: (enabled: boolean) => void } }
      ).SynaplanChatKeyboard = {
        setDocked: (enabled) => {
          calls.push(enabled)
        },
      }
      const field = composerField()
      const profile = document.createElement('input')
      const send = document.createElement('button')
      field.parentElement?.appendChild(send)
      document.body.appendChild(profile)

      initKeyboardChatDock()

      // Tapping composer buttons before the textarea is focused must not dock.
      send.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
      send.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
      expect(calls).toEqual([])

      field.focus()
      field.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
      field.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: send }))
      send.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
      field.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: null }))
      expect(calls).toEqual([true])

      profile.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
      field.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: null }))
      profile.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))

      expect(calls).toEqual([true, false])

      // If a composer button blurs the textarea and never refocuses it, undock after the gesture.
      field.focus()
      field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
      expect(calls).toEqual([true, false, true])
      send.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
      send.focus()
      field.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: null }))
      vi.advanceTimersByTime(400)
      expect(calls).toEqual([true, false, true, false])

      // If the OS hides the keyboard while the textarea stays focused, tapping again re-docks.
      field.focus()
      field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
      expect(calls).toEqual([true, false, true, false, true])
      window.dispatchEvent(
        new CustomEvent('synaplan:keyboardinset', { detail: { height: 0, keyboardHeight: 0 } })
      )
      field.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
      expect(calls).toEqual([true, false, true, false, true, true])
    } finally {
      vi.useRealTimers()
    }
  })
})
