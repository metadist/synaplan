import type { Message } from '@/stores/history'
import type { LookDeeperInput } from '@/stores/researchSession'
import { scheduleSourceFromParts } from '@/utils/scheduleSource'

/** Turns handed to Look deeper: the last ones up to and including the answer. */
const LOOK_DEEPER_TURNS = 6
/** Each turn's text is cut here; the server keeps the same amount. */
const LOOK_DEEPER_TURN_CHARS = 2000

export interface MessageGroup {
  label: string
  messages: Message[]
}

/** The text of the user turn right before `messageId`; '' when there is none. */
export function userTextBefore(messages: Message[], messageId: string | number): string {
  const idx = messages.findIndex((row) => row.id === messageId)
  for (let i = idx - 1; i >= 0; i--) {
    if (messages[i].role === 'user') return scheduleSourceFromParts(messages[i].parts)
  }
  return ''
}

/**
 * What Look deeper under the answer `messageId` sends: the question that
 * answer replied to, the pages it cited, and the last turns up to and
 * including the answer.
 * `null` when no user turn precedes the answer.
 */
export function lookDeeperInput(
  messages: Message[],
  messageId: string | number
): LookDeeperInput | null {
  const question = userTextBefore(messages, messageId)
  if (question === '') return null
  const idx = messages.findIndex((row) => row.id === messageId)
  const context = messages
    .slice(0, idx + 1)
    .slice(-LOOK_DEEPER_TURNS)
    .map((row) => ({
      role: row.role,
      text: scheduleSourceFromParts(row.parts).slice(0, LOOK_DEEPER_TURN_CHARS),
    }))
    .filter((turn) => turn.text !== '')
  const seedSources = (messages[idx]?.searchResults ?? [])
    .filter((result) => result.url !== '')
    .map((result) => ({ url: result.url, title: result.title }))
  return { question, context, seedSources }
}

/** The transcript in day groups, in order; `labelOf` names the day. */
export function groupMessagesByDay(
  messages: Message[],
  labelOf: (date: Date) => string
): MessageGroup[] {
  const groups: MessageGroup[] = []
  let current: MessageGroup | null = null
  for (const message of messages) {
    const label = labelOf(message.timestamp)
    if (!current || current.label !== label) {
      current = { label, messages: [] }
      groups.push(current)
    }
    current.messages.push(message)
  }
  return groups
}
