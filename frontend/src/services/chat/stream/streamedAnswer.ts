/**
 * Paints the answer text a turn has accumulated so far into its message parts.
 *
 * Thinking blocks, prose, code, JSON and link lists become the structural
 * parts; media parts, which arrive through their own events, stay after them.
 * Parts are reconciled in place: a part that keeps its type keeps its object
 * and its `partId` (the Vue key) while its content grows, so the bubble never
 * remounts mid-stream. A raw document envelope never reaches the screen.
 */
import type { Message, Part } from '@/stores/history'
import { looksLikeFileGenerationEnvelope } from '@/utils/fileGenerationEnvelope'
import { extractMediaParts, generatePartId } from '@/utils/mediaParts'
import { parseAIResponse, type ParsedResponsePart } from '@/utils/responseParser'
import { hostOf } from './messageUpdates'
import type { ProcessingIndicator, StreamTurn } from './streamTurn'

const THINK_BLOCK = /<think>([\s\S]*?)(<\/think>|$)/g
const CLOSED_THINK_BLOCK = /<think>[\s\S]*?<\/think>/g
const THINK_TAG = /<think>|<\/think>/g
const STRUCTURAL_TYPES: ReadonlySet<Part['type']> = new Set([
  'thinking',
  'text',
  'code',
  'json',
  'links',
])

export function paintStreamedAnswer(
  message: Message,
  content: string,
  status: ProcessingIndicator,
  t: StreamTurn['t']
): void {
  // Keep media that arrived before the envelope: the audio player stays in the live bubble (#625).
  if (looksLikeFileGenerationEnvelope(content)) {
    if (status.current() !== 'generating_file') {
      status.set('generating_file', { customMessage: t('processing.generatingFile') })
    }
    message.parts = extractMediaParts(message.parts)
    return
  }

  const existing = message.parts.filter((part) => STRUCTURAL_TYPES.has(part.type))
  const structural = streamedParts(content).map((wanted, index) =>
    reconciled(existing[index], wanted)
  )
  message.parts = [...structural, ...extractMediaParts(message.parts)]
}

function streamedParts(content: string): Part[] {
  const thinking = (content.match(THINK_BLOCK) ?? [])
    .map((block) => block.replace(THINK_TAG, '').trim())
    .filter((inner) => inner !== '')
    .map((inner): Part => ({ type: 'thinking', content: inner }))
  const answer = parseAIResponse(content.replace(CLOSED_THINK_BLOCK, '').trim())

  return [...thinking, ...answer.parts.flatMap(structuralPart)]
}

function structuralPart(part: ParsedResponsePart): Part[] {
  switch (part.type) {
    case 'text':
      return [{ type: 'text', content: part.content }]
    case 'json':
      return [{ type: 'json', content: part.content, language: part.language ?? 'json' }]
    case 'code':
      return [{ type: 'code', content: part.content, language: part.language }]
    case 'links':
      return part.links
        ? [
            {
              type: 'links',
              items: part.links.map((link) => ({
                title: link.title,
                url: link.url,
                desc: link.description,
                host: hostOf(link.url),
              })),
            },
          ]
        : []
    default:
      return []
  }
}

function reconciled(existing: Part | undefined, wanted: Part): Part {
  if (!existing || existing.type !== wanted.type) {
    wanted.partId = generatePartId()
    return wanted
  }

  existing.partId ??= generatePartId()
  switch (wanted.type) {
    case 'text':
    case 'thinking':
      if (existing.content !== wanted.content) existing.content = wanted.content
      break
    case 'code':
    case 'json':
      if (existing.content !== wanted.content) existing.content = wanted.content
      if (existing.language !== wanted.language) existing.language = wanted.language
      if (existing.filename !== wanted.filename) existing.filename = wanted.filename
      break
    case 'links':
      if (JSON.stringify(existing.items ?? []) !== JSON.stringify(wanted.items ?? [])) {
        existing.items = wanted.items
      }
      break
  }
  return existing
}
