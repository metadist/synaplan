import { describe, expect, it, vi } from 'vitest'
import { endBusy, recoverWidgetSend, type WidgetBubble } from '@/components/widgets/widgetBusy'

const bubble = (overrides: Partial<WidgetBubble> = {}): WidgetBubble => ({
  id: 'a1',
  role: 'assistant',
  content: '',
  ...overrides,
})

describe('widget busy endings', () => {
  it('restores the text when the box is empty and no files were sent', () => {
    const messages: WidgetBubble[] = [{ id: 'u1', role: 'user', content: 'Hello' }, bubble()]
    const setInput = vi.fn()
    const addBotMessage = vi.fn()

    endBusy(messages, 'a1', 'Hello', '', setInput, { restored: 'R', kept: 'K' }, addBotMessage)

    expect(messages).toEqual([])
    expect(setInput).toHaveBeenCalledWith('Hello')
    expect(addBotMessage).toHaveBeenCalledWith('R')
  })

  it('keeps the bubble when a newer draft is in the box', () => {
    const messages: WidgetBubble[] = [{ id: 'u1', role: 'user', content: 'Hello' }, bubble()]
    const setInput = vi.fn()
    const addBotMessage = vi.fn()

    endBusy(
      messages,
      'a1',
      'Hello',
      'next question',
      setInput,
      { restored: 'R', kept: 'K' },
      addBotMessage
    )

    expect(messages.map((m) => m.id)).toEqual(['u1'])
    expect(setInput).not.toHaveBeenCalled()
    expect(addBotMessage).toHaveBeenCalledWith('K')
  })

  it('keeps a send with files visible, with its files', () => {
    const messages: WidgetBubble[] = [
      { id: 'u1', role: 'user', content: 'Summarise', files: [{ id: 5 }] },
      bubble(),
    ]
    const setInput = vi.fn()
    const addBotMessage = vi.fn()

    endBusy(messages, 'a1', 'Summarise', '', setInput, { restored: 'R', kept: 'K' }, addBotMessage)

    expect(messages.map((m) => m.id)).toEqual(['u1'])
    expect(setInput).not.toHaveBeenCalled()
    expect(addBotMessage).toHaveBeenCalledWith('K')
  })

  it('reads the refreshed transcript after history reloads', async () => {
    let messages: WidgetBubble[] = [bubble({ content: 'busy sentence' })]
    const addBotMessage = vi.fn()
    const scrollToBottom = vi.fn(async () => {})

    await recoverWidgetSend(
      () => messages,
      'a1',
      'busy sentence',
      false,
      async () => {
        messages = [{ id: 'a9', role: 'assistant', content: 'The stored answer' }]
      },
      'failed',
      addBotMessage,
      scrollToBottom
    )

    expect(addBotMessage).not.toHaveBeenCalled()
    expect(scrollToBottom).toHaveBeenCalled()
  })

  it('never counts the busy sentence as a recovered answer', async () => {
    const messages: WidgetBubble[] = [bubble({ content: 'busy sentence' })]
    const addBotMessage = vi.fn()

    await recoverWidgetSend(
      () => messages,
      'a1',
      'busy sentence',
      false,
      async () => {},
      'failed',
      addBotMessage,
      async () => {}
    )

    expect(messages).toEqual([])
    expect(addBotMessage).toHaveBeenCalledWith('failed')
  })
})
