import { describe, expect, it } from 'vitest'
import { adaptStylesForShadowDom } from '@/utils/widgetShadowStyles'

// Mirrors the shape Tailwind emits for the app's `@layer base` block.
const compiledBaseCss = [
  'html,body{overscroll-behavior:none;height:100%;overflow:hidden}',
  'body{font-size:var(--text-base);background:var(--bg-app);margin:0}',
  ':root{--bg-app:#f2f2f2;--txt-primary:#000}',
  '.dark{--bg-app:#0a0e1a;--txt-primary:#fff}',
].join('')

describe('adaptStylesForShadowDom', () => {
  it('moves the design tokens from :root onto :host', () => {
    const adapted = adaptStylesForShadowDom(compiledBaseCss)

    expect(adapted).toContain(':host{--bg-app:#f2f2f2;--txt-primary:#000}')
    expect(adapted).not.toContain(':root')
  })

  it('leaves the dark theme token block untouched', () => {
    const adapted = adaptStylesForShadowDom(compiledBaseCss)

    expect(adapted).toContain('.dark{--bg-app:#0a0e1a;--txt-primary:#fff}')
  })

  it('never gives the widget host a background (issue #1450)', () => {
    const adapted = adaptStylesForShadowDom(compiledBaseCss)

    expect(adapted).toContain('body{font-size:var(--text-base);background:var(--bg-app);margin:0}')
    expect(adapted).not.toMatch(/:host\{[^}]*background/)
  })

  it('keeps document-level sizing and overflow rules off the host', () => {
    const adapted = adaptStylesForShadowDom(compiledBaseCss)

    expect(adapted).toContain('html,body{overscroll-behavior:none;height:100%;overflow:hidden}')
  })

  it('does not rewrite selectors that merely contain the word body', () => {
    const adapted = adaptStylesForShadowDom('.accent-body{width:0}tbody{display:table-row-group}')

    expect(adapted).toBe('.accent-body{width:0}tbody{display:table-row-group}')
  })
})
