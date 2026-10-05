import { describe, expect, it } from 'vitest'
import { chatDateGroup } from '@/components/sidebar/chatDateGroups'

describe('chatDateGroup', () => {
  const now = new Date(2026, 9, 5, 21, 30)
  const at = (daysAgo: number, hour = 12) => new Date(2026, 9, 5 - daysAgo, hour, 0).toISOString()

  it('buckets by local calendar day', () => {
    expect(chatDateGroup(at(0, 0), now)).toBe('today')
    expect(chatDateGroup(at(1, 23), now)).toBe('yesterday')
    expect(chatDateGroup(at(1, 0), now)).toBe('yesterday')
    expect(chatDateGroup(at(2), now)).toBe('lastWeek')
    expect(chatDateGroup(at(7), now)).toBe('lastWeek')
    expect(chatDateGroup(at(8), now)).toBe('lastMonth')
    expect(chatDateGroup(at(30), now)).toBe('lastMonth')
    expect(chatDateGroup(at(31), now)).toBe('older')
  })

  it('puts an unreadable timestamp in "older"', () => {
    expect(chatDateGroup(undefined, now)).toBe('older')
    expect(chatDateGroup('', now)).toBe('older')
    expect(chatDateGroup('not a date', now)).toBe('older')
  })

  it('keeps calendar days across a daylight-saving transition', () => {
    // In America/New_York the 2026 fall-back makes Nov 1 a 25-hour day: the
    // 24h cutoff lands at 01:00, so a fixed millisecond subtraction files a
    // 00:30 chat under "Last Week". Calendar boundaries keep it "Yesterday".
    // (Only discriminates under a TZ with this transition; run this file with
    // TZ=America/New_York to exercise it. It passes either way elsewhere.)
    const reference = new Date(2026, 10, 2, 12, 0)
    const earlyMorning = new Date(2026, 10, 1, 0, 30).toISOString()
    expect(chatDateGroup(earlyMorning, reference)).toBe('yesterday')
  })
})
