import { describe, expect, it } from 'vitest'
import {
  CHAT_HISTORY_PAGE,
  listOverflows,
  nextChatHistoryWindow,
  scrollerGrewWithContent,
} from '@/components/sidebar/chatHistoryPaging'

describe('chat history paging', () => {
  it('opens on one page and advances a page at a time', () => {
    expect(CHAT_HISTORY_PAGE).toBe(30)
    expect(nextChatHistoryWindow(0, 80)).toBe(30)
    expect(nextChatHistoryWindow(30, 80)).toBe(60)
    expect(nextChatHistoryWindow(60, 80)).toBe(80)
    expect(nextChatHistoryWindow(80, 80)).toBe(80)
  })

  it('does not walk past a short list', () => {
    expect(nextChatHistoryWindow(0, 12)).toBe(12)
    expect(nextChatHistoryWindow(12, 12)).toBe(12)
  })

  it('treats a list taller than the pane as already scrollable', () => {
    expect(listOverflows(400, 409)).toBe(true)
    expect(listOverflows(400, 400)).toBe(false)
    expect(listOverflows(400, 408)).toBe(false)
  })

  it('stops auto-fill when the pane grows with the rows', () => {
    expect(
      scrollerGrewWithContent(
        { clientHeight: 200, scrollHeight: 200 },
        { clientHeight: 640, scrollHeight: 640 }
      )
    ).toBe(true)
    expect(
      scrollerGrewWithContent(
        { clientHeight: 640, scrollHeight: 640 },
        { clientHeight: 640, scrollHeight: 980 }
      )
    ).toBe(false)
  })
})
