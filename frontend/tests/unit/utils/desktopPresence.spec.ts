import { describe, expect, it } from 'vitest'
import { DESKTOP_CHECK_IN_SECONDS, desktopPresence } from '@/utils/desktopPresence'

describe('desktopPresence', () => {
  const now = 1_700_000_000

  it('matches the idle check-in interval of 180 seconds', () => {
    expect(DESKTOP_CHECK_IN_SECONDS).toBe(180)
  })

  it('does not call a valid key connected until the app has checked in', () => {
    expect(desktopPresence('active', 0, now)).toBe('never')
    expect(desktopPresence('revoked', now, now)).toBe('revoked')
  })

  it('stays online through the idle window and flips after it', () => {
    expect(desktopPresence('active', now - DESKTOP_CHECK_IN_SECONDS, now)).toBe('online')
    expect(desktopPresence('active', now - DESKTOP_CHECK_IN_SECONDS - 1, now)).toBe('away')
  })
})
