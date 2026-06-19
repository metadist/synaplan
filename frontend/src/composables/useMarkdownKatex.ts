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
 *
 * Both CSS and JS are loaded dynamically on first use to keep the main bundle small.
 */

let katexModule: typeof import('katex') | null = null

// Strict inline-math delimiter pattern: `$…$` whose content has NO whitespace
// directly inside the delimiters (standard remark-math/pandoc rule). This is
// what keeps currency such as "19 $/Monat … 39 $" from being parsed as a
// formula. Single-char formulas (`$x$`) are allowed via the alternation; `$$`
// is excluded by the negative lookaheads. Kept as a source string so both the
// matcher (global flag) and the detector (`hasMathFormulas`, stateless `.test`)
// build their own fresh RegExp and never share `lastIndex`.
const INLINE_MATH_SOURCE = '\\$(?!\\$)((?:[^\\s$][^$\\n]*?[^\\s$]|[^\\s$]))\\$(?!\\$)'

/**
 * Lazy-load KaTeX module and CSS together
 */
async function loadKatex(): Promise<typeof import('katex')> {
  if (katexModule) {
    return katexModule
  }

  // Dynamic import for code-splitting — load CSS and JS in parallel
  const [, katex] = await Promise.all([import('katex/dist/katex.min.css'), import('katex')])

  katexModule = katex
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
  // Avoid lookbehind for Safari compatibility; filter $$ matches manually.
  //
  // Currency guard (issue #903): a valid inline formula must NOT have
  // whitespace directly inside the delimiters (standard remark-math/pandoc
  // rule). Without this, "19 $/Monat … 39 $" matched as a formula, eating
  // both `$` and rendering the text between (incl. **bold**) in math italic.
  // The content therefore must start and end with a non-space, non-`$` char.
  const inlineMathRegex = new RegExp(INLINE_MATH_SOURCE, 'g')
  const inlineMatches = [...result.matchAll(inlineMathRegex)]

  for (const match of inlineMatches.reverse()) {
    const startIndex = match.index ?? -1
    // Emulate negative lookbehind: skip if preceded by '$' (part of '$$')
    if (startIndex > 0 && result[startIndex - 1] === '$') {
      continue
    }
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
 * Check if content contains REAL math formulas.
 *
 * Must mirror what `processKatexInMarkdown` actually renders, otherwise the
 * streaming renderer flags currency-only text ("19 $/Monat") as math and runs
 * the (formula-mangling, raw-markdown) KaTeX path on it. We therefore require
 * a complete, well-formed delimiter pair — not just a lone `$`.
 */
export function hasMathFormulas(content: string): boolean {
  if (!content) return false
  // Block math $$…$$
  if (/\$\$[\s\S]+?\$\$/.test(content)) return true
  // LaTeX block \[ … \]
  if (/\\\[[\s\S]+?\\\]/.test(content)) return true
  // LaTeX inline \( … \)
  if (/\\\([\s\S]+?\\\)/.test(content)) return true
  // Inline $…$ with the strict currency-safe rule
  return new RegExp(INLINE_MATH_SOURCE).test(content)
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
