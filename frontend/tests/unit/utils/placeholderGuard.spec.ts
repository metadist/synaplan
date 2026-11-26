import { describe, expect, it } from 'vitest'
import { breakPlaceholdersInAttributes } from '@/utils/placeholderGuard'

const PLACEHOLDER = /\[Memory\s*:\s*([^\]]+)\]/i

describe('breakPlaceholdersInAttributes', () => {
  it('leaves a placeholder in the text where the badge belongs', () => {
    const html = '<p>See [Memory: 3] for details.</p>'

    expect(breakPlaceholdersInAttributes(html)).toBe(html)
  })

  it('stops a placeholder inside an attribute from matching', () => {
    const out = breakPlaceholdersInAttributes(
      '<p><a href="https://example.com" title="[Memory: 3]">link</a> and [Memory: 4]</p>'
    )
    const container = document.createElement('div')
    container.innerHTML = out

    expect(PLACEHOLDER.test(container.querySelector('a')?.getAttribute('title') ?? '')).toBe(false)
    expect(container.textContent).toContain('[Memory: 4]')
    expect(out.match(new RegExp(PLACEHOLDER, 'gi'))).toHaveLength(1)
  })

  it('covers single-quoted and unquoted attribute values', () => {
    for (const html of ["<img alt='[Memory: 1]'>", '<img alt=[[SETUP_CTA]]>']) {
      const out = breakPlaceholdersInAttributes(html)

      expect(out).not.toContain('[Memory')
      expect(out).not.toContain('[[SETUP_CTA]]')
    }
  })

  it('keeps a link address with brackets working', () => {
    const out = breakPlaceholdersInAttributes(
      '<a href="https://example.com/?ids[]=1">a</a><a href="http://[::1]:8080/x[1]">b</a>'
    )
    const container = document.createElement('div')
    container.innerHTML = out
    const [query, host] = Array.from(container.querySelectorAll('a')).map((a) => a.href)

    expect(query).toBe('https://example.com/?ids%5B]=1')
    expect(host).toBe('http://[::1]:8080/x%5B1]')
  })
})
