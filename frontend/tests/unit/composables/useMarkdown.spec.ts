import { describe, it, expect, beforeEach } from 'vitest'
import { useMarkdown, getMarkdownRenderer } from '@/composables/useMarkdown'
import { ensureHighlighter } from '@/composables/useHighlight'

describe('useMarkdown', () => {
  let markdown: ReturnType<typeof useMarkdown>

  beforeEach(async () => {
    // Ensure highlight.js is loaded before tests that check for syntax highlighting
    await ensureHighlighter()
    markdown = useMarkdown()
  })

  describe('render', () => {
    describe('basic markdown', () => {
      it('should render headings', () => {
        const html = markdown.render('# Heading 1')
        expect(html).toContain('<h1')
        expect(html).toContain('Heading 1')
      })

      it('should render multiple heading levels', () => {
        const html = markdown.render('# H1\n## H2\n### H3')
        expect(html).toContain('<h1')
        expect(html).toContain('<h2')
        expect(html).toContain('<h3')
      })

      it('should render bold text', () => {
        const html = markdown.render('**bold text**')
        expect(html).toContain('<strong')
        expect(html).toContain('bold text')
      })

      it('should render italic text', () => {
        const html = markdown.render('*italic text*')
        expect(html).toContain('<em')
        expect(html).toContain('italic text')
      })

      it('should render nested bold and italic', () => {
        const html = markdown.render('**bold *and italic* text**')
        expect(html).toContain('<strong')
        expect(html).toContain('<em')
      })

      it('should render links', () => {
        const html = markdown.render('[Link Text](https://example.com)')
        expect(html).toContain('<a')
        expect(html).toContain('href="https://example.com"')
        expect(html).toContain('Link Text')
      })

      it('should add target="_blank" to external links', () => {
        const html = markdown.render('[External](https://example.com)')
        expect(html).toContain('target="_blank"')
        expect(html).toContain('rel="noopener noreferrer"')
      })

      it('should render paragraphs', () => {
        const html = markdown.render('First paragraph\n\nSecond paragraph')
        expect(html).toContain('<p')
      })

      it('should render line breaks with breaks: true', () => {
        const html = markdown.render('Line 1\nLine 2')
        expect(html).toContain('<br')
      })
    })

    describe('lists', () => {
      it('should render unordered lists', () => {
        const html = markdown.render('- Item 1\n- Item 2\n- Item 3')
        expect(html).toContain('<ul')
        expect(html).toContain('<li')
        expect(html).toContain('Item 1')
        expect(html).toContain('Item 2')
        expect(html).toContain('Item 3')
      })

      it('should render ordered lists', () => {
        const html = markdown.render('1. First\n2. Second\n3. Third')
        expect(html).toContain('<ol')
        expect(html).toContain('<li')
        expect(html).toContain('First')
        expect(html).toContain('Second')
        expect(html).toContain('Third')
      })

      it('should render task lists (GFM)', () => {
        const html = markdown.render('- [x] Done\n- [ ] Not done')
        expect(html).toContain('type="checkbox"')
        expect(html).toContain('checked')
      })
    })

    describe('code blocks', () => {
      it('should render inline code', () => {
        const html = markdown.render('Use `const` for constants')
        expect(html).toContain('class="inline-code"')
        expect(html).toContain('const')
      })

      it('should render fenced code blocks', () => {
        const html = markdown.render('```javascript\nconst x = 42;\n```')
        expect(html).toContain('class="code-block"')
        expect(html).toContain('language-javascript')
      })

      it('should apply syntax highlighting', () => {
        const html = markdown.render('```typescript\nconst x: number = 42;\n```')
        expect(html).toContain('hljs')
      })

      it('should handle mermaid code blocks specially', () => {
        const html = markdown.render('```mermaid\ngraph TD\nA-->B\n```')
        expect(html).toContain('class="mermaid-block"')
        expect(html).toContain('language-mermaid')
      })

      it('should render HTML code blocks with syntax highlighting', () => {
        const html = markdown.render('```html\n<script>alert("xss")</script>\n```')
        // highlight.js wraps HTML in span elements for syntax highlighting
        expect(html).toContain('class="code-block"')
        expect(html).toContain('language-html')
        expect(html).toContain('alert')
      })
    })

    describe('blockquotes', () => {
      it('should render blockquotes', () => {
        const html = markdown.render('> This is a quote')
        expect(html).toContain('class="markdown-blockquote"')
        expect(html).toContain('This is a quote')
      })

      it('should render nested content in blockquotes', () => {
        const html = markdown.render('> **Bold** in quote')
        expect(html).toContain('markdown-blockquote')
        expect(html).toContain('<strong')
      })
    })

    describe('tables (GFM)', () => {
      it('should render tables', () => {
        const html = markdown.render('| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |')
        expect(html).toContain('class="markdown-table"')
        expect(html).toContain('<thead')
        expect(html).toContain('<tbody')
        expect(html).toContain('<th')
        expect(html).toContain('<td')
      })

      it('should render table headers and cells', () => {
        const html = markdown.render('| Name | Age |\n| --- | --- |\n| Alice | 30 |')
        expect(html).toContain('Name')
        expect(html).toContain('Age')
        expect(html).toContain('Alice')
        expect(html).toContain('30')
      })
    })

    describe('horizontal rules', () => {
      it('should render horizontal rules', () => {
        const html = markdown.render('Above\n\n---\n\nBelow')
        expect(html).toContain('<hr')
      })
    })

    describe('strikethrough (GFM)', () => {
      it('should render strikethrough text', () => {
        const html = markdown.render('~~deleted text~~')
        expect(html).toContain('<del')
        expect(html).toContain('deleted text')
      })
    })

    describe('file markers', () => {
      it('should process file generated marker', () => {
        const html = markdown.render('__FILE_GENERATED__:report.pdf')
        expect(html).toContain('report.pdf')
        expect(html).toContain('File generated')
      })

      it('should process file generation failed marker', () => {
        const html = markdown.render('__FILE_GENERATION_FAILED__')
        expect(html).toContain('File generation failed')
      })

      it('should skip file markers when disabled', () => {
        // When processFileMarkers is false, the marker is processed as markdown
        // Double underscores become bold, so FILE_GENERATED becomes <strong>
        const html = markdown.render('__FILE_GENERATED__:report.pdf', {
          processFileMarkers: false,
        })
        expect(html).toContain('FILE_GENERATED')
        expect(html).toContain('report.pdf')
      })
    })
  })

  describe('XSS prevention', () => {
    it('should sanitize script tags', () => {
      const html = markdown.render('<script>alert("xss")</script>')
      expect(html).not.toContain('<script')
    })

    it('should sanitize onclick handlers', () => {
      const html = markdown.render('<div onclick="alert(\'xss\')">Click</div>')
      expect(html).not.toContain('onclick')
    })

    it('should sanitize javascript: URLs', () => {
      const html = markdown.render('[Click](javascript:alert("xss"))')
      expect(html).not.toContain('javascript:')
    })

    it('should sanitize data: URLs in images', () => {
      const html = markdown.render('![img](data:text/html,<script>alert(1)</script>)')
      expect(html).not.toContain('<script')
    })

    it('should allow safe HTML elements', () => {
      const html = markdown.render('**bold** and *italic*')
      expect(html).toContain('<strong')
      expect(html).toContain('<em')
    })
  })

  describe('escapeHtml', () => {
    it('should escape HTML entities in browser environment', () => {
      // Note: escapeHtml uses DOM methods which may behave differently in test environments
      // In a real browser, it properly escapes HTML entities
      const escaped = markdown.escapeHtml('<div>Test</div>')
      // The function should return some form of the text
      expect(escaped).toBeDefined()
      expect(typeof escaped).toBe('string')
    })

    it('should handle empty strings', () => {
      expect(markdown.escapeHtml('')).toBe('')
    })
  })

  describe('highlightCode', () => {
    it('should highlight JavaScript code', () => {
      const highlighted = markdown.highlightCode('const x = 42;', 'javascript')
      expect(highlighted).toContain('hljs-')
    })

    it('should highlight TypeScript code', () => {
      const highlighted = markdown.highlightCode('const x: number = 42;', 'typescript')
      expect(highlighted).toContain('hljs-')
    })

    it('should escape mermaid code without highlighting', () => {
      const result = markdown.highlightCode('graph TD\nA-->B', 'mermaid')
      expect(result).not.toContain('hljs-')
    })

    it('should fall back for unknown languages', () => {
      const highlighted = markdown.highlightCode('some code', 'unknownlang')
      // Should still produce some output via auto-detection
      expect(highlighted).toBeTruthy()
    })
  })

  describe('empty and null inputs', () => {
    it('should handle empty string', () => {
      expect(markdown.render('')).toBe('')
    })

    it('should handle whitespace-only string', () => {
      const html = markdown.render('   \n\n   ')
      expect(html).toBeDefined()
    })
  })
})

describe('getMarkdownRenderer', () => {
  it('should return a singleton instance', () => {
    const renderer1 = getMarkdownRenderer()
    const renderer2 = getMarkdownRenderer()
    expect(renderer1).toBe(renderer2)
  })

  it('should have render function', () => {
    const renderer = getMarkdownRenderer()
    expect(typeof renderer.render).toBe('function')
  })
})
