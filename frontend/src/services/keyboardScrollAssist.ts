/**
 * Keyboard scroll-assist for the native shell (Capacitor `Keyboard.resize: 'none'`).
 *
 * With `resize: 'none'` the WebView never shrinks, so an opening soft keyboard
 * simply OVERLAYS the bottom of the page. The chat composer copes on its own by
 * floating up via the `--keyboard-inset-height` CSS var (see
 * `synaplan-apps/app/synaplan-native.js` + `.chat-composer-sticky` in style.css).
 * But an ordinary input in a form, dialog or settings page has no such handling:
 * it stays hidden behind the keyboard, and on a short (non-scrollable) page the
 * user cannot even scroll to reach it.
 *
 * This module gives EVERY focused editable element the same "lift above the
 * keyboard" behavior as the chat:
 *   1. Reserve room — add a temporary bottom padding (= keyboard height) to the
 *      field's nearest scrollable ancestor, so even a short page becomes
 *      scrollable enough to clear the keyboard.
 *   2. Reveal — scroll that ancestor so the field sits just above the keyboard.
 *
 * The native shell already publishes the keyboard height on every show/hide as
 * the `synaplan:keyboardinset` CustomEvent (detail.height). We consume that plus
 * `focusin` (focus can move between fields while the keyboard stays open).
 *
 * Web build is a deliberate no-op: mobile browsers shrink the visual viewport
 * and scroll focused inputs into view themselves, and the inset event never
 * fires there.
 */
import { isNativeApp } from '@/services/api/nativeRuntime'

let initialized = false
/** Current soft-keyboard height in px (0 when hidden). */
let keyboardHeight = 0
/** The scroll container we padded, so we can restore it on hide/refocus. */
let paddedScroller: HTMLElement | null = null
/** The scroller's inline `padding-bottom` before we touched it (for restore). */
let paddedScrollerOriginalPadding = ''

/** Gap kept between the field's bottom edge and the top of the keyboard. */
const REVEAL_MARGIN_PX = 16

/** Input types that never show a keyboard — never treat these as editable. */
const NON_TEXT_INPUT_TYPES = new Set([
  'button',
  'submit',
  'reset',
  'checkbox',
  'radio',
  'range',
  'color',
  'file',
  'image',
  'hidden',
])

function isEditable(el: Element | null): el is HTMLElement {
  if (!(el instanceof HTMLElement)) return false
  if (el.isContentEditable) return true
  if (el instanceof HTMLTextAreaElement) return true
  if (el instanceof HTMLInputElement) return !NON_TEXT_INPUT_TYPES.has(el.type)
  return false
}

/** The chat composer manages its own keyboard float — never fight it. */
function isChatComposerField(el: Element): boolean {
  return el.closest('.chat-composer-sticky') !== null
}

/**
 * Nearest ancestor that scrolls vertically. Falls back to the app's primary
 * content area, then the document scroller, so a field is always liftable.
 */
function findScrollableAncestor(el: Element): HTMLElement {
  let node: HTMLElement | null = el.parentElement
  while (node && node !== document.body) {
    const overflowY = window.getComputedStyle(node).overflowY
    if (overflowY === 'auto' || overflowY === 'scroll') return node
    node = node.parentElement
  }
  const primary = document.querySelector<HTMLElement>('main[data-testid="section-primary-content"]')
  if (primary && primary.contains(el)) return primary
  return (document.scrollingElement as HTMLElement | null) ?? document.documentElement
}

function restoreScrollerPadding(): void {
  if (paddedScroller) {
    paddedScroller.style.paddingBottom = paddedScrollerOriginalPadding
    paddedScroller = null
    paddedScrollerOriginalPadding = ''
  }
}

/**
 * Bring the focused field clear of the keyboard. No-op when it is already
 * inside the keyboard-free area.
 *
 * We delegate the actual scrolling to `Element.scrollIntoView({ block:
 * 'center' })` rather than computing a manual `scrollBy`: with `resize: 'none'`
 * the WebView never shrinks, so we do NOT know which ancestor actually scrolls —
 * `scrollIntoView` walks and scrolls every scrollable ancestor for us. Because
 * the layout viewport stays full height, "center" lands the field around the
 * vertical middle of the screen, safely above the keyboard. If the reserved
 * bottom padding is not enough to fully center a field at the very bottom, the
 * scroll simply clamps — which lands the field right above the keyboard, still
 * the desired result.
 */
function scrollFieldClear(el: HTMLElement, scroller: HTMLElement): void {
  if (keyboardHeight <= 0) return
  // Reading a layout property commits the pending padding change synchronously
  // so the reserved scroll range exists before we scroll.
  void scroller.scrollHeight
  const rect = el.getBoundingClientRect()
  const keyboardFreeBottom = window.innerHeight - keyboardHeight - REVEAL_MARGIN_PX
  const obscured = rect.bottom > keyboardFreeBottom || rect.top < REVEAL_MARGIN_PX
  if (!obscured) return
  el.scrollIntoView({ block: 'center', behavior: 'smooth' })
}

function revealActiveField(): void {
  if (keyboardHeight <= 0) return
  const el = document.activeElement
  if (!isEditable(el) || isChatComposerField(el)) return

  const scroller = findScrollableAncestor(el)

  // 1. Reserve room. Padding by keyboard height + margin guarantees the page can
  //    scroll far enough to lift a field sitting at the very bottom clear of the
  //    keyboard, even when the page's own content is shorter than the viewport.
  if (scroller !== paddedScroller) {
    restoreScrollerPadding()
    paddedScroller = scroller
    paddedScrollerOriginalPadding = scroller.style.paddingBottom
    const basePadding = parseFloat(window.getComputedStyle(scroller).paddingBottom) || 0
    scroller.style.paddingBottom = `${basePadding + keyboardHeight + REVEAL_MARGIN_PX}px`
  }

  // 2. Reveal on the next frame (padding applied). This runs on BOTH the
  //    keyboardWillShow and keyboardDidShow inset signals (synaplan-native.js
  //    fires the inset event for both): the willShow pass positions early, the
  //    didShow pass corrects it after WKWebView settles the keyboard animation.
  requestAnimationFrame(() => scrollFieldClear(el, scroller))
}

function onKeyboardInset(event: Event): void {
  const detail = (event as CustomEvent<{ height?: number }>).detail
  keyboardHeight = detail && typeof detail.height === 'number' ? detail.height : 0
  if (keyboardHeight > 0) {
    revealActiveField()
  } else {
    restoreScrollerPadding()
  }
}

/** Re-run when focus jumps to another field while the keyboard is already up. */
function onFocusIn(): void {
  if (keyboardHeight > 0) revealActiveField()
}

/**
 * Wire the global keyboard scroll-assist. Idempotent; native shell only.
 * Called once from App.vue, alongside the other native bootstraps.
 */
export function initKeyboardScrollAssist(): void {
  if (initialized || !isNativeApp()) return
  initialized = true
  window.addEventListener('synaplan:keyboardinset', onKeyboardInset)
  document.addEventListener('focusin', onFocusIn)
}
