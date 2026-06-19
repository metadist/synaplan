/**
 * Mermaid diagram rendering for markdown content
 *
 * This module provides functionality to render mermaid diagrams
 * that were parsed as code blocks by the markdown renderer.
 *
 * Usage:
 * 1. Render markdown with useMarkdown()
 * 2. Mount the HTML to the DOM
 * 3. Call renderMermaidBlocks() to convert mermaid code blocks to SVG diagrams
 */

import DOMPurify from 'dompurify'

let mermaidInitialized = false
let mermaidModule: typeof import('mermaid') | null = null

/**
 * Sanitize a mermaid-rendered SVG before it is injected via innerHTML.
 *
 * Mermaid already sanitizes with `securityLevel: 'strict'`, but assigning the
 * raw string to innerHTML is flagged by static analysis (CodeQL: "DOM text
 * reinterpreted as HTML"). This is defense-in-depth: DOMPurify with the SVG
 * (+ SVG filters + HTML for foreignObject labels) profiles strips scripts and
 * event handlers while preserving everything mermaid needs to render.
 */
function sanitizeSvg(svg: string): string {
  return DOMPurify.sanitize(svg, {
    USE_PROFILES: { svg: true, svgFilters: true, html: true },
  })
}

/**
 * Lazy-load and initialize mermaid
 */
async function initMermaid(): Promise<typeof import('mermaid')> {
  if (mermaidModule && mermaidInitialized) {
    return mermaidModule
  }

  // Dynamic import for code-splitting
  mermaidModule = await import('mermaid')
  const mermaid = mermaidModule.default

  if (!mermaidInitialized) {
    mermaid.initialize({
      startOnLoad: false,
      theme: 'default',
      securityLevel: 'strict',
      fontFamily: 'inherit',
      suppressErrorRendering: true, // Don't render error messages in DOM
    })
    mermaidInitialized = true
  }

  return mermaidModule
}

/**
 * Check if mermaid diagram code looks complete (not still being streamed).
 * During streaming, brackets/braces might be unbalanced.
 */
function isDiagramCodeComplete(code: string): boolean {
  const trimmed = code.trim()

  // Must have at least a diagram type declaration
  if (
    !trimmed.match(
      /^(flowchart|graph|sequenceDiagram|classDiagram|stateDiagram|erDiagram|journey|gantt|pie|quadrantChart|requirementDiagram|gitGraph|mindmap|timeline|zenuml|sankey|xychart)/i
    )
  ) {
    return false
  }

  // Count brackets - they should be balanced
  let squareBrackets = 0
  let curlyBraces = 0
  let parentheses = 0

  for (const char of trimmed) {
    switch (char) {
      case '[':
        squareBrackets++
        break
      case ']':
        squareBrackets--
        break
      case '{':
        curlyBraces++
        break
      case '}':
        curlyBraces--
        break
      case '(':
        parentheses++
        break
      case ')':
        parentheses--
        break
    }
  }

  // All brackets should be balanced for a complete diagram
  return squareBrackets === 0 && curlyBraces === 0 && parentheses === 0
}

/**
 * Render all mermaid code blocks within a container
 *
 * Finds all `<pre class="mermaid-block">` elements and converts them
 * to rendered SVG diagrams.
 *
 * @param container - The DOM element containing markdown content
 * @param theme - Optional theme ('light' or 'dark')
 * @param inPlace - When `false` (default) each `<pre class="mermaid-block">` is
 *   REPLACED by a new `<div>` holding the SVG. When `true` the SVG is injected
 *   INTO the existing `<pre>` (then marked `mermaid-rendered`), keeping element
 *   identity stable so a DOM-morphing renderer (morphdom) can protect the
 *   rendered diagram from being reverted on the next streaming patch.
 */
export async function renderMermaidBlocks(
  container: HTMLElement,
  theme: 'light' | 'dark' = 'light',
  inPlace: boolean = false
): Promise<void> {
  const mermaidBlocks = container.querySelectorAll('pre.mermaid-block')

  if (mermaidBlocks.length === 0) {
    return
  }

  try {
    const { default: mermaid } = await initMermaid()

    // Update theme if needed
    mermaid.initialize({
      startOnLoad: false,
      theme: theme === 'dark' ? 'dark' : 'default',
      securityLevel: 'strict',
      fontFamily: 'inherit',
      suppressErrorRendering: true, // Don't render error messages in DOM
    })

    for (const block of mermaidBlocks) {
      const codeElement = block.querySelector('code.language-mermaid')
      if (!codeElement) continue

      const diagramCode = codeElement.textContent || ''
      if (!diagramCode.trim()) continue

      // Skip incomplete diagrams (still being streamed)
      if (!isDiagramCodeComplete(diagramCode)) {
        continue
      }

      // Skip if already processed (has error or is rendered)
      if (
        block.classList.contains('mermaid-error') ||
        block.classList.contains('mermaid-rendered')
      ) {
        continue
      }

      try {
        const id = `mermaid-${Math.random().toString(36).slice(2, 11)}`
        const { svg } = await mermaid.render(id, diagramCode)
        const safeSvg = sanitizeSvg(svg)

        if (inPlace) {
          // Keep the <pre> element (stable identity for morphdom) and swap its
          // code child for the rendered SVG.
          block.classList.add('mermaid-rendered')
          block.innerHTML = safeSvg
        } else {
          // Create a container for the rendered diagram
          const diagramContainer = document.createElement('div')
          diagramContainer.className = 'mermaid-diagram my-4 overflow-x-auto'
          diagramContainer.innerHTML = safeSvg

          // Replace the code block with the rendered diagram
          block.replaceWith(diagramContainer)
        }
      } catch {
        // Mark as error to prevent re-rendering attempts
        block.classList.add('mermaid-error')

        // Add error indicator
        const errorBanner = document.createElement('div')
        errorBanner.className = 'text-xs text-red-500 mb-2'
        errorBanner.textContent = 'Failed to render diagram'
        block.insertBefore(errorBanner, block.firstChild)
      }
    }
  } catch {
    // Failed to load mermaid library - silently ignore
  }
}

/**
 * Check if content contains mermaid blocks
 *
 * Useful to determine if mermaid should be loaded at all.
 */
export function hasMermaidBlocks(container: HTMLElement): boolean {
  return container.querySelectorAll('pre.mermaid-block').length > 0
}

/**
 * Vue composable for mermaid rendering
 */
export function useMermaid() {
  return {
    renderMermaidBlocks,
    hasMermaidBlocks,
  }
}
