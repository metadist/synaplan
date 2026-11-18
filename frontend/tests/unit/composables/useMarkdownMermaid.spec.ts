import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Config } from 'dompurify'

const sanitize = vi.fn<(input: string, options: Config) => string>((input) => input)

vi.mock('dompurify', () => ({ default: { sanitize } }))

const { sanitizeMermaidSvg } = await import('@/composables/useMarkdownMermaid')

// happy-dom does not switch foreignObject children to the XHTML namespace the
// way browsers do, so DOMPurify's real output differs there. These tests pin
// the options; the rendered labels are checked in the browser.
describe('sanitizeMermaidSvg', () => {
  beforeEach(() => {
    sanitize.mockClear()
  })

  it('keeps foreignObject labels as an HTML integration point', () => {
    sanitizeMermaidSvg('<svg></svg>')

    const options = sanitize.mock.calls[0]?.[1]
    if (!options) throw new Error('sanitize was not called')
    expect(options.ADD_TAGS).toContain('foreignObject')
    expect(options.HTML_INTEGRATION_POINTS).toEqual({ foreignobject: true })
  })

  it('keeps the SVG and HTML profiles and forbids form controls', () => {
    sanitizeMermaidSvg('<svg></svg>')

    const options = sanitize.mock.calls[0]?.[1]
    if (!options) throw new Error('sanitize was not called')
    expect(options.USE_PROFILES).toEqual({ svg: true, svgFilters: true, html: true })
    expect(options.FORBID_TAGS).toEqual(
      expect.arrayContaining(['form', 'input', 'button', 'textarea', 'select'])
    )
  })

  it('returns the sanitized markup', () => {
    sanitize.mockReturnValueOnce('<svg>clean</svg>')

    expect(sanitizeMermaidSvg('<svg>dirty</svg>')).toBe('<svg>clean</svg>')
  })
})
