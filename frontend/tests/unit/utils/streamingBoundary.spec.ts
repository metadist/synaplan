import { describe, it, expect } from 'vitest'
import { findStableMarkdownBoundary } from '@/utils/streamingBoundary'

describe('findStableMarkdownBoundary (issue #903)', () => {
  describe('empty / no boundary', () => {
    it('returns 0 for empty content', () => {
      expect(findStableMarkdownBoundary('')).toBe(0)
    })

    it('returns 0 when the content is still in the first paragraph', () => {
      expect(findStableMarkdownBoundary('Hello world')).toBe(0)
    })

    it('returns 0 when only single newlines separate lines (no paragraph break yet)', () => {
      expect(findStableMarkdownBoundary('Line one\nLine two')).toBe(0)
    })
  })

  describe('paragraph breaks', () => {
    it('marks the position right after a `\\n\\n` as stable', () => {
      const content = 'Paragraph one\n\nParagraph two'
      const boundary = findStableMarkdownBoundary(content)
      expect(content.slice(0, boundary)).toBe('Paragraph one\n\n')
      expect(content.slice(boundary)).toBe('Paragraph two')
    })

    it('uses the LAST paragraph break when multiple exist', () => {
      const content = '# Title\n\nFirst body.\n\nSecond body, in progress'
      const boundary = findStableMarkdownBoundary(content)
      expect(content.slice(0, boundary)).toBe('# Title\n\nFirst body.\n\n')
      expect(content.slice(boundary)).toBe('Second body, in progress')
    })

    it('skips runs of consecutive newlines so the boundary lands on the next block', () => {
      const content = 'A\n\n\n\nB still streaming'
      const boundary = findStableMarkdownBoundary(content)
      expect(content.slice(0, boundary)).toBe('A\n\n\n\n')
      expect(content.slice(boundary)).toBe('B still streaming')
    })

    it('returns content.length when content ends with a paragraph break', () => {
      const content = 'Done paragraph.\n\n'
      const boundary = findStableMarkdownBoundary(content)
      expect(boundary).toBe(content.length)
    })
  })

  describe('code fences', () => {
    it('does not split inside an unclosed code fence', () => {
      const content = '# Heading\n\n```python\ndef foo():\n    return 1\n'
      const boundary = findStableMarkdownBoundary(content)
      // The heading paragraph break IS stable, but the unclosed fence is not.
      expect(content.slice(0, boundary)).toBe('# Heading\n\n')
    })

    it('marks the line right after a closed fence as a stable boundary', () => {
      const content = '```python\ndef foo():\n    return 1\n```\nNext line still typing'
      const boundary = findStableMarkdownBoundary(content)
      expect(content.slice(0, boundary)).toBe('```python\ndef foo():\n    return 1\n```\n')
      expect(content.slice(boundary)).toBe('Next line still typing')
    })

    it('treats `\\n\\n` inside a code fence as part of the code (not a paragraph break)', () => {
      // Blank lines inside a fenced block must NOT split the block.
      const content = '```\nrow1\n\nrow2\n```\n\nAfter fence'
      const boundary = findStableMarkdownBoundary(content)
      // Boundary should be after the `\n\n` following the closed fence.
      expect(content.slice(0, boundary)).toBe('```\nrow1\n\nrow2\n```\n\n')
      expect(content.slice(boundary)).toBe('After fence')
    })

    it('falls back to the previous boundary when a new fence opens but is unclosed', () => {
      const content = 'Intro paragraph.\n\n```js\nconst x =' // unclosed fence
      const boundary = findStableMarkdownBoundary(content)
      expect(content.slice(0, boundary)).toBe('Intro paragraph.\n\n')
    })

    it('returns 0 when a triple-backtick appears with no terminating newline yet', () => {
      // The fence opener arrived but `\n` after the lang tag has not.
      const content = '```py'
      const boundary = findStableMarkdownBoundary(content)
      expect(boundary).toBe(0)
    })
  })

  describe('streaming flicker scenario (regression)', () => {
    // Simulates the exact failure mode from issue #903: SSE chunks land in
    // ~80 ms bursts and the renderer must keep already-stable paragraphs
    // byte-identical so the user does not see the strobe between raw and
    // rendered markdown.
    it('keeps the stable prefix byte-identical as new tail chunks arrive', () => {
      const stable = '# Heading\n\nFirst paragraph done.\n\n'
      const chunks = ['Second', ' paragraph', ' still', ' streaming...']

      let content = stable
      const stablePrefixes: string[] = []

      for (const chunk of chunks) {
        content += chunk
        const boundary = findStableMarkdownBoundary(content)
        stablePrefixes.push(content.slice(0, boundary))
      }

      // The stable prefix returned for every intermediate chunk must be
      // EXACTLY the same — that's what makes the cache hit and the
      // already-rendered HTML stay on screen unchanged.
      expect(new Set(stablePrefixes).size).toBe(1)
      expect(stablePrefixes[0]).toBe(stable)
    })

    it('extends the stable prefix only once a fresh paragraph break arrives', () => {
      const initial = '# Heading\n\nParagraph A still streaming'
      const next = '# Heading\n\nParagraph A done.\n\nParagraph B starting'

      const boundaryA = findStableMarkdownBoundary(initial)
      const boundaryB = findStableMarkdownBoundary(next)

      expect(initial.slice(0, boundaryA)).toBe('# Heading\n\n')
      expect(next.slice(0, boundaryB)).toBe('# Heading\n\nParagraph A done.\n\n')
      expect(boundaryB).toBeGreaterThan(boundaryA)
    })
  })
})
