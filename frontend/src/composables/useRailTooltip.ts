import { onBeforeUnmount, onUnmounted, ref } from 'vue'
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
 * Chromium then fires pointerenter on the new button that appears under a
 * pointer that never moved. That enter is the click, not a request for the
 * tooltip, so it has to outlive the rail that received the click.
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

export function useRailTooltip() {
  const tip = ref<RailTip | null>(null)
  let tipTimer = 0
  let closing = false

  function placeTip(section: NavSection, anchor: HTMLElement): void {
    const rect = anchor.getBoundingClientRect()
    tip.value = {
      key: section.key,
      label: section.label,
      description: section.description,
      top: rect.top + rect.height / 2,
      left: rect.right + 10,
    }
  }

  function hideTipNow(): void {
    window.clearTimeout(tipTimer)
    tipTimer = 0
    tip.value = null
  }

  /**
   * Moving to the next icon keeps the tooltip and only slides it. A leave
   * that stays inside this rail is the panel opening under the pointer, so
   * the click's suppression stays. A leave caused by this rail unmounting is
   * the route change, and `closing` keeps that suppression for the next rail.
   */
  function hideTipSoon(event?: Event): void {
    window.clearTimeout(tipTimer)
    if (!closing && !pointerStillInRail(event)) suppressedSectionKey = null
    tipTimer = window.setTimeout(() => {
      tip.value = null
    }, RAIL_TIP_LEAVE_MS)
  }

  function scheduleTip(section: NavSection, event: Event, immediate = false): void {
    const anchor = event.currentTarget
    if (!(anchor instanceof HTMLElement)) return
    if (!immediate && suppressedSectionKey === section.key) return
    if (!immediate && suppressedSectionKey !== null) suppressedSectionKey = null
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

  function suppressHover(key: NavSectionKey): void {
    suppressedSectionKey = key
    hideTipNow()
  }

  onBeforeUnmount(() => {
    closing = true
    hideTipNow()
  })

  onUnmounted(() => {
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
