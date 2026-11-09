/**
 * Dismiss the on-screen keyboard when the user *taps* outside the focused
 * field, on every page: iOS and Android keep a text control focused (keyboard
 * up) until it is explicitly blurred, so a tap on empty page area would
 * otherwise never close it. Sign-in, setup and dialogs outside MainLayout get
 * the same behavior as the chat.
 *
 * A TAP is not a SCROLL: blurring on `pointerdown` would kill the keyboard the
 * moment the user starts scrolling. The start position is recorded and the
 * blur happens on `pointerup` only when the pointer barely moved. A scroll or
 * drag moves past the tolerance and leaves the keyboard open; the keyboard
 * then only closes on a real tap or the native swipe-down gesture.
 */
import { isEditableField } from '@/services/keyboardReveal'

export const KEYBOARD_TAP_MOVE_TOLERANCE_PX = 10

/** A press that starts on one of these keeps the keyboard; the control owns the tap. */
const FIELD_SELECTOR = 'input, textarea, select, [contenteditable=""], [contenteditable="true"]'

let installed = false
let tapStartX = 0
let tapStartY = 0
let tapCandidate = false

function startsOnField(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest(FIELD_SELECTOR) !== null
}

function onPointerDown(event: PointerEvent): void {
  tapCandidate = false
  if (!isEditableField(document.activeElement)) return
  if (startsOnField(event.target)) return
  tapStartX = event.clientX
  tapStartY = event.clientY
  tapCandidate = true
}

function onPointerUp(event: PointerEvent): void {
  if (!tapCandidate) return
  tapCandidate = false
  if (
    Math.abs(event.clientX - tapStartX) > KEYBOARD_TAP_MOVE_TOLERANCE_PX ||
    Math.abs(event.clientY - tapStartY) > KEYBOARD_TAP_MOVE_TOLERANCE_PX
  ) {
    return
  }
  const active = document.activeElement
  if (!isEditableField(active)) return
  // Released over another field: let it take focus.
  if (startsOnField(event.target)) return
  active.blur()
}

// A touch that turns into a scroll fires pointercancel, not pointerup, once
// the browser claims the gesture. Reset so no stray blur happens afterwards.
function onPointerCancel(): void {
  tapCandidate = false
}

/**
 * Wire the global tap-to-dismiss. Idempotent; called once from App.vue.
 * Returns the teardown (tests); the app keeps it for its lifetime.
 */
export function initKeyboardDismiss(): () => void {
  if (installed) return () => {}
  installed = true
  document.addEventListener('pointerdown', onPointerDown)
  document.addEventListener('pointerup', onPointerUp)
  document.addEventListener('pointercancel', onPointerCancel)
  return () => {
    document.removeEventListener('pointerdown', onPointerDown)
    document.removeEventListener('pointerup', onPointerUp)
    document.removeEventListener('pointercancel', onPointerCancel)
    tapCandidate = false
    installed = false
  }
}
