import { extractBTextPayload } from './jsonResponse'

export interface ParsedResponsePart {
  type: 'text' | 'code' | 'json' | 'link' | 'links' | 'thinking'
  content: string
  language?: string
  url?: string
  title?: string
  links?: Array<{ url: string; title: string; description?: string }>
}

export interface ParsedResponse {
  parts: ParsedResponsePart[]
  hasLinks: boolean
  hasCode: boolean
  hasJson: boolean
  jsonPayload?: Record<string, unknown> | null
}

const URL_REGEX = /https?:\/\/[^\s<>"{}|\\^`[\]]+/g
const MARKDOWN_LINK_REGEX = /\[([^\]]+)\]\(([^)]+)\)/g
const CODE_BLOCK_REGEX = /```(\w+)?\n([\s\S]*?)```/g

export function parseAIResponse(content: string): ParsedResponse {
  const parts: ParsedResponsePart[] = []
  let hasCode = false
  let hasJson = false
  let jsonPayload: Record<string, unknown> | null = null

  // Legacy: Extract BTEXT from JSON (only for backward compatibility with old messages)
  // New messages return plain text directly
  const extraction = extractBTextPayload(content)
  if (extraction.text !== undefined) {
    jsonPayload = extraction.data || null
    hasJson = true
    const remainder = extraction.remainder?.trim()
    const text = extraction.text ?? ''
    content = remainder ? `${text}\n\n${remainder}`.trim() : text
  }

  let lastIndex = 0

  // Extract code blocks first (except mermaid - those stay in markdown for diagram rendering)
  const codeBlocks: Array<{ start: number; end: number; language?: string; code: string }> = []
  let codeMatch
  while ((codeMatch = CODE_BLOCK_REGEX.exec(content)) !== null) {
    const language = codeMatch[1] || 'text'
    const code = codeMatch[2].trim()

    // Skip mermaid blocks - they should stay in markdown for diagram rendering
    if (language.toLowerCase() === 'mermaid') {
      continue
    }

    codeBlocks.push({
      start: codeMatch.index,
      end: codeMatch.index + codeMatch[0].length,
      language,
      code,
    })

    if (language === 'json') {
      hasJson = true
    }
    hasCode = true
  }

  // Sort by position
  codeBlocks.sort((a, b) => a.start - b.start)

  // Parse content, handling code blocks
  for (let i = 0; i < codeBlocks.length; i++) {
    const block = codeBlocks[i]

    // Add text before code block
    if (block.start > lastIndex) {
      const textContent = content.slice(lastIndex, block.start)
      parseTextContent(textContent, parts)
    }

    // Add code block
    parts.push({
      type: block.language === 'json' ? 'json' : 'code',
      content: block.code,
      language: block.language,
    })

    lastIndex = block.end
  }

  // Add remaining text after last code block
  if (lastIndex < content.length) {
    const textContent = content.slice(lastIndex)
    parseTextContent(textContent, parts)
  }

  const hasLinks = parts.some((p) => p.type === 'link' || p.type === 'links')

  return {
    parts,
    hasLinks,
    hasCode,
    hasJson,
    jsonPayload,
  }
}

function parseTextContent(text: string, parts: ParsedResponsePart[]) {
  // Extract all links (both markdown and plain URLs)
  const links: Array<{ url: string; title: string; position: number }> = []

  // Find markdown links
  let mdLinkMatch: RegExpExecArray | null
  const markdownLinkRegex = /\[([^\]]+)\]\(([^)]+)\)/g
  while ((mdLinkMatch = markdownLinkRegex.exec(text)) !== null) {
    links.push({
      title: mdLinkMatch[1],
      url: mdLinkMatch[2],
      position: mdLinkMatch.index,
    })
  }

  // Find plain URLs (that are not part of markdown links)
  let urlMatch: RegExpExecArray | null
  const urlRegex = /https?:\/\/[^\s<>"{}|\\^`[\]]+/g
  while ((urlMatch = urlRegex.exec(text)) !== null) {
    const currentMatch = urlMatch
    const matchIndex = currentMatch.index ?? 0
    // Check if this URL is part of a markdown link
    const isInMarkdown = links.some(
      (l) => matchIndex >= l.position && matchIndex < l.position + l.title.length + l.url.length + 4
    )

    if (!isInMarkdown) {
      links.push({
        url: currentMatch[0],
        title: currentMatch[0],
        position: matchIndex,
      })
    }
  }

  // Only group links into cards when they look like a dedicated link list
  // (e.g. web search results). If links are scattered across long prose,
  // keep them inline and let the markdown renderer handle them.
  const looksLikeLinkList = links.length >= 3 && isClusteredLinkList(text, links)

  if (looksLikeLinkList) {
    const textBeforeLinks = text.slice(0, links[0].position).trim()
    if (textBeforeLinks) {
      parts.push({
        type: 'text',
        content: textBeforeLinks,
      })
    }

    parts.push({
      type: 'links',
      content: '',
      links: links.map((l) => ({
        url: l.url,
        title: l.title,
        description: extractLinkDescription(text, l.position),
      })),
    })

    const lastLink = links[links.length - 1]
    const textAfterLinks = text.slice(lastLink.position + lastLink.url.length).trim()
    if (textAfterLinks) {
      parts.push({
        type: 'text',
        content: textAfterLinks,
      })
    }
  } else if (text.trim()) {
    parts.push({
      type: 'text',
      content: text.trim(),
    })
  }
}

/**
 * Detect whether the links form a compact list (e.g. web search results)
 * vs. being scattered across a long text (normal prose with inline URLs).
 *
 * Heuristic: links are "clustered" when the non-link text between the first
 * and last link is short relative to the span they cover. If there are large
 * blocks of prose between links, they are inline references, not a link list.
 */
function isClusteredLinkList(
  text: string,
  links: Array<{ url: string; title: string; position: number }>
): boolean {
  if (links.length < 3) return false

  const sorted = [...links].sort((a, b) => a.position - b.position)
  const first = sorted[0]
  const last = sorted[sorted.length - 1]
  const spanStart = first.position
  const spanEnd = last.position + last.url.length

  // If any single gap between consecutive links is > 200 chars, this is
  // regular prose with inline URLs, not a dedicated link list.
  for (let i = 0; i < sorted.length - 1; i++) {
    const gapStart = sorted[i].position + sorted[i].url.length
    const gapEnd = sorted[i + 1].position
    const gap = text.slice(gapStart, gapEnd).trim()
    if (gap.length > 200) return false
  }

  // If the link span covers less than 60% of the total text, the links
  // are embedded in a larger body of prose.
  const spanLength = spanEnd - spanStart
  if (spanLength < text.length * 0.6 && text.length > 500) return false

  return true
}

function extractLinkDescription(text: string, linkPosition: number): string | undefined {
  // Try to find description near the link (next 100 chars)
  const afterLink = text.slice(linkPosition).split('\n')[0]
  const description = afterLink.slice(afterLink.indexOf(')') + 1, 100).trim()
  return description.length > 10 ? description : undefined
}

export function extractLinks(content: string): Array<{ url: string; title: string }> {
  const links: Array<{ url: string; title: string }> = []

  // Extract markdown links
  let match
  while ((match = MARKDOWN_LINK_REGEX.exec(content)) !== null) {
    links.push({
      title: match[1],
      url: match[2],
    })
  }

  // Extract plain URLs
  const urlMatches = content.match(URL_REGEX) || []
  for (const url of urlMatches) {
    // Skip if already in markdown links
    if (!links.some((l) => l.url === url)) {
      links.push({
        title: url,
        url,
      })
    }
  }

  return links
}

export function hasWebSearchResults(content: string): boolean {
  const links = extractLinks(content)
  return links.length >= 3
}
