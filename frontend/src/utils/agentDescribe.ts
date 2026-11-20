const MAX_DESCRIPTION_LENGTH = 160
const MIN_SENTENCE_LENGTH = 10

function cleanLine(line: string): string {
  return line
    .replace(/^\s{0,3}(#{1,6}|>|[-*+]|\d+[.)])\s+/, '')
    .replace(/[*_`]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
}

function firstSentence(line: string): string {
  const end = line.search(/[.!?…](\s|$)/)
  const sentence = end === -1 ? line : line.slice(0, end + 1)
  return sentence.trim()
}

function capSentence(sentence: string): string {
  if (sentence.length <= MAX_DESCRIPTION_LENGTH) {
    return sentence
  }
  const cut = sentence.lastIndexOf(' ', MAX_DESCRIPTION_LENGTH)
  const base = (cut > 0 ? sentence.slice(0, cut) : sentence.slice(0, MAX_DESCRIPTION_LENGTH)).trim()
  return /[.!?…]$/.test(base) ? base : `${base}.`
}

/**
 * One plain sentence that says what the agent does, taken from the first
 * usable sentence of its instructions. Empty when the instructions carry
 * nothing usable — callers then leave the description empty instead of
 * inventing one.
 */
export function deriveAgentDescription(instructions: string): string {
  for (const rawLine of (instructions ?? '').split('\n')) {
    const line = cleanLine(rawLine)
    if (line.length < MIN_SENTENCE_LENGTH) {
      continue
    }
    let sentence = firstSentence(line)
    if (/^you are /i.test(sentence)) {
      sentence = sentence.replace(/^you are (an?\s+|the\s+)?/i, '')
      sentence = sentence.charAt(0).toUpperCase() + sentence.slice(1)
    }
    if (sentence.length < MIN_SENTENCE_LENGTH) {
      continue
    }
    return capSentence(sentence)
  }
  return ''
}

/**
 * The greeting carries the agent's name; the topical part is the
 * description's job. `template` is the localized "Hi, I'm {name}…" line.
 */
export function deriveAgentGreeting(name: string, template: string): string {
  const trimmed = (name ?? '').trim()
  if (!trimmed || !template.includes('{name}')) {
    return ''
  }
  return template.replace('{name}', trimmed)
}

export interface DerivedTexts {
  description?: string
  greeting?: string
}

/**
 * The texts a still-empty field may take from the instructions. A field the
 * user already filled is absent from the result — deriving never
 * overwrites. Without usable instructions both stay absent.
 */
export function derivedTexts(input: {
  instructions: string
  description: string
  greeting: string
  name: string
  greetingTemplate: string
}): DerivedTexts {
  const result: DerivedTexts = {}
  if (!(input.instructions ?? '').trim()) {
    return result
  }
  if (!(input.description ?? '').trim()) {
    const description = deriveAgentDescription(input.instructions)
    if (description) {
      result.description = description
    }
  }
  if (!(input.greeting ?? '').trim()) {
    const greeting = deriveAgentGreeting(input.name, input.greetingTemplate)
    if (greeting) {
      result.greeting = greeting
    }
  }
  return result
}
