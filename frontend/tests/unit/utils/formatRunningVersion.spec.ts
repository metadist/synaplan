import { describe, expect, it } from 'vitest'

import { formatRunningVersion } from '@/utils/formatRunningVersion'

describe('formatRunningVersion', () => {
  it('prefixes a SemVer with v', () => {
    expect(formatRunningVersion('4.0.13')).toBe('v4.0.13')
  })

  it('keeps an already-prefixed or named release as-is', () => {
    expect(formatRunningVersion('v4.0.13')).toBe('v4.0.13')
    expect(formatRunningVersion('dev')).toBe('dev')
  })

  it('never shows the mutable latest tag', () => {
    expect(formatRunningVersion('latest')).toBe('')
    expect(formatRunningVersion(' latest ')).toBe('')
  })

  it('hides empty and unknown values', () => {
    expect(formatRunningVersion('')).toBe('')
    expect(formatRunningVersion('unknown')).toBe('')
    expect(formatRunningVersion(null)).toBe('')
    expect(formatRunningVersion(undefined)).toBe('')
  })
})
