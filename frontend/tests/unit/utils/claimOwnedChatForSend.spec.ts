import { describe, expect, it, vi } from 'vitest'
import { claimOwnedChatForSend } from '@/utils/claimOwnedChatForSend'

describe('claimOwnedChatForSend', () => {
  it('keeps the chat that is already open', async () => {
    const openOwnedChat = vi.fn()
    const suppressHistoryLoad = vi.fn()

    const chatId = await claimOwnedChatForSend({
      activeChatId: 4,
      suppressHistoryLoad,
      releaseHistoryLoad: vi.fn(),
      openOwnedChat,
      readActiveChatId: () => 4,
    })

    expect(chatId).toBe(4)
    expect(openOwnedChat).not.toHaveBeenCalled()
    expect(suppressHistoryLoad).not.toHaveBeenCalled()
  })

  it('opens an owned chat after the selection was cleared, before history can reload', async () => {
    const order: string[] = []
    let active: number | null = null

    const chatId = await claimOwnedChatForSend({
      activeChatId: null,
      suppressHistoryLoad: () => order.push('suppress'),
      releaseHistoryLoad: () => order.push('release'),
      openOwnedChat: async () => {
        order.push('open')
        active = 21
      },
      readActiveChatId: () => active,
    })

    expect(chatId).toBe(21)
    expect(order).toEqual(['suppress', 'open'])
  })

  it('does not leave history suppressed when no chat could be opened', async () => {
    const releaseHistoryLoad = vi.fn()

    const chatId = await claimOwnedChatForSend({
      activeChatId: null,
      suppressHistoryLoad: vi.fn(),
      releaseHistoryLoad,
      openOwnedChat: async () => {},
      readActiveChatId: () => null,
    })

    expect(chatId).toBeNull()
    expect(releaseHistoryLoad).toHaveBeenCalledOnce()
  })
})
