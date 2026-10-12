import { describe, expect, it } from 'vitest'
import type { Message } from '@/stores/history'
import {
  questionText,
  shouldReloadTranscriptAfterVersionLink,
  transcriptAfterSavedEdit,
} from '@/utils/editTranscript'

function message(id: string, role: Message['role'], text: string): Message {
  return {
    id,
    role,
    parts: [{ type: 'text', content: text }],
    timestamp: new Date('2026-10-12T08:00:00Z'),
  }
}

describe('transcriptAfterSavedEdit', () => {
  it('shows the saved question and drops the previous branch without a reload', () => {
    const messages = [
      message('earlier', 'user', 'hello'),
      message('earlier-answer', 'assistant', 'hi'),
      message('q', 'user', 'old sentence'),
      message('a', 'assistant', 'old answer'),
      message('later', 'user', 'follow up'),
    ]

    const visible = transcriptAfterSavedEdit(messages, 'q', 'new sentence')
    const streamText = 'new sentence'
    const withAnswer = [...visible, message('streaming', 'assistant', '')]

    expect(questionText(visible.find((row) => row.id === 'q')?.parts ?? [])).toBe(streamText)
    expect(visible.some((row) => questionText(row.parts).includes('old sentence'))).toBe(false)
    expect(visible.some((row) => row.id === 'a' || row.id === 'later')).toBe(false)
    expect(questionText(visible[0].parts)).toBe('hello')
    expect(withAnswer[withAnswer.length - 1].role).toBe('assistant')
    expect(
      questionText(withAnswer.find((row) => row.role === 'user' && row.id === 'q')?.parts ?? [])
    ).toBe(streamText)
  })

  it('keeps file parts on the edited question', () => {
    const edited = transcriptAfterSavedEdit(
      [
        {
          ...message('q', 'user', 'old sentence'),
          parts: [
            { type: 'text', content: 'old sentence' },
            { type: 'image', imageUrl: '/files/1' },
          ],
        },
      ],
      'q',
      'new sentence'
    )

    expect(edited[0].parts.map((part) => part.type)).toEqual(['text', 'image'])
    expect(questionText(edited[0].parts)).toBe('new sentence')
  })

  it('reloads the transcript after an edit link and not after Again or a chat switch', () => {
    expect(
      shouldReloadTranscriptAfterVersionLink({
        kind: 'edit',
        incognito: false,
        editedChatId: 4,
        activeChatId: 4,
      })
    ).toBe(true)
    expect(
      shouldReloadTranscriptAfterVersionLink({
        kind: 'again',
        incognito: false,
        editedChatId: 4,
        activeChatId: 4,
      })
    ).toBe(false)
    expect(
      shouldReloadTranscriptAfterVersionLink({
        kind: 'edit',
        incognito: true,
        editedChatId: 4,
        activeChatId: 4,
      })
    ).toBe(false)
    expect(
      shouldReloadTranscriptAfterVersionLink({
        kind: 'edit',
        incognito: false,
        editedChatId: 4,
        activeChatId: 9,
      })
    ).toBe(false)
  })
})
