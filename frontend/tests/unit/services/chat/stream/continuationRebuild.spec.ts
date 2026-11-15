import { describe, expect, it, vi } from 'vitest'
import { createContinueStreamHandler } from '@/services/chat/stream/streamUpdateHandler'
import type { ContinueTurn } from '@/services/chat/stream/streamTurn'
import { assistantMessage } from './fakeTurn'

describe('continue stream rebuild', () => {
  it('restores the pre-continuation answer before the replay appends', () => {
    const message = assistantMessage({
      parts: [
        { type: 'text', content: 'Started' },
        { type: 'thinking', content: 'earlier' },
      ],
    })
    const renderContent = vi.fn()
    const turn = {
      message,
      text: { content: 'Started', thinkOpen: false },
      smoother: { push: vi.fn(), flush: vi.fn(), cancel: vi.fn() },
      history: {},
      usage: { active: false },
      renderContent,
      showError: vi.fn(),
      t: (key: string) => key,
    } as unknown as ContinueTurn

    const onUpdate = createContinueStreamHandler(turn)
    message.parts.push({ type: 'text', content: ' and more' })
    turn.text.content = 'Started and more'

    onUpdate({ status: 'stream_rebuild' })

    expect(turn.text.content).toBe('Started')
    expect(message.parts).toEqual([
      { type: 'text', content: 'Started' },
      { type: 'thinking', content: 'earlier' },
    ])
    expect(renderContent).toHaveBeenCalledWith('Started')
  })
})
