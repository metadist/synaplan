/** Ignore sub-pixel noise so a settled field does not scroll again. */
export const KEYBOARD_REVEAL_EPSILON_PX = 2

/** Input types that never show a soft keyboard — never treat these as text fields. */
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

export function isEditableField(el: Element | null): el is HTMLElement {
  if (!(el instanceof HTMLElement)) return false
  if (el.isContentEditable) return true
  if (el instanceof HTMLTextAreaElement) return true
  if (el instanceof HTMLInputElement) return !NON_TEXT_INPUT_TYPES.has(el.type)
  return false
}

export function editableFieldFrom(target: EventTarget | null): HTMLElement | null {
  if (!(target instanceof Element)) return null
  const el = target.closest('input, textarea, [contenteditable]')
  return isEditableField(el) ? el : null
}

/**
 * Where the focused field should sit while the keyboard is open.
 *
 * Keep the spot where the user tapped. If that spot is behind the keyboard
 * or above the visible top, clamp it into the visible band. The vertical
 * center is the wrong target: Samsung's WebView already shoves the field to
 * the top edge, and centering then clamps there, so the field ends up half
 * off the screen.
 */
export function keyboardRevealTarget(args: {
  anchorTop: number
  fieldHeight: number
  visibleTop: number
  visibleBottom: number
}): number {
  const roomBottom = args.visibleBottom - Math.max(0, args.fieldHeight)
  const lower = Math.max(args.visibleTop, roomBottom)
  return Math.min(Math.max(args.anchorTop, args.visibleTop), lower)
}

/** Scroll delta that moves the field from fieldTop to the reveal target. */
export function keyboardRevealDelta(args: {
  anchorTop: number
  fieldTop: number
  fieldHeight: number
  visibleTop: number
  visibleBottom: number
}): number {
  const delta = args.fieldTop - keyboardRevealTarget(args)
  return Math.abs(delta) < KEYBOARD_REVEAL_EPSILON_PX ? 0 : delta
}

/**
 * Samsung pins the focused field to the top of the visual viewport, often a
 * few pixels past the edge. A field the user tapped near the top is not this.
 */
export function isFieldShovedToTop(fieldTop: number, anchorTop: number): boolean {
  return fieldTop < 8 && anchorTop - fieldTop > 64
}

/**
 * Bottom of the visible band in visual-viewport coordinates.
 *
 * When the WebView already shrank (`cssInset <= 0`), `innerHeight` and
 * `visualHeight` shrank together and must not subtract the keyboard a second
 * time. An overlay keyboard (`cssInset > 0` and visual height still matching
 * `innerHeight`) subtracts `cssInset`.
 */
export function keyboardVisibleBottom(args: {
  innerHeight: number
  visualHeight: number
  cssInset: number
  marginPx: number
}): number {
  const keyboardCoversVisual = args.cssInset > 0 && args.innerHeight - args.visualHeight < 8
  const bottom = keyboardCoversVisual
    ? args.innerHeight - args.cssInset - args.marginPx
    : args.visualHeight - args.marginPx
  return Math.max(args.marginPx + 1, bottom)
}
