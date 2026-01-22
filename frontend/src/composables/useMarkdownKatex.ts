/**
 * KaTeX math rendering for markdown content
 *
 * This module provides functionality to render LaTeX math formulas
 * in markdown content using KaTeX.
 *
 * Supported syntax:
 * - Inline math: $formula$ or \(formula\)
 * - Block math: $$formula$$ or \[formula\]
 *
 * Usage:
 * 1. Call processKatexInMarkdown() before rendering markdown to convert math syntax
 * 2. Or call renderKatexBlocks() after mounting to process rendered HTML
 */

let katexModule: typeof import('katex') | null = null
let katexCssLoaded = false

/**
 * Lazy-load KaTeX and its CSS
 */
async function loadKatex(): Promise<typeof import('katex')> {
  if (katexModule) {
    return katexModule
  }

  // Load KaTeX CSS if not already loaded
  if (!katexCssLoaded && typeof document !== 'undefined') {
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = 'https://cdn.jsdelivr.net/npm/katex@0.16.11/dist/katex.min.css'
    link.crossOrigin = 'anonymous'
    document.head.appendChild(link)
    katexCssLoaded = true
  }

  // Dynamic import for code-splitting
  katexModule = await import('katex')
  return katexModule
}

/**
 * Render a single math formula to HTML
 */
async function renderMath(formula: string, displayMode: boolean): Promise<string> {
  try {
    const katex = await loadKatex()
    return katex.default.renderToString(formula, {
      displayMode,
      throwOnError: false,
      output: 'html',
      trust: false,
      strict: false,
    })
  } catch (error) {
    console.warn('KaTeX rendering failed:', error)
    // Return the original formula wrapped in a code block as fallback
    const escaped = formula.replace(/</g, '&lt;').replace(/>/g, '&gt;')
    return `<code class="katex-error">${escaped}</code>`
  }
}

/**
 * Process markdown text to render math formulas
 *
 * This function replaces $...$ and $$...$$ syntax with rendered KaTeX HTML.
 * Should be called on the raw markdown before passing to the markdown renderer.
 *
 * @param markdown - The raw markdown text
 * @returns Markdown with math formulas replaced by KaTeX HTML
 */
export async function processKatexInMarkdown(markdown: string): Promise<string> {
  if (!markdown) return markdown

  // Check if there are any math delimiters
  if (!markdown.includes('$') && !markdown.includes('\\(') && !markdown.includes('\\[')) {
    return markdown
  }

  let result = markdown

  // Process block math ($$...$$) first
  const blockMathRegex = /\$\$([\s\S]*?)\$\$/g
  const blockMatches = [...result.matchAll(blockMathRegex)]

  for (const match of blockMatches.reverse()) {
    const formula = match[1].trim()
    const rendered = await renderMath(formula, true)
    const wrapper = `<div class="katex-block">${rendered}</div>`
    result = result.slice(0, match.index!) + wrapper + result.slice(match.index! + match[0].length)
  }

  // Process LaTeX-style block math (\[...\])
  const latexBlockRegex = /\\\[([\s\S]*?)\\\]/g
  const latexBlockMatches = [...result.matchAll(latexBlockRegex)]

  for (const match of latexBlockMatches.reverse()) {
    const formula = match[1].trim()
    const rendered = await renderMath(formula, true)
    const wrapper = `<div class="katex-block">${rendered}</div>`
    result = result.slice(0, match.index!) + wrapper + result.slice(match.index! + match[0].length)
  }

  // Process inline math ($...$) - be careful not to match $$
  // Use negative lookbehind/lookahead to avoid matching $$
  const inlineMathRegex = /(?<!\$)\$(?!\$)([^$\n]+?)\$(?!\$)/g
  const inlineMatches = [...result.matchAll(inlineMathRegex)]

  for (const match of inlineMatches.reverse()) {
    const formula = match[1].trim()
    const rendered = await renderMath(formula, false)
    const wrapper = `<span class="katex-inline">${rendered}</span>`
    result = result.slice(0, match.index!) + wrapper + result.slice(match.index! + match[0].length)
  }

  // Process LaTeX-style inline math (\(...\))
  const latexInlineRegex = /\\\(([\s\S]*?)\\\)/g
  const latexInlineMatches = [...result.matchAll(latexInlineRegex)]

  for (const match of latexInlineMatches.reverse()) {
    const formula = match[1].trim()
    const rendered = await renderMath(formula, false)
    const wrapper = `<span class="katex-inline">${rendered}</span>`
    result = result.slice(0, match.index!) + wrapper + result.slice(match.index! + match[0].length)
  }

  return result
}

/**
 * Check if content contains math formulas
 */
export function hasMathFormulas(content: string): boolean {
  return (
    content.includes('$') || content.includes('\\(') || content.includes('\\[')
  )
}

/**
 * Vue composable for KaTeX rendering
 */
export function useKatex() {
  return {
    processKatexInMarkdown,
    hasMathFormulas,
    renderMath,
  }
}
