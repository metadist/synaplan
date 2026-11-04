import { beforeEach, describe, expect, it } from 'vitest'
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
    field.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    field.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: send }))
    field.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))
    profile.dispatchEvent(new FocusEvent('focusin', { bubbles: true }))

    expect(calls).toEqual([true, false])
  })
})
