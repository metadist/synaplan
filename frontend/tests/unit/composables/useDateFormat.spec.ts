import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'

const mockLocale = ref('en')

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, _params?: Record<string, unknown>, plural?: number) => {
      const translations: Record<string, string | ((n: number) => string)> = {
        'common.justNow': 'Just now',
        'common.minutesAgo': (n: number) => `${n} minute${n === 1 ? '' : 's'} ago`,
        'common.hoursAgo': (n: number) => `${n} hour${n === 1 ? '' : 's'} ago`,
        'common.daysAgo': (n: number) => `${n} day${n === 1 ? '' : 's'} ago`,
        'common.today': 'Today',
        'common.yesterday': 'Yesterday',
      }
      const entry = translations[key]
      if (typeof entry === 'function') return entry(plural ?? 0)
      return entry ?? key
    },
    locale: mockLocale,
  }),
}))

import { useDateFormat } from '@/composables/useDateFormat'

describe('useDateFormat', () => {
  let fmt: ReturnType<typeof useDateFormat>

  beforeEach(() => {
    mockLocale.value = 'en'
    fmt = useDateFormat()
  })

  describe('formatTime', () => {
    it('should return a formatted time string', () => {
      const date = new Date(2025, 5, 15, 14, 30, 0)
      const result = fmt.formatTime(date)
      expect(result).toContain('30')
    })
  })

  describe('formatDate', () => {
    it('should return a formatted date string with year', () => {
      const date = new Date(2025, 0, 5)
      const result = fmt.formatDate(date)
      expect(result).toContain('2025')
    })
  })

  describe('formatDateTime', () => {
    it('should contain both date and time parts', () => {
      const date = new Date(2025, 11, 24, 18, 45)
      const result = fmt.formatDateTime(date)
      expect(result).toContain('2025')
      expect(result).toContain('45')
    })
  })

  describe('formatter caching', () => {
    it('should return same formatter instance when locale does not change', () => {
      const date = new Date(2025, 5, 15, 10, 0, 0)
      const first = fmt.formatTime(date)
      const second = fmt.formatTime(date)
      expect(first).toBe(second)
    })

    it('should update formatter when locale changes', () => {
      const date = new Date(2025, 0, 15, 14, 30, 0)
      const enResult = fmt.formatDate(date)

      mockLocale.value = 'de'
      const deResult = fmt.formatDate(date)

      expect(enResult).not.toBe(deResult)
    })
  })

  describe('formatRelativeTime', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date(2025, 5, 15, 12, 0, 0))
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('should return "Just now" for timestamps less than 1 minute ago', () => {
      const date = new Date(2025, 5, 15, 11, 59, 30)
      expect(fmt.formatRelativeTime(date)).toBe('Just now')
    })

    it('should return "Just now" for future timestamps (clock skew)', () => {
      const future = new Date(2025, 5, 15, 12, 0, 30)
      expect(fmt.formatRelativeTime(future)).toBe('Just now')
    })

    it('should return minutes ago for timestamps 1-59 minutes in the past', () => {
      const date = new Date(2025, 5, 15, 11, 30, 0)
      expect(fmt.formatRelativeTime(date)).toBe('30 minutes ago')
    })

    it('should return hours ago for timestamps 1-23 hours in the past', () => {
      const date = new Date(2025, 5, 15, 9, 0, 0)
      expect(fmt.formatRelativeTime(date)).toBe('3 hours ago')
    })

    it('should return days ago for timestamps 1-6 days in the past', () => {
      const date = new Date(2025, 5, 13, 12, 0, 0)
      expect(fmt.formatRelativeTime(date)).toBe('2 days ago')
    })

    it('should fall back to formatted date for timestamps >= 7 days', () => {
      const date = new Date(2025, 5, 1, 12, 0, 0)
      const result = fmt.formatRelativeTime(date)
      expect(result).toContain('2025')
    })
  })

  describe('getDateLabel', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date(2025, 5, 15, 12, 0, 0))
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    it('should return "Today" for today', () => {
      const today = new Date(2025, 5, 15, 8, 0, 0)
      expect(fmt.getDateLabel(today)).toBe('Today')
    })

    it('should return "Yesterday" for yesterday', () => {
      const yesterday = new Date(2025, 5, 14, 23, 59, 0)
      expect(fmt.getDateLabel(yesterday)).toBe('Yesterday')
    })

    it('should return formatted date for older dates', () => {
      const older = new Date(2025, 5, 10, 12, 0, 0)
      const result = fmt.getDateLabel(older)
      expect(result).toContain('2025')
    })

    it('should handle DST boundary correctly using calendar days', () => {
      vi.setSystemTime(new Date(2025, 2, 31, 0, 30, 0))
      const yesterday = new Date(2025, 2, 30, 23, 30, 0)
      expect(fmt.getDateLabel(yesterday)).toBe('Yesterday')
    })

    it('should return "Today" for future timestamp on the same day', () => {
      const laterToday = new Date(2025, 5, 15, 23, 59, 0)
      expect(fmt.getDateLabel(laterToday)).toBe('Today')
    })
  })
})
