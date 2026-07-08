/**
 * Tests for `useDropdownDirection` — decides whether the chat composer's
 * action-row dropdowns open upward (`.dropdown-up`, the docked-composer
 * default) or downward (`.dropdown-down`) based on the room around the trigger.
 *
 * Regression cover for issue #1285: in the empty-chat centered composer an
 * upward panel is clipped by `page-chat`'s `overflow-hidden`; the composable
 * flips to "down" when there is strictly more room below the trigger.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'
import { useDropdownDirection } from '@/composables/useDropdownDirection'

let originalInnerHeight: number

const makeTrigger = (top: number, bottom: number): HTMLElement => {
  const el = document.createElement('button')
  el.getBoundingClientRect = () =>
    ({
      top,
      bottom,
      left: 0,
      right: 0,
      width: 0,
      height: bottom - top,
      x: 0,
      y: top,
      toJSON: () => ({}),
    }) as DOMRect
  return el
}

describe('useDropdownDirection', () => {
  beforeEach(() => {
    originalInnerHeight = window.innerHeight
    Object.defineProperty(window, 'innerHeight', {
      value: 800,
      configurable: true,
      writable: true,
    })
  })

  afterEach(() => {
    Object.defineProperty(window, 'innerHeight', {
      value: originalInnerHeight,
      configurable: true,
      writable: true,
    })
  })

  it('defaults to "up" before any measurement', () => {
    const triggerRef = ref<HTMLElement | null>(null)
    const { direction } = useDropdownDirection(triggerRef)
    expect(direction.value).toBe('up')
  })

  it('stays "up" for a docked-composer trigger near the bottom of the viewport', () => {
    // Bottom-docked composer: little room below, lots above.
    const triggerRef = ref<HTMLElement | null>(makeTrigger(760, 780))
    const { direction, updateDirection } = useDropdownDirection(triggerRef)
    updateDirection()
    expect(direction.value).toBe('up')
  })

  it('flips to "down" for a trigger with more room below (empty centered composer)', () => {
    // Trigger sits high in the viewport (space below > space above).
    const triggerRef = ref<HTMLElement | null>(makeTrigger(120, 150))
    const { direction, updateDirection } = useDropdownDirection(triggerRef)
    updateDirection()
    expect(direction.value).toBe('down')
  })

  it('keeps "up" on a tie so docked behaviour is unchanged', () => {
    // spaceAbove (400) === spaceBelow (400).
    const triggerRef = ref<HTMLElement | null>(makeTrigger(400, 400))
    const { direction, updateDirection } = useDropdownDirection(triggerRef)
    updateDirection()
    expect(direction.value).toBe('up')
  })

  it('is a no-op when the trigger element is missing', () => {
    const triggerRef = ref<HTMLElement | null>(null)
    const { direction, updateDirection } = useDropdownDirection(triggerRef)
    updateDirection()
    expect(direction.value).toBe('up')
  })
})
