import { describe, expect, it } from 'vitest'
import { incognitoHistory } from '@/utils/incognitoHistory'
import type { Message } from '@/stores/history'

const turn = (role: Message['role'], text: string, extra: Partial<Message> = {}): Message => ({
  id: `${role}-${text}`,
  role,
  parts: [{ type: 'text', content: text }],
  timestamp: new Date(),
  ...extra,
})

describe('incognitoHistory', () => {
  it('sends the earlier turns and leaves the current question out', () => {
    const history = incognitoHistory([
      turn('user', 'weisst Du, wer ich bin?'),
      turn('assistant', 'Nein, deinen Namen kenne ich nicht.'),
      turn('user', 'du kennst meinen namen'),
      turn('assistant', '', { isStreaming: true }),
    ])

    expect(history).toEqual([
      { role: 'user', content: 'weisst Du, wer ich bin?' },
      { role: 'assistant', content: 'Nein, deinen Namen kenne ich nicht.' },
    ])
  })

  it('leaves a failed turn out: the error answer and the question it failed on', () => {
    const history = incognitoHistory([
      turn('user', 'weisst Du, wer ich bin?'),
      turn('assistant', 'Nein, deinen Namen kenne ich nicht.'),
      turn('user', 'du kennst meinen namen'),
      turn('assistant', 'Der KI-Dienst hat diese Anfrage abgelehnt.', {
        errorReason: 'content_filtered',
      }),
      turn('user', 'und was weisst du noch?'),
    ])

    expect(history).toEqual([
      { role: 'user', content: 'weisst Du, wer ich bin?' },
      { role: 'assistant', content: 'Nein, deinen Namen kenne ich nicht.' },
    ])
  })

  it('keeps the answer before a failed turn whose question had no text', () => {
    const history = incognitoHistory([
      turn('user', 'weisst Du, wer ich bin?'),
      turn('assistant', 'Nein, deinen Namen kenne ich nicht.'),
      { ...turn('user', ''), parts: [] },
      turn('assistant', 'Bei der Antwort ist etwas schiefgelaufen.', { errorReason: 'unknown' }),
      turn('user', 'nochmal'),
    ])

    expect(history.map((entry) => entry.content)).toEqual([
      'weisst Du, wer ich bin?',
      'Nein, deinen Namen kenne ich nicht.',
    ])
  })
})
