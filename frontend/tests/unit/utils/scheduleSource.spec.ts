import { describe, expect, it } from 'vitest'
import { instructionHasUrl, scheduleSourceFromParts } from '@/utils/scheduleSource'
import type { Part } from '@/stores/history'

describe('scheduleSourceFromParts', () => {
  it('joins text and pastedText so a URL in a paste is kept', () => {
    const parts: Part[] = [
      { type: 'pastedText', content: 'Read https://example.com/pasted' },
      { type: 'text', content: 'and summarize it' },
    ]
    expect(scheduleSourceFromParts(parts)).toBe('Read https://example.com/pasted\nand summarize it')
  })

  it('ignores non-text parts', () => {
    const parts: Part[] = [
      { type: 'image', url: 'https://cdn.example.com/x.png' },
      { type: 'text', content: 'hello' },
    ]
    expect(scheduleSourceFromParts(parts)).toBe('hello')
  })
})

describe('instructionHasUrl', () => {
  it('detects a bare URL', () => {
    expect(instructionHasUrl('summarize https://example.com/a')).toBe(true)
  })

  it('detects a markdown link', () => {
    expect(instructionHasUrl('read [the page](https://example.com/a)')).toBe(true)
  })

  it('is false without an http URL', () => {
    expect(instructionHasUrl('summarize my notes')).toBe(false)
  })
})
