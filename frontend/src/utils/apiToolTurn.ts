/** Marker rendered as a short sentence instead of a raw tool_result bubble. */
export const TOOL_RESULT_MARKER = '__TOOL_RESULT__'

const TOOL_BLOCK_TYPES = new Set(['tool_result', 'tool_use'])

type ContentBlock = { type: string; text?: unknown }

function isBlock(value: unknown): value is ContentBlock {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const type = (value as { type?: unknown }).type
  return typeof type === 'string' && type !== ''
}

/**
 * Human text from stored Anthropic content, or null when $value is not a
 * content block payload. An empty string means the payload is only tool JSON.
 */
function textFromBlocks(value: unknown): string | null {
  if (isBlock(value)) {
    if (TOOL_BLOCK_TYPES.has(value.type)) return ''
    if (value.type === 'text' && typeof value.text === 'string') return value.text.trim()
    return null
  }

  if (!Array.isArray(value) || value.length === 0) return null
  if (!value.every(isBlock)) return null

  const texts: string[] = []
  let hasTool = false
  for (const block of value) {
    if (TOOL_BLOCK_TYPES.has(block.type)) {
      hasTool = true
      continue
    }
    if (block.type === 'text' && typeof block.text === 'string') {
      const piece = block.text.trim()
      if (piece) texts.push(piece)
    }
  }

  if (!hasTool && texts.length === 0) return null
  return texts.join('\n\n')
}

/**
 * Replace a stored tool_result user bubble with readable text.
 * Ordinary prose, including JSON the person typed, is unchanged.
 * A tool-only payload becomes {@link TOOL_RESULT_MARKER}.
 */
export function collapseApiToolTurn(text: string): string {
  const trimmed = text.trim()
  if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) return text

  let parsed: unknown
  try {
    parsed = JSON.parse(trimmed) as unknown
  } catch {
    return text
  }

  const human = textFromBlocks(parsed)
  if (human === null) return text
  if (human === '') return TOOL_RESULT_MARKER
  return human
}
