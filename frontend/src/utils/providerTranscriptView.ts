export interface ProviderTranscriptMessage {
  role: string
  text: string
}

export interface ProviderTranscriptTurn {
  role: 'you' | 'assistant'
  text: string
}

/**
 * One request the provider actually received.
 * The last "you" turn is the question. Turns before it are earlier messages.
 * Turns after it were included with that question (for example a tool result).
 */
export interface ProviderTranscriptRound {
  instructions: string[]
  history: ProviderTranscriptTurn[]
  question: string | null
  after: ProviderTranscriptTurn[]
}

const KNOWN_ROLES = new Set(['instructions', 'you', 'assistant'])

function isTurn(message: ProviderTranscriptMessage): message is ProviderTranscriptTurn {
  return message.role === 'you' || message.role === 'assistant'
}

/**
 * A tool loop appends the next provider call onto the same transcript.
 * Instructions that show up again after conversation turns start that next call.
 */
function splitRounds(
  messages: readonly ProviderTranscriptMessage[]
): ProviderTranscriptMessage[][] {
  const rounds: ProviderTranscriptMessage[][] = []
  let current: ProviderTranscriptMessage[] = []
  let seenConversation = false

  for (const message of messages) {
    const text = message.text.trim()
    if (text === '' || !KNOWN_ROLES.has(message.role)) continue

    const row: ProviderTranscriptMessage = { role: message.role, text }
    if (row.role === 'instructions' && seenConversation && current.length > 0) {
      rounds.push(current)
      current = []
      seenConversation = false
    }
    current.push(row)
    if (row.role !== 'instructions') seenConversation = true
  }

  if (current.length > 0) rounds.push(current)
  return rounds
}

function uniqueInstructions(messages: readonly ProviderTranscriptMessage[]): string[] {
  const instructions: string[] = []
  for (const message of messages) {
    if (message.role !== 'instructions') continue
    if (instructions[instructions.length - 1] === message.text) continue
    instructions.push(message.text)
  }
  return instructions
}

function toRound(messages: readonly ProviderTranscriptMessage[]): ProviderTranscriptRound | null {
  const instructions = uniqueInstructions(messages)
  const turns = messages.filter(isTurn)
  let questionIndex = -1
  for (let index = turns.length - 1; index >= 0; index -= 1) {
    if (turns[index].role === 'you') {
      questionIndex = index
      break
    }
  }

  const round: ProviderTranscriptRound =
    questionIndex === -1
      ? { instructions, history: turns, question: null, after: [] }
      : {
          instructions,
          history: turns.slice(0, questionIndex),
          question: turns[questionIndex].text,
          after: turns.slice(questionIndex + 1),
        }

  if (
    round.instructions.length === 0 &&
    round.history.length === 0 &&
    round.question === null &&
    round.after.length === 0
  ) {
    return null
  }

  return round
}

export function groupProviderTranscript(
  messages: readonly ProviderTranscriptMessage[] | null | undefined
): ProviderTranscriptRound[] {
  if (!messages || messages.length === 0) return []
  return splitRounds(messages)
    .map(toRound)
    .filter((round): round is ProviderTranscriptRound => round !== null)
}
