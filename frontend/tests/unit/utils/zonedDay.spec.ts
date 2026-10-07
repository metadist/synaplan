import { describe, expect, it } from 'vitest'
import { endOfZonedDayUnix, startOfZonedDayUnix, zonedTodayIso } from '@/utils/zonedDay'

describe('zoned day bounds', () => {
  it('uses the profile zone for a normal day, not UTC midnight', () => {
    expect(startOfZonedDayUnix('2026-10-07', 'Europe/Berlin')).toBe(
      Date.parse('2026-10-06T22:00:00Z') / 1000
    )
    expect(endOfZonedDayUnix('2026-10-07', 'Europe/Berlin')).toBe(
      Date.parse('2026-10-07T21:59:59Z') / 1000
    )
    expect(startOfZonedDayUnix('2026-10-07', 'America/New_York')).toBe(
      Date.parse('2026-10-07T04:00:00Z') / 1000
    )
  })

  it('covers a short spring-forward day through the next midnight', () => {
    expect(startOfZonedDayUnix('2026-03-29', 'Europe/Berlin')).toBe(
      Date.parse('2026-03-28T23:00:00Z') / 1000
    )
    expect(endOfZonedDayUnix('2026-03-29', 'Europe/Berlin')).toBe(
      Date.parse('2026-03-29T21:59:59Z') / 1000
    )
  })

  it('names today in the given zone', () => {
    const eveningInBerlin = new Date('2026-10-07T22:30:00Z')
    expect(zonedTodayIso('Europe/Berlin', eveningInBerlin)).toBe('2026-10-08')
    expect(zonedTodayIso('America/New_York', eveningInBerlin)).toBe('2026-10-07')
  })
})
