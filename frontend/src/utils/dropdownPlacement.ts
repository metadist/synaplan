/**
 * Every `.dropdown-panel` and `.dropdown-up` opens toward the side that can
 * show it. Down is the default when both sides fit; composer menus prefer up.
 * When neither side can hold the menu, it uses the larger side and scrolls
 * inside that space instead of growing the page.
 */

const GAP_PX = 8
const MAX_VIEWPORT_FRACTION = 0.6

export type DropdownPlacement = {
  openAbove: boolean
  maxHeight: number
}

export function chooseDropdownPlacement(input: {
  anchorTop: number
  anchorBottom: number
  frameTop: number
  frameBottom: number
  contentHeight: number
  preferAbove?: boolean
}): DropdownPlacement {
  const spaceAbove = Math.max(0, input.anchorTop - input.frameTop - GAP_PX)
  const spaceBelow = Math.max(0, input.frameBottom - input.anchorBottom - GAP_PX)
  const frameHeight = Math.max(0, input.frameBottom - input.frameTop)
  const cap = frameHeight * MAX_VIEWPORT_FRACTION
  const wanted = Math.min(Math.max(0, input.contentHeight), cap || input.contentHeight)
  const fitsAbove = spaceAbove >= wanted && wanted > 0
  const fitsBelow = spaceBelow >= wanted && wanted > 0
  const preferAbove = input.preferAbove === true

  let openAbove = preferAbove
  if (fitsBelow && !fitsAbove) openAbove = false
  else if (fitsAbove && !fitsBelow) openAbove = true
  else if (!fitsAbove && !fitsBelow) openAbove = spaceAbove > spaceBelow

  const room = openAbove ? spaceAbove : spaceBelow
  const maxHeight = Math.min(wanted || room, room, cap || room)
  return { openAbove, maxHeight: Math.max(0, maxHeight) }
}

const MENU_SELECTOR = '.dropdown-panel, .dropdown-up'

function isMenu(node: Element): boolean {
  return node.matches(MENU_SELECTOR)
}

function anchorOf(panel: HTMLElement): HTMLElement {
  const parent = panel.parentElement
  if (!parent) return panel
  for (const child of parent.children) {
    if (child === panel || !(child instanceof HTMLElement)) continue
    if (child instanceof HTMLButtonElement || child instanceof HTMLInputElement) return child
  }
  return parent
}

function visibleFrame(panel: HTMLElement): { top: number; bottom: number } {
  const viewport = window.visualViewport
  let top = viewport ? viewport.offsetTop : 0
  let bottom = top + (viewport ? viewport.height : window.innerHeight)
  let node = panel.parentElement
  while (node && node !== document.documentElement) {
    // A menu nested in another menu (the tools list inside the plus menu)
    // must size against the page, not against the parent menu's box.
    if (!isMenu(node)) {
      const overflow = getComputedStyle(node).overflowY
      if (overflow === 'auto' || overflow === 'scroll') {
        const rect = node.getBoundingClientRect()
        top = Math.max(top, rect.top)
        bottom = Math.min(bottom, rect.bottom)
        break
      }
    }
    node = node.parentElement
  }
  return { top, bottom }
}

/**
 * The full menu height, not the height already capped by a previous placement.
 * Restores the inner scroll position so a remeasure does not jump back to the top.
 */
function contentHeight(panel: HTMLElement): number {
  const cached = panel.dataset.dropdownContentHeight
  if (cached) return Number(cached)

  const previousMax = panel.style.maxHeight
  const children = [...panel.children].filter(
    (child): child is HTMLElement => child instanceof HTMLElement
  )
  const flex = children.map((child) => child.style.flex)
  const scrollTops = children.map((child) => child.scrollTop)
  panel.style.maxHeight = 'none'
  for (const child of children) child.style.flex = ''
  const height = panel.scrollHeight
  panel.style.maxHeight = previousMax
  children.forEach((child, index) => {
    child.style.flex = flex[index] ?? ''
    child.scrollTop = scrollTops[index] ?? 0
  })
  panel.dataset.dropdownContentHeight = String(height)
  return height
}

function forgetContentHeight(panel: HTMLElement): void {
  delete panel.dataset.dropdownContentHeight
}

/**
 * The menu itself scrolls. A nested list with min-height 0 collapses to a few
 * pixels once the menu is height-capped, so that list is allowed to grow and
 * the menu becomes the scroll container.
 */
function enableMenuScroll(panel: HTMLElement): void {
  panel.style.overflowY = 'auto'
  panel.style.overscrollBehavior = 'contain'
  for (const child of panel.children) {
    if (!(child instanceof HTMLElement)) continue
    const overflow = getComputedStyle(child).overflowY
    if (overflow !== 'auto' && overflow !== 'scroll') continue
    child.style.maxHeight = 'none'
    child.style.minHeight = 'auto'
    child.style.overflowY = 'visible'
  }
}

/** A wheel or scrollbar inside the menu must not be treated as the page moving. */
export function scrollStartedInsideMenu(target: EventTarget | null): boolean {
  return target instanceof Element && target.closest(MENU_SELECTOR) !== null
}

/** Place one open menu. Fixed menus and tooltips already position themselves. */
export function applyDropdownPlacement(panel: HTMLElement): void {
  if (panel.dataset.dropdownPlacement === 'manual') return
  const computed = getComputedStyle(panel)
  if (computed.position === 'fixed' || computed.pointerEvents === 'none') return

  const anchor = anchorOf(panel)
  const anchorRect = anchor.getBoundingClientRect()
  if (anchorRect.width === 0 && anchorRect.height === 0) return

  const parent = panel.parentElement
  if (parent && getComputedStyle(parent).position === 'static') {
    parent.style.position = 'relative'
  }

  const frame = visibleFrame(panel)
  const natural = contentHeight(panel)
  const placement = chooseDropdownPlacement({
    anchorTop: anchorRect.top,
    anchorBottom: anchorRect.bottom,
    frameTop: frame.top,
    frameBottom: frame.bottom,
    contentHeight: natural,
    preferAbove: panel.classList.contains('dropdown-up'),
  })

  const parentRect = (parent ?? anchor).getBoundingClientRect()
  panel.style.position = 'absolute'
  panel.style.marginTop = '0'
  panel.style.marginBottom = '0'
  // A menu that fits its items must not grow a scrollbar. Capping max-height
  // to the content height still overflows by the border and shows a bar.
  if (natural > placement.maxHeight + 1) {
    panel.style.maxHeight = `${placement.maxHeight}px`
    enableMenuScroll(panel)
  } else {
    panel.style.maxHeight = 'none'
    panel.style.overflowY = 'visible'
    panel.style.overscrollBehavior = ''
  }
  if (placement.openAbove) {
    const bottom = parentRect.bottom - anchorRect.top + GAP_PX
    panel.style.top = 'auto'
    panel.style.bottom = `${bottom}px`
  } else {
    const top = anchorRect.bottom - parentRect.top + GAP_PX
    panel.style.bottom = 'auto'
    panel.style.top = `${top}px`
  }
  panel.dataset.dropdownPlacement = placement.openAbove ? 'above' : 'below'
}

function menusIn(root: ParentNode): HTMLElement[] {
  return [...root.querySelectorAll<HTMLElement>(MENU_SELECTOR)]
}

function menuWasTouched(records: MutationRecord[]): boolean {
  for (const record of records) {
    if (record.type !== 'childList') continue
    if (record.target instanceof Element) {
      const panel = record.target.closest(MENU_SELECTOR)
      if (panel instanceof HTMLElement) forgetContentHeight(panel)
      if (panel) return true
    }
    for (const node of [...record.addedNodes, ...record.removedNodes]) {
      if (!(node instanceof Element)) continue
      if (isMenu(node) || node.querySelector(MENU_SELECTOR)) return true
    }
  }
  return false
}

/** Watch one document or shadow root for menus that have just opened. */
export function installDropdownPlacement(root: ParentNode & Node = document): () => void {
  let scheduled = false
  const placeOpenMenus = () => {
    scheduled = false
    for (const panel of menusIn(root)) applyDropdownPlacement(panel)
  }
  const schedule = () => {
    if (scheduled) return
    scheduled = true
    requestAnimationFrame(placeOpenMenus)
  }
  const onScroll = (event: Event) => {
    if (scrollStartedInsideMenu(event.target)) return
    if (menusIn(root).length === 0) return
    schedule()
  }
  const observer = new MutationObserver((records) => {
    // Place before the next paint so a menu does not flash open downward.
    if (menuWasTouched(records)) placeOpenMenus()
  })
  const scrollTarget: EventTarget = root instanceof Document ? document : root
  observer.observe(root, { childList: true, subtree: true })
  window.addEventListener('resize', schedule)
  window.visualViewport?.addEventListener('resize', schedule)
  window.visualViewport?.addEventListener('scroll', schedule)
  scrollTarget.addEventListener('scroll', onScroll, true)

  return () => {
    observer.disconnect()
    window.removeEventListener('resize', schedule)
    window.visualViewport?.removeEventListener('resize', schedule)
    window.visualViewport?.removeEventListener('scroll', schedule)
    scrollTarget.removeEventListener('scroll', onScroll, true)
  }
}
