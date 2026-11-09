/**
 * Pin the chat composer to the native keyboard.
 *
 * The shell (synaplan-apps) exposes `window.SynaplanChatKeyboard` and docks
 * the WebView to the keyboard. This file only tells it when the composer is
 * the field being edited. Other inputs stay on the overlay scroll-assist.
 * Absent bridge (plain web): no-op.
 */
import { initChatKeyboardLift } from '@/services/keyboardChatLift'
import { editableFieldFrom } from '@/services/keyboardReveal'

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

/** True only for an editable text field inside the chat composer. */
export function isChatComposerTarget(target: EventTarget | null): boolean {
  const field = editableFieldFrom(target)
  return field !== null && field.closest(COMPOSER_SELECTOR) !== null
}

/** True for non-editable controls (send, attach, mic, etc.) inside the composer bar. */
function isChatComposerChrome(target: EventTarget | null): boolean {
  return (
    target instanceof Element &&
    target.closest(COMPOSER_SELECTOR) !== null &&
    !isChatComposerTarget(target)
  )
}

let installed = false

/** Wire composer focus to the native dock. Idempotent; no-op without the bridge. */
export function initKeyboardChatDock(): void {
  if (installed || !chatKeyboardApi()) return
  installed = true
  const lift = initChatKeyboardLift()
  let docked = false
  let chromePointerAt = 0
  let chromeBlurTimer: ReturnType<typeof setTimeout> | null = null

  const clearChromeBlurTimer = (): void => {
    if (chromeBlurTimer !== null) {
      clearTimeout(chromeBlurTimer)
      chromeBlurTimer = null
    }
  }

  const setDocked = (enabled: boolean): void => {
    if (docked === enabled) return
    docked = enabled
    if (enabled) lift.rememberLayoutHeight()
    chatKeyboardApi()?.setDocked(enabled)
  }

  window.addEventListener('synaplan:keyboardinset', (event: Event) => {
    const detail = (event as CustomEvent<{ height?: number; keyboardHeight?: number }>).detail
    const height = detail?.keyboardHeight ?? detail?.height ?? 0
    if (height <= 0) {
      clearChromeBlurTimer()
      docked = false
    }
  })

  document.addEventListener(
    'pointerdown',
    (event: PointerEvent) => {
      if (isChatComposerTarget(event.target)) {
        clearChromeBlurTimer()
        chromePointerAt = 0
        setDocked(true)
        return
      }
      if (docked && isChatComposerChrome(event.target)) {
        chromePointerAt = Date.now()
        return
      }
      clearChromeBlurTimer()
      chromePointerAt = 0
    },
    true
  )
  document.addEventListener('focusin', (event: FocusEvent) => {
    if (isChatComposerTarget(event.target)) {
      clearChromeBlurTimer()
      setDocked(true)
      return
    }
    if (
      docked &&
      isChatComposerChrome(event.target) &&
      Date.now() - chromePointerAt < CHROME_BLUR_MS
    ) {
      return
    }
    clearChromeBlurTimer()
    chromePointerAt = 0
    setDocked(false)
  })
  document.addEventListener('focusout', (event: FocusEvent) => {
    const fromChrome =
      isChatComposerChrome(event.relatedTarget) ||
      (event.relatedTarget === null && Date.now() - chromePointerAt < CHROME_BLUR_MS)
    chromePointerAt = 0
    if (isChatComposerTarget(event.relatedTarget)) {
      clearChromeBlurTimer()
      return
    }
    if (fromChrome && isChatComposerTarget(event.target)) {
      clearChromeBlurTimer()
      chromeBlurTimer = setTimeout(() => {
        chromeBlurTimer = null
        if (!isChatComposerTarget(document.activeElement)) {
          setDocked(false)
        }
      }, CHROME_BLUR_MS)
      return
    }
    clearChromeBlurTimer()
    setDocked(false)
  })
}
