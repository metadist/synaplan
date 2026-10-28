import { describe, expect, it } from 'vitest'
import type { Message } from '@/stores/history'
import { groupMessagesByDay, lookDeeperInput, userTextBefore } from '@/utils/chatTurns'

const turn = (id: string, role: Message['role'], text: string, day = 1): Message => ({
  id,
  role,
  parts: [{ type: 'text', content: text }],
  timestamp: new Date(2026, 9, day, 12),
})

const transcript: Message[] = [
  turn('u1', 'user', 'First question'),
  turn('a1', 'assistant', 'First answer'),
  turn('u2', 'user', 'Second question'),
  turn('a2', 'assistant', 'Second answer'),
  turn('u3', 'user', 'Third question'),
  turn('a3', 'assistant', 'Third answer'),
  turn('u4', 'user', 'Fourth question'),
  turn('a4', 'assistant', 'Fourth answer'),
]

describe('userTextBefore', () => {
  it('returns the user turn right before the answer', () => {
    expect(userTextBefore(transcript, 'a2')).toBe('Second question')
  })

  it('returns nothing when no user turn precedes the message', () => {
    expect(userTextBefore([turn('a0', 'assistant', 'Hello')], 'a0')).toBe('')
    expect(userTextBefore(transcript, 'missing')).toBe('')
  })
})

describe('lookDeeperInput', () => {
  it('sends the question and the last six turns up to and including the answer', () => {
    const input = lookDeeperInput(transcript, 'a4')

    expect(input?.question).toBe('Fourth question')
    expect(input?.context.map((row) => row.text)).toEqual([
      'Second question',
      'Second answer',
      'Third question',
      'Third answer',
      'Fourth question',
      'Fourth answer',
    ])
    expect(input?.context[0].role).toBe('user')
  })

  it('cuts each turn and drops empty ones', () => {
    const long = 'x'.repeat(2500)
    const input = lookDeeperInput([turn('u1', 'user', long), turn('a1', 'assistant', '  ')], 'a1')

    expect(input?.context).toHaveLength(1)
    expect(input?.context[0].text).toHaveLength(2000)
  })

  it('is null when the answer has no question before it', () => {
    expect(lookDeeperInput([turn('a0', 'assistant', 'Hello')], 'a0')).toBeNull()
  })
})

describe('groupMessagesByDay', () => {
  it('groups consecutive messages of the same day in order', () => {
    const groups = groupMessagesByDay(
      [turn('u1', 'user', 'a', 1), turn('a1', 'assistant', 'b', 1), turn('u2', 'user', 'c', 2)],
      (date) => `day ${date.getDate()}`
    )

    expect(groups.map((group) => [group.label, group.messages.length])).toEqual([
      ['day 1', 2],
      ['day 2', 1],
    ])
  })

  it('is empty for an empty transcript', () => {
    expect(groupMessagesByDay([], () => 'today')).toEqual([])
  })
})
