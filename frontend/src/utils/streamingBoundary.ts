/**
 * Streaming markdown rendering helper — finds the last "stable boundary"
 * in an in-progress markdown stream.
 *
 * A stable boundary is a position where we know that the markdown
 * pipeline output for `content.slice(0, boundary)` will not change as
 * more characters are appended after `boundary`. Splitting the streamed
 * content there lets us:
 *
 *   - run the full markdown + DOMPurify + highlight.js pipeline ONCE
 *     on the stable prefix and cache the resulting HTML, so headings,
 *     tables, and closed code blocks stay byte-identical between SSE
 *     chunks (no flicker — see issue #903);
 *   - render only the trailing in-progress paragraph with the cheap
 *     escape + <br> path, since it would be rewritten by every chunk
 *     anyway.
 *
 * Stable boundaries:
 *   1. End of a closed fenced code block (line right after the closing
 *      ``` line). Inside an unclosed fence everything keeps changing
 *      shape as new lines arrive.
 *   2. End of a paragraph (a `\n\n` run) outside any fence. After a
 *      blank line, markdown treats the next line as a brand-new block,
 *      so the previous block can never grow downward.
 *
 * Returns 0 when no stable boundary exists yet (still inside the very
 * first paragraph, or inside an unclosed code fence). The caller should
 * then render the entire content with the cheap streaming path.
 */
export function findStableMarkdownBoundary(content: string): number {
  if (content.length === 0) return 0

  let inFence = false
  let lastBoundary = 0
  let i = 0

  while (i < content.length) {
    if (content[i] === '`' && content[i + 1] === '`' && content[i + 2] === '`') {
      const lineEnd = content.indexOf('\n', i + 3)
      if (lineEnd === -1) {
        // Fence marker without a terminating newline yet — treat the
        // whole tail (including the unclosed fence) as in-progress.
        return lastBoundary
      }

      if (inFence) {
        inFence = false
        i = lineEnd + 1
        // Right after a fully-closed fence is a safe split point: the
        // closed code block's HTML is fixed. Also swallow any blank
        // lines that follow so the boundary lands cleanly on the start
        // of the next block (matches the paragraph-break case below).
        while (i < content.length && content[i] === '\n') i++
        lastBoundary = i
      } else {
        inFence = true
        i = lineEnd + 1
      }
      continue
    }

    if (!inFence && content[i] === '\n' && content[i + 1] === '\n') {
      // Skip the entire run of consecutive newlines so the boundary
      // sits cleanly on the first character of the next block.
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
