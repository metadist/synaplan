/**
 * Streaming markdown rendering helper — finds the last "stable boundary"
 * in an in-progress markdown stream.
 *
 * Stable boundaries:
 *   1. End of a closed fenced code block
 *   2. End of a paragraph (`\n\n` run) outside any fence
 *   3. End of a complete markdown table row block (header + separator + data)
 */

function isTableRowLine(line: string): boolean {
  const trimmed = line.trim()
  return trimmed.startsWith('|') && trimmed.endsWith('|') && trimmed.length > 2
}

function isTableSeparatorLine(line: string): boolean {
  return /^\|[\s\-:|]+\|$/.test(line.trim())
}

/**
 * Longest prefix ending after a render-stable GFM table block.
 * Requires at least a header row and a separator row.
 */
function findCompleteTableBoundary(content: string): number {
  const lines = content.split('\n')
  let offset = 0
  let best = 0

  let tableStart = -1
  let rowCount = 0
  let hasSeparator = false
  let lastRowEnd = 0

  for (const line of lines) {
    const lineStart = offset
    offset += line.length + 1

    if (isTableRowLine(line)) {
      if (tableStart === -1) {
        tableStart = lineStart
        rowCount = 0
        hasSeparator = false
      }
      if (isTableSeparatorLine(line)) {
        hasSeparator = true
      }
      rowCount += 1
      lastRowEnd = offset
      continue
    }

    if (tableStart !== -1 && rowCount >= 2 && hasSeparator) {
      best = Math.max(best, lastRowEnd)
    }

    tableStart = -1
    rowCount = 0
    hasSeparator = false
  }

  if (tableStart !== -1 && rowCount >= 2 && hasSeparator) {
    best = Math.max(best, lastRowEnd)
  }

  return best
}

function findParagraphAndFenceBoundary(content: string): number {
  if (content.length === 0) return 0

  let inFence = false
  let lastBoundary = 0
  let i = 0

  while (i < content.length) {
    if (content[i] === '`' && content[i + 1] === '`' && content[i + 2] === '`') {
      const lineEnd = content.indexOf('\n', i + 3)
      if (lineEnd === -1) {
        return lastBoundary
      }

      if (inFence) {
        inFence = false
        i = lineEnd + 1
        while (i < content.length && content[i] === '\n') i++
        lastBoundary = i
      } else {
        inFence = true
        i = lineEnd + 1
      }
      continue
    }

    if (!inFence && content[i] === '\n' && content[i + 1] === '\n') {
      let j = i + 1
      while (j < content.length && content[j] === '\n') j++
      lastBoundary = j
      i = j
      continue
    }

    i++
  }

  return lastBoundary
}

export function findStableMarkdownBoundary(content: string): number {
  if (content.length === 0) return 0

  return Math.max(findParagraphAndFenceBoundary(content), findCompleteTableBoundary(content))
}
