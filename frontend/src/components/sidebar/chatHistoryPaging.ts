/** How many unpinned chats the desktop sidebar renders before the next scroll. */
export const CHAT_HISTORY_PAGE = 30

export interface ScrollBox {
  clientHeight: number
  scrollHeight: number
}

/** Grow the visible window by one page, and never past the list. */
export function nextChatHistoryWindow(shown: number, total: number): number {
  if (shown >= total) return shown
  const start = Math.max(0, shown)
  return Math.min(total, start + CHAT_HISTORY_PAGE)
}

/** The list is taller than the scroller, so the next page waits for a scroll. */
export function listOverflows(clientHeight: number, scrollHeight: number): boolean {
  return scrollHeight > clientHeight + 8
}

/**
 * The scroller grew to fit the new rows instead of keeping a fixed height.
 * Auto-fill must stop there, or a chat list with no real scrollport renders
 * every row and pushes the profile control off the bottom of the menu.
 */
export function scrollerGrewWithContent(before: ScrollBox, after: ScrollBox): boolean {
  const grew = after.clientHeight > before.clientHeight + 8
  const stillFits = after.scrollHeight <= after.clientHeight + 8
  return grew && stillFits
}
