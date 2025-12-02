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
  jsonPayload?: Record<string, any> | null
}

const URL_REGEX = /https?:\/\/[^\s<>"{}|\\^`\[\]]+/g
const MARKDOWN_LINK_REGEX = /\[([^\]]+)\]\(([^)]+)\)/g
const CODE_BLOCK_REGEX = /```(\w+)?\n([\s\S]*?)```/g
const INLINE_CODE_REGEX = /`([^`]+)`/g

export function parseAIResponse(content: string): ParsedResponse {
  const parts: ParsedResponsePart[] = []
  let hasLinks = false
  let hasCode = false
  let hasJson = false
  let jsonPayload: Record<string, any> | null = null

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

  // Extract code blocks first
  const codeBlocks: Array<{ start: number; end: number; language?: string; code: string }> = []
  let codeMatch
  while ((codeMatch = CODE_BLOCK_REGEX.exec(content)) !== null) {
    const language = codeMatch[1] || 'text'
    const code = codeMatch[2].trim()
    codeBlocks.push({
      start: codeMatch.index,
      end: codeMatch.index + codeMatch[0].length,
      language,
      code
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
      language: block.language
    })

    lastIndex = block.end
  }

  // Add remaining text after last code block
  if (lastIndex < content.length) {
    const textContent = content.slice(lastIndex)
    parseTextContent(textContent, parts)
  }

  // Check if we found links in the text
  hasLinks = parts.some(p => p.type === 'link' || p.type === 'links')

  return {
    parts,
    hasLinks,
    hasCode,
    hasJson,
    jsonPayload
  }
}

function parseTextContent(text: string, parts: ParsedResponsePart[]) {
  // Extract all links (both markdown and plain URLs)
  const links: Array<{ url: string; title: string; position: number }> = []
  
  // Find markdown links
  let mdLinkMatch
  const markdownLinkRegex = /\[([^\]]+)\]\(([^)]+)\)/g
  while ((mdLinkMatch = markdownLinkRegex.exec(text)) !== null) {
    links.push({
      title: mdLinkMatch[1],
      url: mdLinkMatch[2],
      position: mdLinkMatch.index
    })
  }

  // Find plain URLs (that are not part of markdown links)
  let urlMatch
  const urlRegex = /https?:\/\/[^\s<>"{}|\\^`\[\]]+/g
  while ((urlMatch = urlRegex.exec(text)) !== null) {
    // Check if this URL is part of a markdown link
    const isInMarkdown = links.some(l => 
      urlMatch.index >= l.position && 
      urlMatch.index < l.position + l.title.length + l.url.length + 4
    )
    
    if (!isInMarkdown) {
      links.push({
        url: urlMatch[0],
        title: urlMatch[0],
        position: urlMatch.index
      })
    }
  }

  // If we have multiple links (web search results), group them
  if (links.length >= 3) {
    // Extract the text before links
    const textBeforeLinks = links.length > 0 ? text.slice(0, links[0].position).trim() : text
    if (textBeforeLinks) {
      parts.push({
        type: 'text',
        content: textBeforeLinks
      })
    }

    // Group all links
    parts.push({
      type: 'links',
      content: '',
      links: links.map(l => ({
        url: l.url,
        title: l.title,
        description: extractLinkDescription(text, l.position)
      }))
    })

    // Add remaining text after links
    const lastLink = links[links.length - 1]
    const textAfterLinks = text.slice(lastLink.position + lastLink.url.length).trim()
    if (textAfterLinks) {
      parts.push({
        type: 'text',
        content: textAfterLinks
      })
    }
  } else if (links.length > 0) {
    // Few links, keep them inline
    parts.push({
      type: 'text',
      content: text
    })
  } else {
    // No links, just text
    if (text.trim()) {
      parts.push({
        type: 'text',
        content: text.trim()
      })
    }
  }
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
      url: match[2]
    })
  }

  // Extract plain URLs
  const urlMatches = content.match(URL_REGEX) || []
  for (const url of urlMatches) {
    // Skip if already in markdown links
    if (!links.some(l => l.url === url)) {
      links.push({
        title: url,
        url
      })
    }
  }

  return links
}

export function hasWebSearchResults(content: string): boolean {
  const links = extractLinks(content)
  return links.length >= 3
}

