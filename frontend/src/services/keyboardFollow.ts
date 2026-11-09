/**
 * Ask the native shell for live keyboard frames while a text field is focused.
 *
 * The shell (synaplan-apps) exposes `window.SynaplanChatKeyboard`. While it is
 * enabled the native side reports the keyboard's overlap on every animation
 * frame and the bootstrap writes it into `--keyboard-inset-height`; the content
 * column shrinks by that amount (shrink model, style.css). Without frames the
 * shell only knows the final height once the keyboard has finished moving.
 *
 * Every editable field counts, not just the chat composer: the whole page
 * shrinks, so every field needs the same frames. Absent bridge (plain web):
 * no-op.
 */
import { editableFieldFrom, isEditableField } from '@/services/keyboardReveal'

/**
 * iOS blurs the field after a tap on a control that does not take focus (send,
 * attach, mic) and leaves `relatedTarget` null, although the page refocuses the
 * field right away. Give that refocus this long before the frames are released.
 */
export const CONTROL_BLUR_MS = 350

const CONTROL_SELECTOR = 'button, [role="button"], [role="tab"], a, label'

interface KeyboardFrameApi {
  setDocked: (enabled: boolean) => void
}

function keyboardFrameApi(): KeyboardFrameApi | null {
  const candidate = (globalThis as { SynaplanChatKeyboard?: unknown }).SynaplanChatKeyboard
  if (
    candidate &&
    'object' === typeof candidate &&
    'function' === typeof (candidate as KeyboardFrameApi).setDocked
  ) {
    return candidate as KeyboardFrameApi
  }
  return null
}

/** True for a non-editable control the user may tap while a field is focused. */
function isControl(target: EventTarget | null): boolean {
  return (
    target instanceof Element &&
    target.closest(CONTROL_SELECTOR) !== null &&
    editableFieldFrom(target) === null
  )
}

/** Focus/pointer state machine deciding when the shell should send frames. */
class KeyboardFollower {
  private following = false
  private controlPointerAt = 0
  private releaseTimer: ReturnType<typeof setTimeout> | null = null

  private recentControlTap(): boolean {
    return Date.now() - this.controlPointerAt < CONTROL_BLUR_MS
  }

  private clearReleaseTimer(): void {
    if (this.releaseTimer !== null) {
      clearTimeout(this.releaseTimer)
      this.releaseTimer = null
    }
  }

  private follow(enabled: boolean): void {
    if (this.following === enabled) return
    this.following = enabled
    keyboardFrameApi()?.setDocked(enabled)
  }

  /** Forget the pending gesture and settle on `enabled`. */
  private settle(enabled: boolean): void {
    this.clearReleaseTimer()
    this.controlPointerAt = 0
    this.follow(enabled)
  }

  // The shell reports 0 once the keyboard is gone; the next field starts over.
  readonly onKeyboardInset = (event: Event): void => {
    const detail = (event as CustomEvent<{ height?: number; keyboardHeight?: number }>).detail
    const height = detail?.keyboardHeight ?? detail?.height ?? 0
    if (height <= 0) {
      this.clearReleaseTimer()
      this.following = false
    }
  }

  // pointerdown runs before focus and before the keyboard notification, so the
  // frames are requested in time for the first one.
  readonly onPointerDown = (event: PointerEvent): void => {
    if (editableFieldFrom(event.target)) {
      this.settle(true)
      return
    }
    if (this.following && isControl(event.target)) {
      this.controlPointerAt = Date.now()
      return
    }
    this.clearReleaseTimer()
    this.controlPointerAt = 0
  }

  readonly onFocusIn = (event: FocusEvent): void => {
    if (editableFieldFrom(event.target)) {
      this.clearReleaseTimer()
      this.follow(true)
      return
    }
    if (this.following && isControl(event.target) && this.recentControlTap()) {
      return
    }
    this.settle(false)
  }

  readonly onFocusOut = (event: FocusEvent): void => {
    const viaControl =
      isControl(event.relatedTarget) || (event.relatedTarget === null && this.recentControlTap())
    if (editableFieldFrom(event.relatedTarget)) {
      this.clearReleaseTimer()
      this.controlPointerAt = 0
      return
    }
    if (viaControl && editableFieldFrom(event.target)) {
      // The control's own focusin (Android focuses buttons) follows this event
      // and must still see the gesture, so the timestamp stays until the timer
      // decides. It is time-bounded either way.
      this.clearReleaseTimer()
      this.releaseTimer = setTimeout(() => {
        this.releaseTimer = null
        if (!isEditableField(document.activeElement)) {
          this.follow(false)
        }
      }, CONTROL_BLUR_MS)
      return
    }
    this.settle(false)
  }

  dispose(): void {
    this.clearReleaseTimer()
  }
}

let installed = false

const noop = (): void => {}

/**
 * Wire field focus to the native keyboard frames. Idempotent; no-op without
 * the bridge. Returns the teardown (tests); the app keeps it for its lifetime.
 */
export function initKeyboardFollow(): () => void {
  if (installed || !keyboardFrameApi()) return noop
  installed = true
  const follower = new KeyboardFollower()

  window.addEventListener('synaplan:keyboardinset', follower.onKeyboardInset)
  document.addEventListener('pointerdown', follower.onPointerDown, true)
  document.addEventListener('focusin', follower.onFocusIn)
  document.addEventListener('focusout', follower.onFocusOut)

  return () => {
    follower.dispose()
    window.removeEventListener('synaplan:keyboardinset', follower.onKeyboardInset)
    document.removeEventListener('pointerdown', follower.onPointerDown, true)
    document.removeEventListener('focusin', follower.onFocusIn)
    document.removeEventListener('focusout', follower.onFocusOut)
    installed = false
  }
}
