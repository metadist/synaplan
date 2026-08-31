import { describe, expect, it } from 'vitest'
import { inferNavContext } from '@/router/navContext'

describe('inferNavContext', () => {
  it('marks public contract routes as public', () => {
    expect(inferNavContext('/shared/abc', { public: true })).toBe('public')
    expect(inferNavContext('/addin/connect')).toBe('public')
    expect(inferNavContext('/account-deletion')).toBe('public')
    expect(inferNavContext('/login')).toBe('public')
  })

  it('marks admin and setup as operate', () => {
    expect(inferNavContext('/admin')).toBe('operate')
    expect(inferNavContext('/admin/setup')).toBe('operate')
    expect(inferNavContext('/setup')).toBe('operate')
  })

  it('marks channels and AI pages as manage', () => {
    expect(inferNavContext('/channels/widgets')).toBe('manage')
    expect(inferNavContext('/ai/models')).toBe('manage')
    expect(inferNavContext('/plugins/fastbill')).toBe('manage')
  })

  it('marks account pages as personal', () => {
    expect(inferNavContext('/settings')).toBe('personal')
    expect(inferNavContext('/profile')).toBe('personal')
    expect(inferNavContext('/statistics')).toBe('personal')
  })

  it('marks chat and files as work', () => {
    expect(inferNavContext('/')).toBe('work')
    expect(inferNavContext('/files')).toBe('work')
  })
})
