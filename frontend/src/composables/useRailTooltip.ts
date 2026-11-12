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
   * caused by this rail unmounting is the route change under the pointer,
   * so the click's suppression stays for the rail that mounts next.
   */
  function hideTipSoon(): void {
    window.clearTimeout(tipTimer)
    if (!closing) suppressedSectionKey = null
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
