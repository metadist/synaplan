import { describe, expect, it } from 'vitest'
import { displaySessionTitle } from '@/utils/displaySessionTitle'

describe('displaySessionTitle', () => {
  it('leaves an account-timezone stamp unchanged', () => {
    expect(displaySessionTitle('Claude Code · 2026-09-27 14:57')).toBe(
      'Claude Code · 2026-09-27 14:57'
    )
  })

  it('renders a UTC stamp in the requested timezone', () => {
    expect(displaySessionTitle('Claude Code · 2026-09-27 12:57Z', 'Europe/Berlin')).toBe(
      'Claude Code · 2026-09-27 14:57'
    )
  })
})
