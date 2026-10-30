import { nextTick, onBeforeUnmount, onMounted, ref, type Ref, watch } from 'vue'

const GAP = 8
const PAD = 8
/** The flyout overlaps the tools box so a straight move right enters it. */
const OVERLAP = 12
const MAX_WIDTH = 320
const CLOSE_DELAY_MS = 180

export type BesideFrame = {
  boxTop: number
  boxRight: number
  boxBottom: number
  boxLeft: number
  /** The row that opened the menu. The leading item lines up with this row. */
  anchorTop: number
  anchorBottom: number
  /** Panel padding above the first item and below the last item. */
  leadingInset: number
  trailingInset: number
  contentHeight: number
  viewLeft: number
  viewTop: number
  viewWidth: number
  viewHeight: number
}

export type BesidePlacement = {
  left: number
  top: number
  width: number
  minHeight: number
  maxHeight: number | null
  opensLeft: boolean
}

const ownerId: { current: string | null } = { current: null }
const ownerListeners = new Set<(id: string | null) => void>()

function setOwner(id: string | null): void {
  ownerId.current = id
  ownerListeners.forEach((listener) => listener(id))
}

function clamp(value: number, min: number, max: number): number {
  if (max < min) return min
  return Math.min(max, Math.max(min, value))
}

/**
 * Side of the tools box, overlapping it so a straight move right enters the
 * menu. The bottom item sits beside the row that opened it and the list grows
 * upward. A list that would leave the screen shifts only while that row stays
 * inside the menu.
 */
export function chooseBesidePlacement(frame: BesideFrame): BesidePlacement {
  const viewRight = frame.viewLeft + frame.viewWidth
  const viewBottom = frame.viewTop + frame.viewHeight
  const width = Math.min(MAX_WIDTH, Math.max(0, frame.viewWidth - PAD * 2))
  const spaceRight = viewRight - frame.boxRight - GAP
  const spaceLeft = frame.boxLeft - frame.viewLeft - GAP
  const opensLeft = spaceLeft > spaceRight
  const rawLeft = opensLeft ? frame.boxLeft + OVERLAP - width : frame.boxRight - OVERLAP
  const left = clamp(rawLeft, frame.viewLeft + PAD, viewRight - PAD - width)
  const available = Math.max(0, frame.viewHeight - PAD * 2)
  const fits = frame.contentHeight <= available
  const rendered = fits ? frame.contentHeight : available
  const floor = frame.viewTop + PAD
  const ceiling = Math.max(floor, viewBottom - PAD - rendered)
  let top = frame.anchorBottom + frame.trailingInset - rendered
  if (top < floor) top = floor
  if (top > ceiling) top = ceiling
  const rowMid = (frame.anchorTop + frame.anchorBottom) / 2
  if (rowMid < top || rowMid > top + rendered) {
    top = frame.anchorBottom + frame.trailingInset - rendered
  }
  return {
    left,
    top,
    width,
    minHeight: 0,
    maxHeight: fits ? null : available,
    opensLeft,
  }
}

function naturalHeight(panel: HTMLElement): number {
  const style = getComputedStyle(panel)
  let total =
    (parseFloat(style.paddingTop) || 0) +
    (parseFloat(style.paddingBottom) || 0) +
    (parseFloat(style.borderTopWidth) || 0) +
    (parseFloat(style.borderBottomWidth) || 0)
  for (const child of panel.children) {
    if (!(child instanceof HTMLElement)) continue
    const childStyle = getComputedStyle(child)
    total += child.offsetHeight
    total += (parseFloat(childStyle.marginTop) || 0) + (parseFloat(childStyle.marginBottom) || 0)
  }
  return Math.ceil(total)
}

function edgeInsets(panel: HTMLElement): { leading: number; trailing: number } {
  const items = [...panel.querySelectorAll('button')].filter(
    (node): node is HTMLButtonElement => node instanceof HTMLButtonElement
  )
  if (items.length === 0) return { leading: 0, trailing: 0 }
  const panelRect = panel.getBoundingClientRect()
  const first = items[0].getBoundingClientRect()
  const last = items[items.length - 1].getBoundingClientRect()
  return {
    leading: Math.max(0, first.top - panelRect.top),
    trailing: Math.max(0, panelRect.bottom - last.bottom),
  }
}

export function applyBesidePlacement(panel: HTMLElement, box: HTMLElement): BesidePlacement {
  const viewport = window.visualViewport
  const boxRect = box.getBoundingClientRect()
  const anchor = panel.parentElement?.querySelector('[data-beside-row]')
  const anchorRect = anchor instanceof HTMLElement ? anchor.getBoundingClientRect() : boxRect
  const viewWidth = viewport?.width ?? window.innerWidth
  panel.style.position = 'absolute'
  panel.style.width = `${Math.min(MAX_WIDTH, Math.max(0, viewWidth - PAD * 2))}px`
  panel.style.minHeight = '0px'
  panel.style.maxHeight = 'none'
  const insets = edgeInsets(panel)
  const placement = chooseBesidePlacement({
    boxTop: boxRect.top,
    boxRight: boxRect.right,
    boxBottom: boxRect.bottom,
    boxLeft: boxRect.left,
    anchorTop: anchorRect.top,
    anchorBottom: anchorRect.bottom,
    leadingInset: insets.leading,
    trailingInset: insets.trailing,
    contentHeight: naturalHeight(panel),
    viewLeft: viewport?.offsetLeft ?? 0,
    viewTop: viewport?.offsetTop ?? 0,
    viewWidth: viewport?.width ?? window.innerWidth,
    viewHeight: viewport?.height ?? window.innerHeight,
  })
  const origin = panel.offsetParent instanceof HTMLElement ? panel.offsetParent : box
  const originRect = origin.getBoundingClientRect()
  panel.style.position = 'absolute'
  panel.style.left = `${placement.left - originRect.left}px`
  panel.style.top = `${placement.top - originRect.top}px`
  panel.style.right = 'auto'
  panel.style.bottom = 'auto'
  panel.style.width = `${placement.width}px`
  panel.style.minHeight = `${placement.minHeight}px`
  panel.style.maxHeight = placement.maxHeight === null ? 'none' : `${placement.maxHeight}px`
  panel.style.overflow = placement.maxHeight === null ? 'visible' : 'auto'
  panel.style.zIndex = '120'
  panel.dataset.opensLeft = placement.opensLeft ? 'true' : 'false'
  return placement
}

function hoverFine(): boolean {
  return window.matchMedia('(hover: hover) and (pointer: fine)').matches
}

function bindViewport(onViewport: () => void): () => void {
  window.addEventListener('resize', onViewport)
  window.addEventListener('scroll', onViewport, true)
  window.visualViewport?.addEventListener('resize', onViewport)
  window.visualViewport?.addEventListener('scroll', onViewport)
  return () => {
    window.removeEventListener('resize', onViewport)
    window.removeEventListener('scroll', onViewport, true)
    window.visualViewport?.removeEventListener('resize', onViewport)
    window.visualViewport?.removeEventListener('scroll', onViewport)
  }
}

function toolsBox(root: Ref<HTMLElement | null>): HTMLElement | null {
  return root.value?.closest('[data-testid="dropdown-plus-panel"]') ?? null
}

function readOpensLeft(root: Ref<HTMLElement | null>): boolean {
  const host = toolsBox(root)
  if (!host) return false
  const rect = host.getBoundingClientRect()
  const viewport = window.visualViewport
  const viewLeft = viewport?.offsetLeft ?? 0
  const viewWidth = viewport?.width ?? window.innerWidth
  return rect.left - viewLeft - GAP > viewLeft + viewWidth - rect.right - GAP
}

function placePanel(
  root: Ref<HTMLElement | null>,
  panel: Ref<HTMLElement | null>,
  opensLeft: Ref<boolean>
): void {
  const el = panel.value
  const host = toolsBox(root)
  if (!el || !host) return
  opensLeft.value = applyBesidePlacement(el, host).opensLeft
}

function createHoverGate(
  id: string,
  isOpen: Ref<boolean>,
  root: Ref<HTMLElement | null>,
  panel: Ref<HTMLElement | null>
) {
  let closeTimer: number | null = null
  // A click inside the list keeps it open. Repositioning after that click would
  // otherwise look like the pointer left, and the list would unmount mid-click.
  let stick = false

  function open(): void {
    setOwner(id)
    isOpen.value = true
  }

  function close(): void {
    stick = false
    isOpen.value = false
    if (ownerId.current === id) setOwner(null)
  }

  function cancelClose(): void {
    if (closeTimer === null) return
    window.clearTimeout(closeTimer)
    closeTimer = null
  }

  function holdOpen(): void {
    stick = true
    cancelClose()
  }

  function scheduleClose(): void {
    if (stick || !hoverFine()) return
    cancelClose()
    closeTimer = window.setTimeout(() => {
      closeTimer = null
      const row = root.value?.querySelector('[data-beside-row]')
      if (panel.value?.matches(':hover') || row?.matches(':hover')) return
      close()
    }, CLOSE_DELAY_MS)
  }

  function onRowClick(): void {
    if (hoverFine()) {
      if (!isOpen.value) open()
      return
    }
    if (isOpen.value) close()
    else open()
  }

  return {
    open,
    close,
    cancelClose,
    onRowEnter: () => {
      if (!hoverFine()) return
      cancelClose()
      open()
    },
    onRowLeave: scheduleClose,
    onRowClick,
    holdOpen,
  }
}

export function useBesideMenu(
  id: string,
  isOpen: Ref<boolean>,
  root: Ref<HTMLElement | null>,
  panel: Ref<HTMLElement | null>
) {
  const opensLeft = ref(false)
  const hover = createHoverGate(id, isOpen, root, panel)
  let observer: MutationObserver | null = null
  let unbindViewport: (() => void) | null = null

  function onOwner(current: string | null): void {
    if (current !== id && isOpen.value) isOpen.value = false
  }

  function onViewport(): void {
    opensLeft.value = readOpensLeft(root)
    if (isOpen.value) placePanel(root, panel, opensLeft)
  }

  watch(isOpen, async (openNow) => {
    observer?.disconnect()
    observer = null
    if (!openNow) return
    await nextTick()
    placePanel(root, panel, opensLeft)
    const el = panel.value
    if (!el || typeof MutationObserver === 'undefined') return
    observer = new MutationObserver(() => placePanel(root, panel, opensLeft))
    observer.observe(el, { childList: true, subtree: true })
  })

  onMounted(() => {
    ownerListeners.add(onOwner)
    opensLeft.value = readOpensLeft(root)
    unbindViewport = bindViewport(onViewport)
  })

  onBeforeUnmount(() => {
    hover.cancelClose()
    observer?.disconnect()
    ownerListeners.delete(onOwner)
    if (ownerId.current === id) setOwner(null)
    unbindViewport?.()
  })

  return {
    opensLeft,
    onRowEnter: hover.onRowEnter,
    onRowLeave: hover.onRowLeave,
    onPanelEnter: hover.cancelClose,
    onPanelPointerDown: hover.holdOpen,
    onPanelLeave: hover.onRowLeave,
    onRowClick: hover.onRowClick,
    openFromKeyboard: hover.open,
    close: hover.close,
  }
}
