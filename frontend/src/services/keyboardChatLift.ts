/**
 * How far the docked chat page rises above the native keyboard.
 *
 * The shell writes the keyboard's overlap into `--keyboard-inset-height`.
 * Some Android WebViews still shrink above the IME or pan the visual
 * viewport. Translating the page by the full overlap on top of that leaves an
 * empty band between the composer and the keyboard. The dock CSS therefore
 * reads `--chat-keyboard-lift`: the part of the overlap the layout has not
 * already absorbed.
 */

const KEYBOARD_INSET_VAR = '--keyboard-inset-height'
const CHAT_LIFT_VAR = '--chat-keyboard-lift'
const KEYBOARD_OPEN_CLASS = 'synaplan-keyboard-open'
/** The native shell uses the same tolerance before it treats a height drop as a resize. */
export const LAYOUT_RESIZE_TOLERANCE_PX = 8

export interface ChatKeyboardLiftInput {
  /** Keyboard overlap the shell reports, in CSS pixels. */
  keyboardPx: number
  /** Layout height while the keyboard was closed. */
  baselineHeight: number
  /** Layout height now. */
  layoutHeight: number
  /** How far the visual viewport is panned down (`visualViewport.offsetTop`). */
  panPx: number
}

export function chatKeyboardLiftPx(input: ChatKeyboardLiftInput): number {
  if (input.keyboardPx <= 0) return 0
  if (input.baselineHeight - input.layoutHeight > LAYOUT_RESIZE_TOLERANCE_PX) return 0
  return Math.max(0, Math.round(input.keyboardPx - Math.max(0, input.panPx)))
}

export interface ChatKeyboardLift {
  /** Record the closed-keyboard height. Call before the composer opens the keyboard. */
  rememberLayoutHeight: () => void
  stop: () => void
}

function cssPx(value: string): number {
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0
}

export function initChatKeyboardLift(): ChatKeyboardLift {
  const root = document.documentElement
  let baselineWidth = window.innerWidth
  let baselineHeight = window.innerHeight
  let published = ''

  const keyboardPx = (): number => cssPx(root.style.getPropertyValue(KEYBOARD_INSET_VAR))

  const update = (): void => {
    // A rotation changes the closed height. Without a new baseline the
    // landscape height would read as a shrink and drop the lift on iOS.
    if (window.innerWidth !== baselineWidth) {
      baselineWidth = window.innerWidth
      baselineHeight = window.innerHeight
    }
    const value = `${chatKeyboardLiftPx({
      keyboardPx: keyboardPx(),
      baselineHeight,
      layoutHeight: window.innerHeight,
      panPx: window.visualViewport?.offsetTop ?? 0,
    })}px`
    if (value === published) return
    published = value
    root.style.setProperty(CHAT_LIFT_VAR, value)
  }

  // A resize while the keyboard is still opening is not a closed height.
  // The baseline only moves when the keyboard is known to be down.
  const rememberLayoutHeight = (): void => {
    if (keyboardPx() > 0 || root.classList.contains(KEYBOARD_OPEN_CLASS)) return
    baselineWidth = window.innerWidth
    baselineHeight = window.innerHeight
  }

  const onKeyboardInset = (): void => {
    rememberLayoutHeight()
    update()
  }

  // The shell writes the overlap on every keyboard frame without an event.
  const observer = new MutationObserver(update)
  observer.observe(root, { attributes: true, attributeFilter: ['style'] })
  const viewport = window.visualViewport
  window.addEventListener('resize', update)
  viewport?.addEventListener('resize', update)
  viewport?.addEventListener('scroll', update)
  window.addEventListener('synaplan:keyboardinset', onKeyboardInset)
  update()

  return {
    rememberLayoutHeight,
    stop: () => {
      observer.disconnect()
      window.removeEventListener('resize', update)
      viewport?.removeEventListener('resize', update)
      viewport?.removeEventListener('scroll', update)
      window.removeEventListener('synaplan:keyboardinset', onKeyboardInset)
    },
  }
}
