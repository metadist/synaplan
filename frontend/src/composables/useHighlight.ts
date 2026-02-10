/**
 * Shared highlight.js composable with lazy loading
 *
 * Provides syntax highlighting functionality that is loaded on demand,
 * keeping highlight.js out of the main bundle. Follows the same dynamic
 * import pattern as useMarkdownMermaid.ts.
 *
 * Usage:
 *   import { preloadHighlighter, highlightCode, ensureHighlighter } from './useHighlight'
 *
 *   // Start loading early (fire-and-forget)
 *   preloadHighlighter()
 *
 *   // Sync highlight (returns escaped HTML if not yet loaded)
 *   const html = highlightCode(code, 'typescript')
 *
 *   // Async: guarantee highlighting is available
 *   await ensureHighlighter()
 *   const html = highlightCode(code, 'typescript')
 */

import type { HLJSApi } from 'highlight.js'

let hljsInstance: HLJSApi | null = null
let loadPromise: Promise<HLJSApi> | null = null

/**
 * Escape HTML entities for safe rendering
 */
export function escapeHtml(text: string): string {
  const div = document.createElement('div')
  div.textContent = text
  return div.innerHTML
}

/**
 * Load highlight.js core, CSS theme, and all supported languages
 */
async function doLoad(): Promise<HLJSApi> {
  try {
    const [
      ,
      // CSS side-effect import (no export)
      { default: hljs },
      { default: javascript },
      { default: typescript },
      { default: python },
      { default: java },
      { default: cpp },
      { default: csharp },
      { default: php },
      { default: ruby },
      { default: go },
      { default: rust },
      { default: sql },
      { default: bash },
      { default: json },
      { default: xml },
      { default: cssLang },
      { default: yaml },
      { default: markdown },
      { default: plaintext },
    ] = await Promise.all([
      import('highlight.js/styles/atom-one-dark.css'),
      import('highlight.js/lib/core'),
      import('highlight.js/lib/languages/javascript'),
      import('highlight.js/lib/languages/typescript'),
      import('highlight.js/lib/languages/python'),
      import('highlight.js/lib/languages/java'),
      import('highlight.js/lib/languages/cpp'),
      import('highlight.js/lib/languages/csharp'),
      import('highlight.js/lib/languages/php'),
      import('highlight.js/lib/languages/ruby'),
      import('highlight.js/lib/languages/go'),
      import('highlight.js/lib/languages/rust'),
      import('highlight.js/lib/languages/sql'),
      import('highlight.js/lib/languages/bash'),
      import('highlight.js/lib/languages/json'),
      import('highlight.js/lib/languages/xml'),
      import('highlight.js/lib/languages/css'),
      import('highlight.js/lib/languages/yaml'),
      import('highlight.js/lib/languages/markdown'),
      import('highlight.js/lib/languages/plaintext'),
    ])

    // Register primary language names
    hljs.registerLanguage('javascript', javascript)
    hljs.registerLanguage('typescript', typescript)
    hljs.registerLanguage('python', python)
    hljs.registerLanguage('java', java)
    hljs.registerLanguage('cpp', cpp)
    hljs.registerLanguage('csharp', csharp)
    hljs.registerLanguage('php', php)
    hljs.registerLanguage('ruby', ruby)
    hljs.registerLanguage('go', go)
    hljs.registerLanguage('rust', rust)
    hljs.registerLanguage('sql', sql)
    hljs.registerLanguage('bash', bash)
    hljs.registerLanguage('json', json)
    hljs.registerLanguage('xml', xml)
    hljs.registerLanguage('css', cssLang)
    hljs.registerLanguage('yaml', yaml)
    hljs.registerLanguage('markdown', markdown)
    hljs.registerLanguage('plaintext', plaintext)

    // Register common aliases
    hljs.registerLanguage('js', javascript)
    hljs.registerLanguage('ts', typescript)
    hljs.registerLanguage('py', python)
    hljs.registerLanguage('c++', cpp)
    hljs.registerLanguage('c#', csharp)
    hljs.registerLanguage('rb', ruby)
    hljs.registerLanguage('golang', go)
    hljs.registerLanguage('rs', rust)
    hljs.registerLanguage('shell', bash)
    hljs.registerLanguage('sh', bash)
    hljs.registerLanguage('zsh', bash)
    hljs.registerLanguage('html', xml)
    hljs.registerLanguage('yml', yaml)
    hljs.registerLanguage('md', markdown)
    hljs.registerLanguage('text', plaintext)
    hljs.registerLanguage('plain', plaintext)

    hljsInstance = hljs
    return hljs
  } catch (error) {
    // Allow retry on failure
    loadPromise = null
    throw error
  }
}

/**
 * Start loading highlight.js in the background (fire-and-forget).
 * Call this early (e.g. when the chat view initializes) so that
 * hljs is ready by the time a code block needs to be rendered.
 */
export function preloadHighlighter(): void {
  if (!loadPromise && !hljsInstance) {
    loadPromise = doLoad()
  }
}

/**
 * Ensure highlight.js is fully loaded.
 * Returns the hljs instance once available.
 */
export async function ensureHighlighter(): Promise<HLJSApi> {
  if (hljsInstance) return hljsInstance
  if (!loadPromise) loadPromise = doLoad()
  return loadPromise
}

/**
 * Get the highlight.js instance synchronously.
 * Returns null if not yet loaded.
 */
export function getHighlighter(): HLJSApi | null {
  return hljsInstance
}

/**
 * Highlight code synchronously.
 *
 * If highlight.js is not yet loaded, returns escaped HTML as a graceful
 * fallback. For guaranteed highlighting, call ensureHighlighter() first.
 */
export function highlightCode(code: string, language: string): string {
  if (!hljsInstance) {
    return escapeHtml(code)
  }

  const lang = language.toLowerCase()

  // Mermaid code blocks are handled separately by useMarkdownMermaid
  if (lang === 'mermaid') {
    return escapeHtml(code)
  }

  try {
    if (lang && hljsInstance.getLanguage(lang)) {
      return hljsInstance.highlight(code, { language: lang, ignoreIllegals: true }).value
    }
    // Auto-detect for unknown or empty language
    return hljsInstance.highlightAuto(code).value
  } catch {
    return escapeHtml(code)
  }
}
