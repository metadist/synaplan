export const PASTE_BLOCK_MIN_CHARS = 1200
export const PASTE_BLOCK_MIN_LINES = 10
/** A short multi-line address block should stay in the textarea. */
export const PASTE_BLOCK_MIN_CHARS_FOR_LINE_RULE = 400

export const PASTED_CONTENT_OPEN = '<pasted-content>'
export const PASTED_CONTENT_CLOSE = '</pasted-content>'

const PASTED_CONTENT_RE = /<pasted-content>([\s\S]*?)<\/pasted-content>/g
const PASTED_TAG_RE = /<\/?pasted-content>/g

export interface PastedTextBlock {
  id: string
  content: string
}

export function countLines(text: string): number {
  if (text.length === 0) {
    return 0
  }
  return text.split('\n').length
}

export function shouldBecomeBlock(text: string): boolean {
  const chars = text.length
  if (chars >= PASTE_BLOCK_MIN_CHARS) {
    return true
  }
  return chars >= PASTE_BLOCK_MIN_CHARS_FOR_LINE_RULE && countLines(text) > PASTE_BLOCK_MIN_LINES
}

export function sanitizePastedBody(text: string): string {
  return text.replace(PASTED_TAG_RE, '')
}

export function wrapPastedBlocks(typed: string, blocks: PastedTextBlock[]): string {
  const wrapped = blocks
    .map((block) => {
      const body = sanitizePastedBody(block.content)
      return `${PASTED_CONTENT_OPEN}\n${body}\n${PASTED_CONTENT_CLOSE}`
    })
    .join('\n\n')

  const rest = typed.trim()
  if (!wrapped) {
    return typed
  }
  if (!rest) {
    return wrapped
  }
  return `${wrapped}\n\n${rest}`
}

export function extractPastedBlocks(raw: string): { blocks: string[]; text: string } {
  const blocks: string[] = []
  const text = raw
    .replace(PASTED_CONTENT_RE, (_match, body: string) => {
      blocks.push(body.replace(/^\n/, '').replace(/\n$/, ''))
      return ''
    })
    .replace(/\n{3,}/g, '\n\n')
    .trim()

  return { blocks, text }
}

export function stripPastedBlocks(raw: string): string {
  return raw
    .replace(PASTED_CONTENT_RE, '')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

export function createPastedBlockId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `paste-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}
