import { describe, it, expect } from 'vitest'
import { hasMathFormulas, processKatexInMarkdown } from '@/composables/useMarkdownKatex'

// Regression (issue #903): currency like "19 $/Monat … 39 $" must NOT be
// detected/rendered as a math formula. The naive `$…$` matcher used to eat
// both dollar signs and render everything between them (incl. **bold**) as a
// KaTeX formula in math italic.

describe('hasMathFormulas', () => {
  it('does NOT treat currency with two dollar signs as math', () => {
    const text =
      'Copilot Business (ca. 19 $/Nutzer/Monat) und Copilot Enterprise (ca. 39 $/Nutzer/Monat).'
    expect(hasMathFormulas(text)).toBe(false)
  })

  it('does NOT treat a single lone dollar sign as math', () => {
    expect(hasMathFormulas('Der Preis ist 5 $ pro Monat')).toBe(false)
  })

  it('does NOT treat space-padded dollars as math', () => {
    expect(hasMathFormulas('kostet 5 $ und spart 10 $')).toBe(false)
  })

  it('detects a real inline formula $x^2$', () => {
    expect(hasMathFormulas('Die Formel $x^2$ ist einfach')).toBe(true)
  })

  it('detects a single-char inline formula $x$', () => {
    expect(hasMathFormulas('Sei $x$ gegeben')).toBe(true)
  })

  it('detects block math $$…$$', () => {
    expect(hasMathFormulas('$$a^2 + b^2 = c^2$$')).toBe(true)
  })

  it('detects LaTeX inline \\(…\\) and block \\[…\\]', () => {
    expect(hasMathFormulas('Sei \\(x\\) gegeben')).toBe(true)
    expect(hasMathFormulas('\\[ E = mc^2 \\]')).toBe(true)
  })

  it('returns false for plain text', () => {
    expect(hasMathFormulas('Einfach nur Text ohne alles')).toBe(false)
    expect(hasMathFormulas('')).toBe(false)
  })

  it('does NOT treat empty delimiters as math (consistent with the renderer)', () => {
    expect(hasMathFormulas('$$$$')).toBe(false)
    expect(hasMathFormulas('\\(\\)')).toBe(false)
    expect(hasMathFormulas('\\[\\]')).toBe(false)
  })
})

describe('processKatexInMarkdown', () => {
  it('leaves currency untouched (no KaTeX span, dollars preserved)', async () => {
    const text =
      '**Copilot Business** (ca. 19 $/Nutzer/Monat) und **Copilot Enterprise** (ca. 39 $/Nutzer/Monat).'
    const result = await processKatexInMarkdown(text)
    expect(result).toBe(text)
    expect(result).not.toContain('katex')
  })

  it('leaves empty delimiters untouched (no empty formula rendered)', async () => {
    expect(await processKatexInMarkdown('Leeres $$$$ hier')).toBe('Leeres $$$$ hier')
    expect(await processKatexInMarkdown('Leeres \\(\\) hier')).toBe('Leeres \\(\\) hier')
  })
})
