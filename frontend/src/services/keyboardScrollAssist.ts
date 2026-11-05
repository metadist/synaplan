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
 * This module keeps EVERY focused editable where the user tapped it:
 *   1. Remember the position on pointerdown, before the keyboard pans.
 *   2. Reserve room — temporary bottom padding on the nearest scrollable
 *      ancestor, so a short page can still clear the keyboard.
 *   3. If Samsung pins the field to the top edge, restore that scroll, then
 *      move it only as far as the keyboard actually covers.
 *
 * The native shell publishes `synaplan:keyboardinset`. `detail.height` is the
 * CSS lift (0 when the WebView already shrank). `detail.keyboardHeight` is the
 * IME, so a shrunk layout still reveals the field. `focusin` covers a jump to
 * another field while the keyboard stays open.
 *
 * Web build is a deliberate no-op: mobile browsers shrink the visual viewport
 * and scroll focused inputs into view themselves, and the inset event never
 * fires there.
 */
import { isNativeApp } from '@/services/api/nativeRuntime'
import {
  editableFieldFrom,
  isEditableField,
  isFieldShovedToTop,
  keyboardRevealDelta,
  keyboardVisibleBottom,
} from '@/services/keyboardReveal'

let initialized = false
/** Current soft-keyboard height in px (0 when hidden). */
let keyboardHeight = 0
/** CSS lift published by the shell. 0 when the WebView itself already shrank. */
let cssInset = 0
/** The scroll container we padded, so we can restore it on hide/refocus. */
let paddedScroller: HTMLElement | null = null
/** The scroller's inline `padding-bottom` before we touched it (for restore). */
let paddedScrollerOriginalPadding = ''

/** Gap kept between the field and the keyboard, and below the top edge. */
const REVEAL_MARGIN_PX = 16

type ScrollLock = { scroller: HTMLElement; scrollTop: number }

/** Field and scroll positions from the tap, before the keyboard pans. */
let anchorEl: HTMLElement | null = null
let anchorTop = 0
let locks: ScrollLock[] = []
let revealing = false
/** Viewport-driven corrections per gesture. Stops a Samsung re-pan from looping. */
let corrections = 0
let revealTimers: ReturnType<typeof setTimeout>[] = []

const REVEAL_DELAYS_MS = [50, 150, 320]
const MAX_VIEWPORT_CORRECTIONS = 8

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

/** Every scrollable ancestor, plus the document. Samsung may pan any of them. */
function scrollableAncestors(el: Element): HTMLElement[] {
  const found: HTMLElement[] = []
  let node: HTMLElement | null = el.parentElement
  while (node && node !== document.documentElement) {
    const overflowY = window.getComputedStyle(node).overflowY
    if (overflowY === 'auto' || overflowY === 'scroll') found.push(node)
    node = node.parentElement
  }
  const doc = document.scrollingElement
  if (doc instanceof HTMLElement && !found.includes(doc)) found.push(doc)
  if (found.length === 0) found.push(findScrollableAncestor(el))
  return found
}

/** Scroll positions to restore when Samsung pins the focused field to the top. */
function rememberField(el: HTMLElement): void {
  anchorEl = el
  anchorTop = el.getBoundingClientRect().top
  locks = scrollableAncestors(el).map((scroller) => ({
    scroller,
    scrollTop: scroller.scrollTop,
  }))
}

function restoreLocks(): void {
  for (const lock of locks) {
    if (Math.abs(lock.scroller.scrollTop - lock.scrollTop) > 1) {
      lock.scroller.scrollTop = lock.scrollTop
    }
  }
}

function updateLocks(): void {
  for (const lock of locks) {
    lock.scrollTop = lock.scroller.scrollTop
  }
}

/**
 * Band in visual-viewport coordinates. getBoundingClientRect is relative to
 * that viewport, so a viewport that already shrank must not subtract the
 * keyboard a second time. An overlay keyboard (viewport still full height)
 * covers the bottom of the same coordinate space.
 */
function visibleBand(): { top: number; bottom: number } {
  const vv = window.visualViewport
  const visualHeight = vv && vv.height > 0 ? vv.height : window.innerHeight
  return {
    top: REVEAL_MARGIN_PX,
    bottom: keyboardVisibleBottom({
      innerHeight: window.innerHeight,
      visualHeight,
      cssInset,
      marginPx: REVEAL_MARGIN_PX,
    }),
  }
}

function reservePadding(el: HTMLElement): void {
  // The shell publishes a 0 CSS lift once the WebView itself shrank. Extra
  // padding there is the gap above the keyboard.
  if (cssInset <= 0) return
  const scroller = findScrollableAncestor(el)
  if (scroller !== paddedScroller) {
    restoreScrollerPadding()
    paddedScroller = scroller
    paddedScrollerOriginalPadding = scroller.style.paddingBottom
    const basePadding = parseFloat(window.getComputedStyle(scroller).paddingBottom) || 0
    scroller.style.paddingBottom = `${basePadding + keyboardHeight + REVEAL_MARGIN_PX}px`
  }
  // Commit the padding before measuring, so a short page can scroll.
  void scroller.scrollHeight
}

function scrollByDelta(el: HTMLElement, delta: number): void {
  let remaining = delta
  for (const scroller of scrollableAncestors(el)) {
    if (Math.abs(remaining) < 1) return
    const before = el.getBoundingClientRect().top
    scroller.scrollBy({ top: remaining, behavior: 'auto' })
    const moved = before - el.getBoundingClientRect().top
    remaining -= moved
  }
}

function clearRevealTimers(): void {
  for (const id of revealTimers) clearTimeout(id)
  revealTimers = []
}

/**
 * Put the focused field back where the user tapped it, then lift it only as
 * far as the keyboard requires. The chat composer floats on its own; here we
 * only undo the pan that shoved it to the top.
 */
function revealActiveField(fromViewport = false): void {
  if (revealing || keyboardHeight <= 0) return
  if (fromViewport && corrections >= MAX_VIEWPORT_CORRECTIONS) return
  const active = document.activeElement
  const field =
    anchorEl && document.contains(anchorEl) && isEditableField(anchorEl)
      ? anchorEl
      : isEditableField(active)
        ? active
        : null
  if (!field) return
  revealing = true
  let changed = false
  try {
    if (isFieldShovedToTop(field.getBoundingClientRect().top, anchorTop)) {
      restoreLocks()
      changed = true
    }
    if (isChatComposerField(field)) return
    reservePadding(field)
    const rect = field.getBoundingClientRect()
    const band = visibleBand()
    const delta = keyboardRevealDelta({
      anchorTop,
      fieldTop: rect.top,
      fieldHeight: rect.height,
      visibleTop: band.top,
      visibleBottom: band.bottom,
    })
    if (delta === 0) return
    scrollByDelta(field, delta)
    updateLocks()
    changed = true
  } finally {
    if (fromViewport && changed) corrections += 1
    revealing = false
  }
}

/** willShow lands before Samsung finishes panning, so correct across the animation. */
function scheduleReveal(): void {
  clearRevealTimers()
  corrections = 0
  revealActiveField()
  for (const delay of REVEAL_DELAYS_MS) {
    revealTimers.push(setTimeout(() => revealActiveField(), delay))
  }
}

function readKeyboardHeight(detail: { height?: number; keyboardHeight?: number } | null): number {
  if (!detail) return 0
  if (typeof detail.keyboardHeight === 'number') return detail.keyboardHeight
  return typeof detail.height === 'number' ? detail.height : 0
}

function onKeyboardInset(event: Event): void {
  const detail = (event as CustomEvent<{ height?: number; keyboardHeight?: number }>).detail
  cssInset = detail && typeof detail.height === 'number' ? detail.height : 0
  keyboardHeight = readKeyboardHeight(detail)
  if (keyboardHeight > 0) {
    scheduleReveal()
  } else {
    clearRevealTimers()
    restoreScrollerPadding()
    anchorEl = null
    anchorTop = 0
    locks = []
    corrections = 0
  }
}

/** Record the resting position before the keyboard (or a field change) pans. */
function onPointerDown(event: PointerEvent): void {
  const el = editableFieldFrom(event.target)
  if (!el) return
  corrections = 0
  rememberField(el)
}

/** Re-run when focus jumps to another field while the keyboard is already up. */
function onFocusIn(): void {
  const el = document.activeElement
  if (!isEditableField(el)) return
  if (el !== anchorEl) {
    corrections = 0
    rememberField(el)
  }
  if (keyboardHeight > 0) scheduleReveal()
}

function onViewport(): void {
  if (keyboardHeight > 0) revealActiveField(true)
}

/**
 * Wire the global keyboard scroll-assist. Idempotent; native shell only.
 * Called once from App.vue, alongside the other native bootstraps.
 */
export function initKeyboardScrollAssist(): void {
  if (initialized || !isNativeApp()) return
  initialized = true
  window.addEventListener('synaplan:keyboardinset', onKeyboardInset)
  document.addEventListener('pointerdown', onPointerDown, true)
  document.addEventListener('focusin', onFocusIn)
  window.visualViewport?.addEventListener('resize', onViewport)
  window.visualViewport?.addEventListener('scroll', onViewport)
}
