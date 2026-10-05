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
})
