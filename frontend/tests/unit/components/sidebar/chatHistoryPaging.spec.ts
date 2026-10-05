import { describe, expect, it } from 'vitest'
import {
  CHAT_HISTORY_PAGE,
  listOverflows,
  scrollerGrewWithContent,
} from '@/components/sidebar/chatHistoryPaging'

describe('chat history paging', () => {
  it('pages the chat menu thirty at a time', () => {
    expect(CHAT_HISTORY_PAGE).toBe(30)
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
