import { describe, expect, it } from 'vitest'
import { paintStreamedAnswer } from '@/services/chat/stream/streamedAnswer'
import { assistantMessage, fakeTurn } from './fakeTurn'

function painter() {
  const message = assistantMessage({ parts: [] })
  const turn = fakeTurn(message)
  const paint = (content: string) => paintStreamedAnswer(message, content, turn.status, turn.t)
  return { message, turn, paint }
}

describe('paintStreamedAnswer', () => {
  it('splits thinking from the answer, an open think block included', () => {
    const { message, paint } = painter()

    paint('<think>Weighing</think>The answer')
    expect(message.parts.map(({ type, content }) => ({ type, content }))).toEqual([
      { type: 'thinking', content: 'Weighing' },
      { type: 'text', content: 'The answer' },
    ])

    paint('<think>Still weighing')
    expect(message.parts[0]).toMatchObject({ type: 'thinking', content: 'Still weighing' })
  })

  it('grows a part in place, so its key survives the stream', () => {
    const { message, paint } = painter()

    paint('Hello')
    const [first] = message.parts
    paint('Hello world')

    expect(message.parts[0]).toBe(first)
    expect(first).toMatchObject({ content: 'Hello world', partId: expect.any(String) })
  })

  it('gives a part that changes its type a fresh key', () => {
    const { message, paint } = painter()

    paint('```js\nconst a = 1\n```')
    const [code] = message.parts
    paint('Plain prose now')

    expect(message.parts[0].type).toBe('text')
    expect(message.parts[0].partId).not.toBe(code.partId)
  })

  it('keeps media from their own events after the text', () => {
    const { message, paint } = painter()
    message.parts.push({ type: 'image', url: '/api/v1/files/uploads/a.png' })

    paint('Here is the picture')

    expect(message.parts.map((part) => part.type)).toEqual(['text', 'image'])
  })

  it('hides a raw document envelope but keeps the media that already arrived', () => {
    const { message, turn, paint } = painter()
    message.parts.push({ type: 'text', content: 'old' }, { type: 'audio', url: '/a.mp3' })

    paint('Your report: {"BFILEPATH": "report.docx", "BFILETEXT": "draft"')

    expect(message.parts.map((part) => part.type)).toEqual(['audio'])
    expect(turn.indicator).toEqual({
      status: 'generating_file',
      metadata: { customMessage: 'processing.generatingFile' },
    })
  })
})
