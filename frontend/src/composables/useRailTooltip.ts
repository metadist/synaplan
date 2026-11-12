import { computed, onBeforeUnmount, onMounted, onUnmounted, ref, type Ref } from 'vue'
import type { NavSection, NavSectionKey } from './useNavSections'

export const RAIL_TOOLTIP_ID = 'tooltip-sidebar-rail'
export const RAIL_TIP_DELAY_MS = 180
const RAIL_TIP_LEAVE_MS = 80
const CLICK_DRIFT_PX = 2
const VIEWPORT_PADDING_PX = 8

export interface RailTip {
  key: NavSectionKey
  label: string
  description: string
  top: number
  left: number
}

/**
 * Click suppression shared across rail instances. Each view mounts its own
 * rail, and opening or closing the panel also shifts icons vertically; browsers
 * fire synthetic pointer events when controls mount or move under a pointer
 * that never moved.
 */
let suppressedSectionKey: NavSectionKey | null = null
let suppressedPoint: { x: number; y: number } | null = null
let watchingPointerMove = false

function isTouchPointer(event: Event | undefined): boolean {
  return (
    typeof event === 'object' &&
    event !== null &&
    'pointerType' in event &&
    (event as PointerEvent).pointerType === 'touch'
  )
}

function readPointerPoint(event: Event | undefined): { x: number; y: number } | null {
  if (!event || typeof event !== 'object') return null
  const candidate = event as Partial<MouseEvent>
  if (typeof candidate.clientX !== 'number' || typeof candidate.clientY !== 'number') {
    return null
  }
  if ((candidate.detail ?? 0) === 0 && candidate.clientX === 0 && candidate.clientY === 0) {
    return null
  }
  return { x: candidate.clientX, y: candidate.clientY }
}

function isStationarySinceSuppress(event: Event | undefined): boolean {
  if (suppressedPoint === null) return false
  const point = readPointerPoint(event)
  if (point === null) return false
  return (
    Math.abs(point.x - suppressedPoint.x) <= CLICK_DRIFT_PX &&
    Math.abs(point.y - suppressedPoint.y) <= CLICK_DRIFT_PX
  )
}

function stopPointerMoveWatch(): void {
  if (!watchingPointerMove || typeof window === 'undefined') return
  window.removeEventListener('pointermove', onWindowPointerMove)
  watchingPointerMove = false
}

function clearSuppression(): void {
  suppressedSectionKey = null
  suppressedPoint = null
  stopPointerMoveWatch()
}

function onWindowPointerMove(event: PointerEvent): void {
  if (isTouchPointer(event) || isStationarySinceSuppress(event)) return
  suppressedPoint = null
  const overSuppressedButton =
    suppressedSectionKey !== null &&
    event.target instanceof Element &&
    event.target.closest(`[data-tour="rail-${suppressedSectionKey}"]`) !== null
  if (!overSuppressedButton) {
    clearSuppression()
  }
}

function startPointerMoveWatch(): void {
  if (watchingPointerMove || typeof window === 'undefined') return
  watchingPointerMove = true
  window.addEventListener('pointermove', onWindowPointerMove, { passive: true })
}

/** Drops the click memory. Unit tests call this between cases. */
export function resetRailTooltipState(): void {
  clearSuppression()
}

function resolveLiveTip(
  current: RailTip | null,
  sections?: Readonly<Ref<readonly NavSection[]>>
): RailTip | null {
  if (!current) return null
  const live = sections?.value.find((item) => item.key === current.key)
  if (!live) return current
  return { ...current, label: live.label, description: live.description }
}

function computeAnchorTip(section: NavSection, anchor: HTMLElement): RailTip {
  const rect = anchor.getBoundingClientRect()
  const rawTop = rect.top + rect.height / 2
  const maxTop =
    typeof window !== 'undefined' && window.innerHeight > VIEWPORT_PADDING_PX * 2
      ? window.innerHeight - VIEWPORT_PADDING_PX
      : rawTop
  return {
    key: section.key,
    label: section.label,
    description: section.description,
    top: Math.min(Math.max(rawTop, VIEWPORT_PADDING_PX), maxTop),
    left: rect.right + 10,
  }
}

function shouldIgnoreHover(section: NavSection, event: Event): boolean {
  if (isTouchPointer(event) || isStationarySinceSuppress(event)) return true
  if (suppressedSectionKey === section.key) {
    suppressedPoint = null
    return true
  }
  clearSuppression()
  return !window.matchMedia('(hover: hover)').matches
}

export function useRailTooltip(sections?: Readonly<Ref<readonly NavSection[]>>) {
  const anchorTip = ref<RailTip | null>(null)
  let tipTimer = 0
  let closing = false

  const tip = computed<RailTip | null>(() => resolveLiveTip(anchorTip.value, sections))

  function hideTipNow(): void {
    window.clearTimeout(tipTimer)
    tipTimer = 0
    anchorTip.value = null
  }

  /**
   * Moving to the next icon keeps the tooltip and only slides it. A leave
   * fired at the click point (panel shift) or during unmount (route change)
   * keeps the click suppression for the frame that mounts next.
   */
  function hideTipSoon(event?: Event): void {
    window.clearTimeout(tipTimer)
    if (!closing && !isStationarySinceSuppress(event)) clearSuppression()
    tipTimer = window.setTimeout(() => {
      anchorTip.value = null
    }, RAIL_TIP_LEAVE_MS)
  }

  function scheduleTip(section: NavSection, event: Event, immediate = false): void {
    const anchor = event.currentTarget
    if (!(anchor instanceof HTMLElement)) return
    if (!immediate && shouldIgnoreHover(section, event)) return
    window.clearTimeout(tipTimer)
    if (immediate || anchorTip.value) {
      anchorTip.value = computeAnchorTip(section, anchor)
      return
    }
    tipTimer = window.setTimeout(() => {
      anchorTip.value = computeAnchorTip(section, anchor)
    }, RAIL_TIP_DELAY_MS)
  }

  function showTipOnKeyboardFocus(section: NavSection, event: FocusEvent): void {
    const anchor = event.currentTarget
    if (!(anchor instanceof HTMLElement) || !anchor.matches(':focus-visible')) return
    scheduleTip(section, event, true)
  }

  function suppressHover(key: NavSectionKey | null = null, event?: Event): void {
    suppressedSectionKey = key
    suppressedPoint = readPointerPoint(event)
    if (suppressedSectionKey !== null || suppressedPoint !== null) startPointerMoveWatch()
    else stopPointerMoveWatch()
    hideTipNow()
  }

  function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || anchorTip.value === null) return
    event.preventDefault()
    hideTipNow()
  }

  onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    window.addEventListener('resize', hideTipNow)
  })

  onBeforeUnmount(() => {
    closing = true
    hideTipNow()
  })

  onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    window.removeEventListener('resize', hideTipNow)
    hideTipNow()
  })

  return { tip, scheduleTip, showTipOnKeyboardFocus, hideTipNow, hideTipSoon, suppressHover }
}
