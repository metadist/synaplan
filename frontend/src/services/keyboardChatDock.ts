/**
 * Pin the chat composer to the native keyboard.
 *
 * The shell (synaplan-apps) exposes `window.SynaplanChatKeyboard` and docks
 * the WebView to the keyboard. This file only tells it when the composer is
 * the field being edited. Other inputs stay on the overlay scroll-assist.
 * Absent bridge (plain web): no-op.
 */

const COMPOSER_SELECTOR = '.chat-composer-sticky'

interface ChatKeyboardApi {
  setDocked: (enabled: boolean) => void
}

function chatKeyboardApi(): ChatKeyboardApi | null {
  const candidate = (globalThis as { SynaplanChatKeyboard?: unknown }).SynaplanChatKeyboard
  if (
    candidate &&
    'object' === typeof candidate &&
    'function' === typeof (candidate as ChatKeyboardApi).setDocked
  ) {
    return candidate as ChatKeyboardApi
  }
  return null
}

export function isChatComposerTarget(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest(COMPOSER_SELECTOR) !== null
}

let installed = false

/** Wire composer focus to the native dock. Idempotent; no-op without the bridge. */
export function initKeyboardChatDock(): void {
  if (installed || !chatKeyboardApi()) return
  installed = true
  let docked = false
  const setDocked = (enabled: boolean): void => {
    if (docked === enabled) return
    docked = enabled
    chatKeyboardApi()?.setDocked(enabled)
  }

  document.addEventListener(
    'pointerdown',
    (event: PointerEvent) => {
      if (isChatComposerTarget(event.target)) setDocked(true)
    },
    true
  )
  document.addEventListener('focusin', (event: FocusEvent) => {
    setDocked(isChatComposerTarget(event.target))
  })
  document.addEventListener('focusout', (event: FocusEvent) => {
    if (isChatComposerTarget(event.relatedTarget)) return
    setDocked(false)
  })
}
