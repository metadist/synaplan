import { describe, expect, it } from 'vitest'
import {
  filterTimezones,
  formatUtcOffset,
  groupTimezones,
  listTimezones,
  timezoneGroupsForSelect,
} from '@/utils/timezones'

const winter = new Date('2026-01-15T12:00:00Z')
const summer = new Date('2026-07-15T12:00:00Z')

describe('listTimezones', () => {
  const zones = listTimezones(winter)

  it('imports the IANA list instead of a short hardcoded sample', () => {
    expect(zones.length).toBeGreaterThan(100)
    for (const id of [
      'UTC',
      'Europe/Berlin',
      'Europe/London',
      'America/New_York',
      'America/Los_Angeles',
      'Asia/Tokyo',
      'Australia/Sydney',
      'Pacific/Chatham',
    ]) {
      expect(zones.some((zone) => zone.value === id)).toBe(true)
    }
  })

  it('shows the current offset in hours and minutes on every zone', () => {
    for (const zone of zones) {
      expect(zone.label).toMatch(/\(UTC[+-]\d{2}:\d{2}\)$/)
      expect(zone.offset).toMatch(/^UTC[+-]\d{2}:\d{2}$/)
    }
  })

  it('keeps half-hour and quarter-hour offsets', () => {
    const india = zones.find(
      (zone) => zone.value === 'Asia/Kolkata' || zone.value === 'Asia/Calcutta'
    )
    const nepal = zones.find(
      (zone) => zone.value === 'Asia/Kathmandu' || zone.value === 'Asia/Katmandu'
    )
    expect(india?.offset).toBe('UTC+05:30')
    expect(nepal?.offset).toBe('UTC+05:45')
    expect(zones.find((zone) => zone.value === 'America/St_Johns')?.offset).toBe('UTC-03:30')
  })

  it('follows daylight saving instead of a fixed hour', () => {
    const winterZones = listTimezones(winter)
    const summerZones = listTimezones(summer)
    expect(winterZones.find((zone) => zone.value === 'Europe/Berlin')?.offset).toBe('UTC+01:00')
    expect(summerZones.find((zone) => zone.value === 'Europe/Berlin')?.offset).toBe('UTC+02:00')
    expect(formatUtcOffset('America/New_York', winter)).toBe('UTC-05:00')
    expect(formatUtcOffset('America/New_York', summer)).toBe('UTC-04:00')
  })

  it('sorts by offset, then by name', () => {
    for (let index = 1; index < zones.length; index += 1) {
      const previous = zones[index - 1]
      const current = zones[index]
      expect(previous.offsetMinutes).toBeLessThanOrEqual(current.offsetMinutes)
      if (previous.offsetMinutes === current.offsetMinutes) {
        expect(previous.value.localeCompare(current.value)).toBeLessThanOrEqual(0)
      }
    }
  })

  it('keeps a saved zone that the runtime list does not know', () => {
    const withCustom = listTimezones(winter, 'Custom/Saved')
    expect(withCustom.some((zone) => zone.value === 'Custom/Saved')).toBe(true)
  })
})

describe('filter and grouping', () => {
  const zones = listTimezones(winter)

  it('finds a city or an offset, including minutes', () => {
    const nepal = filterTimezones(zones, 'kathmandu').map((zone) => zone.value)
    expect(nepal.some((value) => value === 'Asia/Kathmandu' || value === 'Asia/Katmandu')).toBe(
      true
    )
    expect(
      filterTimezones(zones, '+05:45').some(
        (zone) => zone.value === 'Asia/Kathmandu' || zone.value === 'Asia/Katmandu'
      )
    ).toBe(true)
    expect(filterTimezones(zones, 'kolkata').some((zone) => zone.offset === 'UTC+05:30')).toBe(true)
    expect(filterTimezones(zones, 'new york').map((zone) => zone.value)).toContain(
      'America/New_York'
    )
    expect(filterTimezones(zones, 'no-such-zone')).toEqual([])
  })

  it('groups zones under their hour offset', () => {
    const groups = groupTimezones(filterTimezones(zones, 'kolkata'))
    expect(groups.some((group) => group.offset === 'UTC+05:30')).toBe(true)
    expect(
      groups
        .find((group) => group.offset === 'UTC+05:30')
        ?.zones.some((zone) => zone.value === 'Asia/Kolkata' || zone.value === 'Asia/Calcutta')
    ).toBe(true)
  })

  it('keeps the selected zone visible when the search misses it', () => {
    const result = timezoneGroupsForSelect(zones, 'zzzz-no-match', 'Europe/Berlin')
    expect(result.matchedCount).toBe(0)
    expect(
      result.groups.some((group) => group.zones.some((zone) => zone.value === 'Europe/Berlin'))
    ).toBe(true)
  })
})
