export const MENU_WIDTH_PX = 208
export const MENU_GAP_PX = 4
export const VIEWPORT_PAD_PX = 8

export type VisibleViewport = {
  offsetTop: number
  height: number
}

export type MenuPlacement = {
  left: number
  maxHeight: number
  openAbove: boolean
  top: string
  bottom: string
}

export function parseCssPx(raw: string): number {
  const parsed = Number.parseFloat(raw.trim())
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 0
}

export function visibleViewportBounds(args: {
  innerHeight: number
  keyboardInsetPx: number
  visualViewport?: VisibleViewport | null
}): { top: number; bottom: number } {
  const inset = Math.max(0, args.keyboardInsetPx)
  let top = 0
  let bottom = args.innerHeight - inset
  const vv = args.visualViewport
  if (vv) {
    top = Math.max(top, vv.offsetTop)
    bottom = Math.min(bottom, vv.offsetTop + vv.height)
  }
  if (bottom < top) {
    bottom = top
  }
  return { top, bottom }
}

/**
 * Prefer opening above the kebab (legacy `bottom-full`). Flip down only when
 * the menu cannot fit above and there is more room below. Space is the visible
 * area: native `Keyboard.resize:'none'` does not shrink innerHeight, so the
 * `--keyboard-inset-height` CSS var must be subtracted; mobile browsers shrink
 * `visualViewport` instead.
 */
export function placeOfficeActionsMenu(args: {
  trigger: { top: number; bottom: number; right: number }
  menuHeight: number
  innerWidth: number
  innerHeight: number
  keyboardInsetPx: number
  visualViewport?: VisibleViewport | null
}): MenuPlacement {
  const visible = visibleViewportBounds(args)
  const spaceAbove = args.trigger.top - visible.top - MENU_GAP_PX
  const spaceBelow = visible.bottom - args.trigger.bottom - MENU_GAP_PX
  const menuHeight = Math.max(0, args.menuHeight)
  const openAbove = !(spaceAbove < menuHeight && spaceBelow > spaceAbove)
  const maxHeight = Math.max(0, openAbove ? spaceAbove : spaceBelow)
  const maxLeft = args.innerWidth - MENU_WIDTH_PX - VIEWPORT_PAD_PX
  const left = Math.max(VIEWPORT_PAD_PX, Math.min(args.trigger.right - MENU_WIDTH_PX, maxLeft))

  if (openAbove) {
    return {
      left,
      maxHeight,
      openAbove: true,
      top: 'auto',
      bottom: `${args.innerHeight - args.trigger.top + MENU_GAP_PX}px`,
    }
  }

  return {
    left,
    maxHeight,
    openAbove: false,
    top: `${args.trigger.bottom + MENU_GAP_PX}px`,
    bottom: 'auto',
  }
}
