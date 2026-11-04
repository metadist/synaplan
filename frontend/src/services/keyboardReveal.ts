/** Ignore sub-pixel noise so a settled field does not scroll again. */
export const KEYBOARD_REVEAL_EPSILON_PX = 2

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
