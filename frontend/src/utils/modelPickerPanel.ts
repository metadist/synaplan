/**
 * The model chip sits left of the send button, so a right-aligned panel can
 * run off the left edge on a phone. Grow it to at most 20rem, then shift it
 * right until both edges stay inside the viewport margin.
 */
export function modelPickerPanelStyle(
  trigger: HTMLElement
): { width: string; right: string } | null {
  const rect = trigger.getBoundingClientRect()
  if (rect.width === 0 && rect.right === 0) return null

  const margin = 12
  const preferred = Math.min(320, window.innerWidth - margin * 2)
  const spaceLeft = rect.right - margin
  if (spaceLeft >= preferred) {
    return { width: `${preferred}px`, right: '0px' }
  }

  const maxShift = Math.max(0, window.innerWidth - margin - rect.right)
  const shift = Math.min(Math.max(0, preferred - spaceLeft), maxShift)
  return {
    width: `${Math.max(0, spaceLeft + shift)}px`,
    right: `${-shift}px`,
  }
}
