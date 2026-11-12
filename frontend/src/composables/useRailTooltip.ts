import { nextTick, onBeforeUnmount, onUnmounted, ref } from 'vue'
import type { NavSection, NavSectionKey } from './useNavSections'

export const RAIL_TOOLTIP_ID = 'tooltip-sidebar-rail'
export const RAIL_TIP_DELAY_MS = 180
const RAIL_TIP_LEAVE_MS = 80

export interface RailTip {
  key: NavSectionKey
  label: string
  description: string
  top: number
  left: number
}

/**
 * The section whose icon was just clicked. Each view mounts its own rail, and
 * opening or closing the panel removes or restores the expand control so the
 * icons shift beneath a stationary pointer; both fire pointerleave/pointerenter
 * without the pointer moving. Those events belong to the click, not to hover,
 * so suppression outlives the shift and the route remount.
 */
let suppressedSectionKey: NavSectionKey | null = null

/** Drops the click memory. Unit tests call this between cases. */
export function resetRailTooltipState(): void {
  suppressedSectionKey = null
}

/** Opening the panel shifts the icons under a pointer that never left the rail. */
function pointerStillInRail(event: Event | undefined): boolean {
  if (!(event instanceof PointerEvent)) return false
  const next = event.relatedTarget
  const current = event.currentTarget
  if (!(next instanceof Node) || !(current instanceof Element)) return false
  const rail = current.closest('[data-testid="section-sidebar-rail"]')
  return rail?.contains(next) === true
}

function measureTip(section: NavSection, anchor: HTMLElement): RailTip {
  const rect = anchor.getBoundingClientRect()
  return {
    key: section.key,
    label: section.label,
    description: section.description,
    top: rect.top + rect.height / 2,
    left: rect.right + 10,
  }
}

class ShiftGuard {
  active = false
  private frame = 0

  arm(isClosing: () => boolean): void {
    this.clear()
    this.active = true
    void nextTick(() => {
      if (isClosing()) return
      this.frame = window.requestAnimationFrame(() => {
        this.frame = window.requestAnimationFrame(() => {
          this.frame = 0
          this.active = false
        })
      })
    })
  }

  clear(): void {
    if (this.frame !== 0) {
      window.cancelAnimationFrame(this.frame)
      this.frame = 0
    }
    this.active = false
  }
}

export function useRailTooltip() {
  const tip = ref<RailTip | null>(null)
  const shift = new ShiftGuard()
  let tipTimer = 0
  let closing = false

  function placeTip(section: NavSection, anchor: HTMLElement): void {
    tip.value = measureTip(section, anchor)
  }

  function hideTipNow(): void {
    window.clearTimeout(tipTimer)
    tipTimer = 0
    tip.value = null
  }

  /**
   * Moving to the next icon keeps the tooltip and only slides it. A leave
   * that stays inside this rail or fires while the panel toggles is the rail
   * shifting under the pointer, so the click's suppression stays. A leave
   * caused by this rail unmounting is the route change, and `closing` keeps
   * that suppression for the next rail.
   */
  function hideTipSoon(event?: Event): void {
    window.clearTimeout(tipTimer)
    if (!closing && !shift.active && !pointerStillInRail(event)) suppressedSectionKey = null
    tipTimer = window.setTimeout(() => {
      tip.value = null
    }, RAIL_TIP_LEAVE_MS)
  }

  function scheduleTip(section: NavSection, event: Event, immediate = false): void {
    const anchor = event.currentTarget
    if (!(anchor instanceof HTMLElement)) return
    if (!immediate) {
      if (shift.active) {
        suppressedSectionKey = section.key
        return
      }
      if (suppressedSectionKey === section.key) return
      if (suppressedSectionKey !== null) suppressedSectionKey = null
    }
    if (!immediate && !window.matchMedia('(hover: hover)').matches) return
    window.clearTimeout(tipTimer)
    if (immediate || tip.value) {
      placeTip(section, anchor)
      return
    }
    tipTimer = window.setTimeout(() => placeTip(section, anchor), RAIL_TIP_DELAY_MS)
  }

  function showTipOnKeyboardFocus(section: NavSection, event: FocusEvent): void {
    const anchor = event.currentTarget
    if (!(anchor instanceof HTMLElement) || !anchor.matches(':focus-visible')) return
    scheduleTip(section, event, true)
  }

  function suppressHover(key: NavSectionKey, layoutShifted = false): void {
    suppressedSectionKey = key
    if (layoutShifted) shift.arm(() => closing)
    else shift.clear()
    hideTipNow()
  }

  onBeforeUnmount(() => {
    closing = true
    shift.clear()
    hideTipNow()
  })

  onUnmounted(() => {
    shift.clear()
    hideTipNow()
  })

  return {
    tip,
    scheduleTip,
    showTipOnKeyboardFocus,
    hideTipNow,
    hideTipSoon,
    suppressHover,
  }
}
