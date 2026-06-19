import { describe, it, expect } from 'vitest'
import { completeInlineMarkdown } from '@/utils/partialMarkdown'

describe('completeInlineMarkdown', () => {
  describe('completed markup is left untouched', () => {
    it('keeps balanced bold unchanged', () => {
      expect(completeInlineMarkdown('**bold**')).toBe('**bold**')
    })

    it('keeps balanced italic, strike and inline code unchanged', () => {
      expect(completeInlineMarkdown('*italic*')).toBe('*italic*')
      expect(completeInlineMarkdown('~~gone~~')).toBe('~~gone~~')
      expect(completeInlineMarkdown('`code`')).toBe('`code`')
      expect(completeInlineMarkdown('_under_')).toBe('_under_')
    })

    it('keeps a fully typed paragraph with multiple closed spans unchanged', () => {
      const input = 'This is **bold** and *italic* and `code` done.'
      expect(completeInlineMarkdown(input)).toBe(input)
    })

    it('returns empty input unchanged', () => {
      expect(completeInlineMarkdown('')).toBe('')
    })
  })

  describe('open inline markers are closed', () => {
    it('closes an open bold marker', () => {
      expect(completeInlineMarkdown('**blabla')).toBe('**blabla**')
    })

    it('preserves an earlier closed bold while closing the new open one', () => {
      expect(completeInlineMarkdown('**done** and **start')).toBe('**done** and **start**')
    })

    it('closes an open italic marker', () => {
      expect(completeInlineMarkdown('*blabla')).toBe('*blabla*')
    })

    it('closes an open underscore italic marker', () => {
      expect(completeInlineMarkdown('_blabla')).toBe('_blabla_')
    })

    it('closes an open strikethrough marker', () => {
      expect(completeInlineMarkdown('~~blabla')).toBe('~~blabla~~')
    })

    it('closes an open inline code span', () => {
      expect(completeInlineMarkdown('here is `code')).toBe('here is `code`')
    })

    it('closes a multi-backtick inline code span with the same run length', () => {
      expect(completeInlineMarkdown('here is ``co`de')).toBe('here is ``co`de``')
    })

    it('closes nested emphasis innermost-first', () => {
      expect(completeInlineMarkdown('**bold and *italic')).toBe('**bold and *italic***')
    })

    it('closes bold that wraps an inline code span still open', () => {
      expect(completeInlineMarkdown('**bold `code')).toBe('**bold `code`**')
    })
  })

  // Regression: a closer must never be appended AFTER trailing whitespace,
  // otherwise CommonMark right-flanking fails and marked renders raw `**`.
  describe('trailing whitespace (closer goes before the space)', () => {
    it('closes bold when the fragment ends with a space', () => {
      expect(completeInlineMarkdown('**Was ')).toBe('**Was** ')
    })

    it('closes bold when several words are followed by a trailing space', () => {
      expect(completeInlineMarkdown('**Was du ')).toBe('**Was du** ')
    })

    it('closes italic before a trailing space', () => {
      expect(completeInlineMarkdown('*italic ')).toBe('*italic* ')
    })

    it('closes strikethrough before a trailing space', () => {
      expect(completeInlineMarkdown('~~strike ')).toBe('~~strike~~ ')
    })

    it('only closes the in-progress span, keeping earlier closed spans intact', () => {
      expect(completeInlineMarkdown('a **b** c **d ')).toBe('a **b** c **d** ')
    })

    it('preserves a trailing newline while placing the closer correctly', () => {
      expect(completeInlineMarkdown('**done**\n**new ')).toBe('**done**\n**new** ')
    })
  })

  // Regression: a half-arrived closer (only one `*` of `**`) must not be
  // turned into italic — this was the "shows italic instead of bold" bug.
  describe('half-typed closing markers', () => {
    it('treats a single trailing asterisk as a partial bold closer', () => {
      expect(completeInlineMarkdown('**bold*')).toBe('**bold**')
    })

    it('handles a partial bold closer followed by a space', () => {
      expect(completeInlineMarkdown('**bold* ')).toBe('**bold** ')
    })

    it('handles a fresh italic opener inside bold that has no content yet', () => {
      expect(completeInlineMarkdown('**bold *')).toBe('**bold** ')
    })
  })

  describe('dangling opener with no content is hidden', () => {
    it('hides a trailing bold opener until content arrives', () => {
      expect(completeInlineMarkdown('Das Feature heißt **')).toBe('Das Feature heißt ')
    })

    it('hides a trailing italic opener after a closed bold span', () => {
      expect(completeInlineMarkdown('text **bold** and more *')).toBe('text **bold** and more ')
    })
  })

  describe('false positives are NOT treated as markdown', () => {
    it('does not close a spaced asterisk (multiplication)', () => {
      expect(completeInlineMarkdown('2 * 3')).toBe('2 * 3')
    })

    it('does not close a bullet list marker', () => {
      expect(completeInlineMarkdown('* item')).toBe('* item')
    })

    it('does not treat a dash bullet as a marker', () => {
      expect(completeInlineMarkdown('- item')).toBe('- item')
    })

    it('does not treat underscores in snake_case as emphasis', () => {
      expect(completeInlineMarkdown('call some_function_name')).toBe('call some_function_name')
    })

    it('hides a lone trailing asterisk (in-progress marker, no content yet)', () => {
      expect(completeInlineMarkdown('a list: *')).toBe('a list: ')
    })

    it('ignores a single tilde (not strikethrough)', () => {
      expect(completeInlineMarkdown('about ~10 items')).toBe('about ~10 items')
    })

    it('does not touch markup inside a closed inline code span', () => {
      expect(completeInlineMarkdown('use `**not bold**` here')).toBe('use `**not bold**` here')
    })
  })

  describe('incomplete links and images are hidden', () => {
    it('shows only the label when the bracket is not closed', () => {
      expect(completeInlineMarkdown('see [the docs')).toBe('see the docs')
    })

    it('hides a link whose destination is still being typed', () => {
      expect(completeInlineMarkdown('see [the docs](https://exa')).toBe('see ')
    })

    it('hides an image whose destination is still being typed', () => {
      expect(completeInlineMarkdown('look ![alt](https://exa')).toBe('look ')
    })

    it('shows the alt text when an image bracket is not closed', () => {
      expect(completeInlineMarkdown('look ![alt text')).toBe('look alt text')
    })

    it('keeps a fully formed link untouched', () => {
      const input = 'see [the docs](https://example.com) now'
      expect(completeInlineMarkdown(input)).toBe(input)
    })

    it('does not mistake a completed bracket pair for an incomplete link', () => {
      expect(completeInlineMarkdown('array[0] = 1')).toBe('array[0] = 1')
    })
  })
})
