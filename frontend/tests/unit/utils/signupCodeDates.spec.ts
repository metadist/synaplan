import { describe, expect, it } from 'vitest'
import { dateInputToUnixEndOfDay, unixToDateInput } from '@/utils/signupCodeDates'

describe('signupCodeDates', () => {
  it('ends a "valid until" date at the last second of that local day', () => {
    const seconds = dateInputToUnixEndOfDay('2027-03-25')
    const date = new Date(seconds * 1000)

    expect([date.getFullYear(), date.getMonth() + 1, date.getDate()]).toEqual([2027, 3, 25])
    expect([date.getHours(), date.getMinutes(), date.getSeconds()]).toEqual([23, 59, 59])
  })

  it('round-trips through the date input', () => {
    expect(unixToDateInput(dateInputToUnixEndOfDay('2026-01-05'))).toBe('2026-01-05')
  })
})
