import { describe, expect, it } from 'vitest'
import {
  isTextEntry,
  shouldFocusComposer,
  typingKeyForComposer,
} from '@/composables/useComposerTyping'

function keydown(key: string, init: KeyboardEventInit = {}): KeyboardEvent {
  return new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
}

describe('useComposerTyping', () => {
  it('treats fields as text entry and chrome as not', () => {
    const field = document.createElement('textarea')
    const button = document.createElement('button')
    expect(isTextEntry(field)).toBe(true)
    expect(isTextEntry(button)).toBe(false)
  })

  it('takes a letter typed on a button and leaves shortcuts and fields alone', () => {
    const button = document.createElement('button')
    document.body.appendChild(button)
    const letter = keydown('h')
    button.dispatchEvent(letter)
    expect(typingKeyForComposer(letter)).toBe('h')

    const field = document.createElement('input')
    document.body.appendChild(field)
    const typed = keydown('h')
    field.dispatchEvent(typed)
    expect(typingKeyForComposer(typed)).toBeNull()

    const shortcut = keydown('k', { metaKey: true })
    button.dispatchEvent(shortcut)
    expect(typingKeyForComposer(shortcut)).toBeNull()

    const space = keydown(' ')
    button.dispatchEvent(space)
    expect(typingKeyForComposer(space)).toBeNull()

    button.remove()
    field.remove()
  })

  it('does not move focus while a dialog is open or the user is in another field', () => {
    const composer = document.createElement('textarea')
    const search = document.createElement('input')
    expect(shouldFocusComposer(search, composer)).toBe(false)
    expect(shouldFocusComposer(document.body, composer)).toBe(true)

    const dialog = document.createElement('div')
    dialog.setAttribute('aria-modal', 'true')
    document.body.appendChild(dialog)
    expect(shouldFocusComposer(document.body, composer)).toBe(false)
    dialog.remove()
  })
})
