import { describe, expect, it } from 'vitest'
import { artifactSrcDoc, extractArtifacts, sanitizeSvg } from '@/utils/chatArtifacts'

describe('extractArtifacts', () => {
  it('keeps a complete HTML document and an SVG, and leaves mermaid alone', () => {
    const text = [
      '```html',
      '<!DOCTYPE html><html><body><h1>Hi</h1></body></html>',
      '```',
      '```mermaid',
      'graph TD; A-->B;',
      '```',
      '```svg',
      '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>',
      '```',
      '```html',
      '<p>fragment</p>',
      '```',
    ].join('\n')

    const found = extractArtifacts(text)
    expect(found.map((item) => item.language)).toEqual(['html', 'svg'])
  })

  it('strips script and external urls from svg', () => {
    const clean = sanitizeSvg(
      '<svg><script>alert(1)</script><a href="https://evil.test" onclick="x()">t</a></svg>'
    )
    expect(clean).not.toContain('script')
    expect(clean).not.toContain('https://evil.test')
    expect(clean).not.toContain('onclick')
    expect(artifactSrcDoc({ language: 'svg', code: clean, index: 0 })).toContain('default-src')
    expect(artifactSrcDoc({ language: 'svg', code: clean, index: 0 })).not.toContain(
      'allow-same-origin'
    )
  })
})
