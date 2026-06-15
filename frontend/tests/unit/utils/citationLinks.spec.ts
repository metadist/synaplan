import { describe, it, expect } from 'vitest'
import { replaceCitationMarkers } from '@/utils/citationLinks'

describe('replaceCitationMarkers', () => {
  it('replaces a bare [1] marker with a source-ref anchor', () => {
    const result = replaceCitationMarkers('Paris [1]', 3)
    expect(result).toContain('class="source-ref')
    expect(result).toContain('data-source-index="0"')
    expect(result).toContain('>1<')
  })

  it('replaces [1†source] (dagger suffix) with a source-ref anchor', () => {
    const result = replaceCitationMarkers('Paris [1†source]', 3)
    expect(result).toContain('class="source-ref')
    expect(result).toContain('data-source-index="0"')
    expect(result).toContain('>1<')
    expect(result).not.toContain('†source')
  })

  it('replaces [1↑source] (arrow suffix) with a source-ref anchor', () => {
    const result = replaceCitationMarkers('Paris [1↑source]', 3)
    expect(result).toContain('class="source-ref')
    expect(result).toContain('data-source-index="0"')
    expect(result).not.toContain('↑source')
  })

  it('replaces [1‡source] (double-dagger suffix) with a source-ref anchor', () => {
    const result = replaceCitationMarkers('Paris [1‡source]', 3)
    expect(result).toContain('class="source-ref')
    expect(result).toContain('data-source-index="0"')
    expect(result).not.toContain('‡source')
  })

  it('replaces multiple citations in one string', () => {
    const result = replaceCitationMarkers('Paris [1†source] and Berlin [2†source].', 3)
    expect(result).toContain('data-source-index="0"')
    expect(result).toContain('data-source-index="1"')
  })

  it('uses 0-based index (citation [2] → data-source-index="1")', () => {
    const result = replaceCitationMarkers('text [2]', 3)
    expect(result).toContain('data-source-index="1"')
    expect(result).toContain('>2<')
  })

  it('leaves a citation unchanged when index exceeds sourceCount', () => {
    const result = replaceCitationMarkers('text [5†source]', 3)
    expect(result).toBe('text [5†source]')
  })

  it('leaves a citation unchanged when sourceCount is 0', () => {
    const result = replaceCitationMarkers('text [1]', 0)
    expect(result).toBe('text [1]')
  })

  it('does not match [1 und 2] (space after digit, no dagger)', () => {
    const result = replaceCitationMarkers('see [1 und 2]', 5)
    expect(result).toBe('see [1 und 2]')
  })

  it('returns content unchanged when there are no citation markers', () => {
    const text = 'No citations here at all.'
    expect(replaceCitationMarkers(text, 5)).toBe(text)
  })

  it('anchor includes href="#" and onclick prevention', () => {
    const result = replaceCitationMarkers('[1]', 1)
    expect(result).toContain('href="#"')
    expect(result).toContain('onclick="event.preventDefault()"')
  })
})
