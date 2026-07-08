import { ref, type Ref } from 'vue'

export type DropdownDirection = 'up' | 'down'

/**
 * Decide whether an action-row dropdown should open upward or downward.
 *
 * The chat composer's dropdowns (Model / Tools / Knowledge folder) historically
 * open **upward** (`.dropdown-up`), which is correct when the composer is docked
 * `sticky bottom-0` in an active chat. But on the empty landing the composer is
 * vertically centered (`state-empty` → `min-h-[68vh]` + `justify-center`), so an
 * upward panel is clipped by `page-chat`'s `overflow-hidden` (issue #1285).
 *
 * On each open we measure the space above and below the trigger and pick the
 * side with more room, keeping "up" on a tie so the docked-composer behaviour is
 * unchanged. Consumers bind the returned `direction` to `.dropdown-up` /
 * `.dropdown-down` and call `updateDirection()` right before opening.
 */
export function useDropdownDirection(triggerRef: Ref<HTMLElement | null>): {
  direction: Ref<DropdownDirection>
  updateDirection: () => void
} {
  const direction = ref<DropdownDirection>('up')

  const updateDirection = (): void => {
    const el = triggerRef.value
    if (!el || typeof window === 'undefined') {
      return
    }

    const rect = el.getBoundingClientRect()
    const spaceAbove = rect.top
    const spaceBelow = window.innerHeight - rect.bottom

    // Prefer opening upward (the docked-composer default) unless there is
    // strictly more room below the trigger.
    direction.value = spaceBelow > spaceAbove ? 'down' : 'up'
  }

  return { direction, updateDirection }
}
