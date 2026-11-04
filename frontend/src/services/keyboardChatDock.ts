/**
 * Pin the chat composer to the native keyboard.
 *
 * The shell (synaplan-apps) exposes `window.SynaplanChatKeyboard` and docks
 * the WebView to the keyboard. This file only tells it when the composer is
 * the field being edited. Other inputs stay on the overlay scroll-assist.
 * Absent bridge (plain web): no-op.
 */

const COMPOSER_SELECTOR = '.chat-composer-sticky'
/**
 * iOS often blurs after pointerup (compatibility click) and leaves
 * `relatedTarget` null when the tap hit composer chrome that does not take
 * focus. The keyboard is still up for that gesture.
 */
const CHROME_BLUR_MS = 350

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

/** Tap landed in the field that already has focus, so this is not chrome. */
function pointerHitsFocusedField(target: EventTarget | null): boolean {
  if (!(target instanceof Node)) return false
  const active = document.activeElement
  if (
    !(active instanceof Element) ||
    active === document.body ||
    active === document.documentElement
  ) {
    return false
  }
  return active === target || active.contains(target)
}

let installed = false

/** Wire composer focus to the native dock. Idempotent; no-op without the bridge. */
export function initKeyboardChatDock(): void {
  if (installed || !chatKeyboardApi()) return
  installed = true
  let docked = false
  let chromePointerAt = 0
  const setDocked = (enabled: boolean): void => {
    if (docked === enabled) return
    docked = enabled
    chatKeyboardApi()?.setDocked(enabled)
  }

  document.addEventListener(
    'pointerdown',
    (event: PointerEvent) => {
      const inside = isChatComposerTarget(event.target)
      if (inside) setDocked(true)
      chromePointerAt = inside && !pointerHitsFocusedField(event.target) ? Date.now() : 0
    },
    true
  )
  document.addEventListener('focusin', (event: FocusEvent) => {
    setDocked(isChatComposerTarget(event.target))
  })
  document.addEventListener('focusout', (event: FocusEvent) => {
    const fromChrome = Date.now() - chromePointerAt < CHROME_BLUR_MS
    chromePointerAt = 0
    if (isChatComposerTarget(event.relatedTarget)) return
    if (event.relatedTarget === null && fromChrome && isChatComposerTarget(event.target)) return
    setDocked(false)
  })
}
