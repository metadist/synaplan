/**
 * Markdown rendering composable using marked + DOMPurify + highlight.js
 *
 * Features:
 * - GitHub Flavored Markdown (tables, task lists, strikethrough)
 * - Syntax highlighting for code blocks
 * - XSS-safe HTML output via DOMPurify
 * - Extension points for Mermaid diagrams and KaTeX math
 */
import { marked, type MarkedExtension, type Tokens } from 'marked'
import DOMPurify from 'dompurify'
import { escapeHtml, highlightCode, preloadHighlighter, ensureHighlighter } from './useHighlight'

// Custom renderer for marked
function createRenderer(): MarkedExtension {
  return {
    renderer: {
      // Custom code block rendering with syntax highlighting
      code(token: Tokens.Code): string {
        const lang = token.lang || 'text'
        const code = token.text

        // Mermaid blocks get special treatment (will be processed later)
        if (lang === 'mermaid') {
          return `<pre class="mermaid-block"><code class="language-mermaid">${escapeHtml(code)}</code></pre>`
        }

        const highlighted = highlightCode(code, lang)
        return `<pre class="code-block"><code class="hljs language-${escapeHtml(lang)}">${highlighted}</code></pre>`
      },

      // Custom inline code
      codespan(token: Tokens.Codespan): string {
        return `<code class="inline-code">${escapeHtml(token.text)}</code>`
      },

      // Custom link rendering (open external links in new tab)
      link(token: Tokens.Link): string {
        const href = token.href || ''
        const title = token.title ? ` title="${escapeHtml(token.title)}"` : ''
        let text = this.parser?.parseInline(token.tokens) || token.text

        // For mailto links, show just the email address if the text is the full mailto URL
        if (href.startsWith('mailto:') && text === href) {
          text = href.replace('mailto:', '')
        }

        // External links open in new tab
        if (href.startsWith('http://') || href.startsWith('https://')) {
          return `<a href="${escapeHtml(href)}"${title} target="_blank" rel="noopener noreferrer">${text}</a>`
        }

        return `<a href="${escapeHtml(href)}"${title}>${text}</a>`
      },

      // Custom table rendering with styling classes
      table(token: Tokens.Table): string {
        const header = token.header
          .map((cell) => {
            const content = this.parser?.parseInline(cell.tokens) || cell.text
            return `<th>${content}</th>`
          })
          .join('')

        const body = token.rows
          .map((row) => {
            const cells = row
              .map((cell) => {
                const content = this.parser?.parseInline(cell.tokens) || cell.text
                return `<td>${content}</td>`
              })
              .join('')
            return `<tr>${cells}</tr>`
          })
          .join('')

        return `<table class="markdown-table"><thead><tr>${header}</tr></thead><tbody>${body}</tbody></table>`
      },

      // Custom blockquote
      blockquote(token: Tokens.Blockquote): string {
        const body = this.parser?.parse(token.tokens) || ''
        return `<blockquote class="markdown-blockquote">${body}</blockquote>`
      },
    },
  }
}

// Configure DOMPurify for safe HTML
function sanitizeHtml(html: string): string {
  return DOMPurify.sanitize(html, {
    // Allow mermaid and KaTeX elements
    ADD_TAGS: [
      'mermaid',
      'math',
      'mrow',
      'mi',
      'mo',
      'mn',
      'msup',
      'msub',
      'mfrac',
      'semantics',
      'annotation',
    ],
    // Allow additional attributes for KaTeX math rendering
    ADD_ATTR: ['class', 'target', 'rel', 'data-*', 'aria-hidden', 'focusable', 'role', 'xmlns'],
    // Allow standard HTML elements plus KaTeX SVG elements
    ALLOWED_TAGS: [
      'h1',
      'h2',
      'h3',
      'h4',
      'h5',
      'h6',
      'p',
      'br',
      'hr',
      'strong',
      'b',
      'em',
      'i',
      'u',
      's',
      'del',
      'ins',
      'a',
      'ul',
      'ol',
      'li',
      'blockquote',
      'pre',
      'code',
      'table',
      'thead',
      'tbody',
      'tr',
      'th',
      'td',
      'img',
      'span',
      'div',
      'input', // For task list checkboxes
      'label',
      'sup',
      'sub',
      'mark', // For ==highlighted text== syntax
      'details', // For collapsible sections
      'summary', // For collapsible section headers
      'dl', // Definition list
      'dt', // Definition term
      'dd', // Definition description
      'section', // For footnotes section
      // SVG elements for KaTeX
      'svg',
      'path',
      'line',
      'rect',
      'g',
      'use',
      'defs',
      'symbol',
    ],
    ALLOWED_ATTR: [
      'href',
      'src',
      'alt',
      'title',
      'class',
      'id',
      'target',
      'rel',
      'type',
      'checked',
      'disabled',
      'open', // For <details open>
      'data-*',
      'style', // Required for KaTeX math rendering (DOMPurify sanitizes CSS)
      // SVG attributes for KaTeX
      'viewBox',
      'width',
      'height',
      'd',
      'fill',
      'stroke',
      'stroke-width',
      'transform',
      'x',
      'y',
      'x1',
      'x2',
      'y1',
      'y2',
      'xlink:href',
    ],
  })
}

// Custom extension for ==marked text== (highlight) syntax
function createMarkExtension(): MarkedExtension {
  return {
    extensions: [
      {
        name: 'mark',
        level: 'inline',
        start(src: string) {
          return src.indexOf('==')
        },
        tokenizer(src: string) {
          const rule = /^==([^=]+)==/
          const match = rule.exec(src)
          if (match) {
            return {
              type: 'mark',
              raw: match[0],
              text: match[1],
            }
          }
          return undefined
        },
        renderer(token: Tokens.Generic) {
          const text = (token as unknown as { text: string }).text || ''
          return `<mark class="markdown-mark">${escapeHtml(text)}</mark>`
        },
      },
    ],
  }
}

// Storage for footnote definitions (reset per render)
let footnoteDefinitions: Map<string, string> = new Map()

// Custom extension for footnotes [^1] and [^1]: definition
function createFootnoteExtension(): MarkedExtension {
  return {
    extensions: [
      // Footnote definition: [^1]: This is the footnote text
      {
        name: 'footnoteDefinition',
        level: 'block',
        start(src: string) {
          return src.match(/^\[\^[^\]]+\]:/)?.index
        },
        tokenizer(src: string) {
          const rule = /^\[\^([^\]]+)\]:\s*(.+?)(?:\n\n|\n(?=\[\^)|\n?$)/s
          const match = rule.exec(src)
          if (match) {
            return {
              type: 'footnoteDefinition',
              raw: match[0],
              id: match[1],
              text: match[2].trim(),
            }
          }
          return undefined
        },
        renderer(token: Tokens.Generic) {
          const t = token as unknown as { id: string; text: string }
          // Store the definition for later use
          footnoteDefinitions.set(t.id, t.text)
          // Return empty - definitions are rendered at the end
          return ''
        },
      },
      // Footnote reference: [^1]
      {
        name: 'footnoteRef',
        level: 'inline',
        start(src: string) {
          return src.match(/\[\^[^\]]+\](?!:)/)?.index
        },
        tokenizer(src: string) {
          const rule = /^\[\^([^\]]+)\](?!:)/
          const match = rule.exec(src)
          if (match) {
            return {
              type: 'footnoteRef',
              raw: match[0],
              id: match[1],
            }
          }
          return undefined
        },
        renderer(token: Tokens.Generic) {
          const t = token as unknown as { id: string }
          return `<sup class="footnote-ref"><a href="#fn-${escapeHtml(t.id)}" id="fnref-${escapeHtml(t.id)}">[${escapeHtml(t.id)}]</a></sup>`
        },
      },
    ],
  }
}

// Custom extension for definition lists (Term\n: Definition)
function createDefinitionListExtension(): MarkedExtension {
  return {
    extensions: [
      {
        name: 'defList',
        level: 'block',
        start(src: string) {
          // Look for a line followed by a line starting with ": "
          return src.match(/^[^\n]+\n: /)?.index
        },
        tokenizer(src: string) {
          // Match: Term\n: Definition (can have multiple definitions)
          const rule = /^([^\n]+)\n((?:: [^\n]+\n?)+)/
          const match = rule.exec(src)
          if (match) {
            const term = match[1].trim()
            const definitionsRaw = match[2]
            const definitions = definitionsRaw
              .split('\n')
              .filter((line) => line.startsWith(': '))
              .map((line) => line.slice(2).trim())

            return {
              type: 'defList',
              raw: match[0],
              term,
              definitions,
            }
          }
          return undefined
        },
        renderer(token: Tokens.Generic) {
          const t = token as unknown as { term: string; definitions: string[] }
          const dds = t.definitions.map((def) => `<dd>${escapeHtml(def)}</dd>`).join('')
          return `<dl class="definition-list"><dt>${escapeHtml(t.term)}</dt>${dds}</dl>`
        },
      },
    ],
  }
}

// Singleton marked instance with configuration
let markedConfigured = false

function configureMarked(): void {
  if (markedConfigured) return

  // Start loading highlight.js in the background so it is ready
  // by the time a code block needs to be rendered
  preloadHighlighter()

  marked.use(createRenderer())
  marked.use(createMarkExtension())
  marked.use(createFootnoteExtension())
  marked.use(createDefinitionListExtension())
  marked.use({
    gfm: true, // GitHub Flavored Markdown
    breaks: true, // Convert \n to <br>
  })

  markedConfigured = true
}

export interface MarkdownOptions {
  /** Enable XSS sanitization (default: true) */
  sanitize?: boolean
  /** Process special file markers from backend (default: true) */
  processFileMarkers?: boolean
  /** Process KaTeX math formulas (default: false, requires async) */
  katex?: boolean
}

export interface UseMarkdownReturn {
  /** Render markdown to safe HTML (sync) */
  render: (markdown: string, options?: MarkdownOptions) => string
  /** Render markdown to safe HTML with optional KaTeX (async) */
  renderAsync: (markdown: string, options?: MarkdownOptions) => Promise<string>
  /** Escape HTML entities */
  escapeHtml: (text: string) => string
  /** Highlight code with syntax highlighting */
  highlightCode: (code: string, language: string) => string
}

/**
 * Markdown rendering composable
 *
 * @example
 * ```ts
 * const { render } = useMarkdown()
 * const html = render('# Hello **World**')
 *
 * // With KaTeX math support (async)
 * const htmlWithMath = await renderAsync('$E = mc^2$', { katex: true })
 * ```
 */
export function useMarkdown(): UseMarkdownReturn {
  configureMarked()

  function render(markdown: string, options: MarkdownOptions = {}): string {
    const { sanitize = true, processFileMarkers = true } = options

    if (!markdown) {
      return ''
    }

    // Clear footnote definitions from previous render
    footnoteDefinitions = new Map()

    let content = markdown

    // Handle special file generation markers from backend
    if (processFileMarkers) {
      if (content.startsWith('__FILE_GENERATED__:')) {
        const filename = content.replace('__FILE_GENERATED__:', '').trim()
        content = `📄 File generated: **${filename}**`
      } else if (content === '__FILE_GENERATION_FAILED__') {
        content = '❌ File generation failed'
      }
    }

    // Parse markdown to HTML
    let html = marked.parse(content, { async: false }) as string

    // Append footnotes section if there are any
    if (footnoteDefinitions.size > 0) {
      const footnotes = Array.from(footnoteDefinitions.entries())
        .map(
          ([id, text]) =>
            `<li id="fn-${escapeHtml(id)}" class="footnote-item"><span>${escapeHtml(text)}</span> <a href="#fnref-${escapeHtml(id)}" class="footnote-backref">↩</a></li>`
        )
        .join('')
      html += `<hr class="footnotes-sep"><section class="footnotes"><ol class="footnotes-list">${footnotes}</ol></section>`
    }

    // Sanitize if enabled
    return sanitize ? sanitizeHtml(html) : html
  }

  async function renderAsync(markdown: string, options: MarkdownOptions = {}): Promise<string> {
    const { sanitize = true, processFileMarkers = true, katex = false } = options

    if (!markdown) {
      return ''
    }

    // Ensure highlight.js is available for code block rendering
    await ensureHighlighter()

    // Clear footnote definitions from previous render
    footnoteDefinitions = new Map()

    let content = markdown

    // Handle special file generation markers from backend
    if (processFileMarkers) {
      if (content.startsWith('__FILE_GENERATED__:')) {
        const filename = content.replace('__FILE_GENERATED__:', '').trim()
        content = `📄 File generated: **${filename}**`
      } else if (content === '__FILE_GENERATION_FAILED__') {
        content = '❌ File generation failed'
      }
    }

    // Process KaTeX if enabled
    if (katex) {
      const { processKatexInMarkdown } = await import('./useMarkdownKatex')
      content = await processKatexInMarkdown(content)
    }

    // Parse markdown to HTML
    let html = marked.parse(content, { async: false }) as string

    // Append footnotes section if there are any
    if (footnoteDefinitions.size > 0) {
      const footnotes = Array.from(footnoteDefinitions.entries())
        .map(
          ([id, text]) =>
            `<li id="fn-${escapeHtml(id)}" class="footnote-item"><span>${escapeHtml(text)}</span> <a href="#fnref-${escapeHtml(id)}" class="footnote-backref">↩</a></li>`
        )
        .join('')
      html += `<hr class="footnotes-sep"><section class="footnotes"><ol class="footnotes-list">${footnotes}</ol></section>`
    }

    // Sanitize if enabled
    return sanitize ? sanitizeHtml(html) : html
  }

  return {
    render,
    renderAsync,
    escapeHtml,
    highlightCode,
  }
}

// Export singleton for widget usage (avoids re-initialization)
let singletonInstance: UseMarkdownReturn | null = null

export function getMarkdownRenderer(): UseMarkdownReturn {
  if (!singletonInstance) {
    singletonInstance = useMarkdown()
  }
  return singletonInstance
}
