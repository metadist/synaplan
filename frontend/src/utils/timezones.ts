export interface TimezoneOption {
  value: string
  label: string
  offset: string
  offsetMinutes: number
}

export interface TimezoneGroup {
  offset: string
  offsetMinutes: number
  zones: TimezoneOption[]
}

const OFFSET_PATTERN = /^(?:GMT|UTC)([+-])(\d{1,2})(?::(\d{2}))?$/i

/**
 * Older ICU datasets still publish the previous IANA link name. Search must
 * find the city people type ("Kolkata") even when the option value is
 * "Asia/Calcutta". Both names are accepted by PHP.
 */
const SEARCH_ALIASES: Record<string, readonly string[]> = {
  'Asia/Calcutta': ['Kolkata', 'Asia/Kolkata'],
  'Asia/Kolkata': ['Calcutta', 'Asia/Calcutta'],
  'Asia/Katmandu': ['Kathmandu', 'Asia/Kathmandu'],
  'Asia/Kathmandu': ['Katmandu', 'Asia/Katmandu'],
  'Asia/Saigon': ['Ho Chi Minh', 'Asia/Ho_Chi_Minh'],
  'Asia/Ho_Chi_Minh': ['Saigon', 'Asia/Saigon'],
  'Asia/Rangoon': ['Yangon', 'Asia/Yangon'],
  'Asia/Yangon': ['Rangoon', 'Asia/Rangoon'],
  'Europe/Kiev': ['Kyiv', 'Europe/Kyiv'],
  'Europe/Kyiv': ['Kiev', 'Europe/Kiev'],
  'America/Godthab': ['Nuuk', 'America/Nuuk'],
  'America/Nuuk': ['Godthab', 'America/Godthab'],
  'Atlantic/Faeroe': ['Faroe', 'Atlantic/Faroe'],
  'Atlantic/Faroe': ['Faeroe', 'Atlantic/Faeroe'],
}

function ianaTimeZoneIds(): string[] {
  const intl = Intl as typeof Intl & {
    supportedValuesOf?: (key: 'timeZone') => string[]
  }
  if (typeof intl.supportedValuesOf === 'function') {
    return intl.supportedValuesOf('timeZone')
  }
  return ['UTC']
}

/**
 * Current offset from UTC, including daylight saving, always with hours and minutes.
 * Examples: UTC+01:00, UTC+05:30, UTC-03:30.
 */
export function formatUtcOffset(timeZone: string, now: Date = new Date()): string {
  try {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone,
      timeZoneName: 'longOffset',
      hour: '2-digit',
    }).formatToParts(now)
    const raw = parts.find((part) => part.type === 'timeZoneName')?.value ?? 'GMT'
    return normalizeOffsetLabel(raw)
  } catch {
    return 'UTC+00:00'
  }
}

export function normalizeOffsetLabel(raw: string): string {
  const trimmed = raw.trim()
  if (
    trimmed === 'GMT' ||
    trimmed === 'UTC' ||
    trimmed === 'GMT+0' ||
    trimmed === 'GMT-0' ||
    trimmed === 'UTC+0' ||
    trimmed === 'UTC-0'
  ) {
    return 'UTC+00:00'
  }

  const match = trimmed.match(OFFSET_PATTERN)
  if (!match) {
    return trimmed.replace(/^GMT/i, 'UTC')
  }

  const sign = match[1] === '-' ? '-' : '+'
  const hours = match[2].padStart(2, '0')
  const minutes = (match[3] ?? '00').padStart(2, '0')
  if (hours === '00' && minutes === '00') {
    return 'UTC+00:00'
  }
  return `UTC${sign}${hours}:${minutes}`
}

export function offsetToMinutes(offset: string): number {
  const match = offset.match(/^UTC([+-])(\d{2}):(\d{2})$/)
  if (!match) return 0
  const sign = match[1] === '-' ? -1 : 1
  return sign * (Number(match[2]) * 60 + Number(match[3]))
}

export function listTimezones(now: Date = new Date(), selected = ''): TimezoneOption[] {
  const ids = new Set(ianaTimeZoneIds())
  // Some ICU builds omit UTC from supportedValuesOf('timeZone'). It is the
  // zero-offset zone and PHP accepts it, so keep it on the list.
  ids.add('UTC')
  if (selected && !ids.has(selected)) {
    ids.add(selected)
  }

  return [...ids]
    .map((value) => {
      const offset = formatUtcOffset(value, now)
      return {
        value,
        offset,
        offsetMinutes: offsetToMinutes(offset),
        label: `${value.replace(/_/g, ' ')} (${offset})`,
      }
    })
    .sort((a, b) => a.offsetMinutes - b.offsetMinutes || a.value.localeCompare(b.value))
}

function searchHaystack(zone: TimezoneOption): string {
  const match = zone.offset.match(/^UTC([+-])(\d{2}):(\d{2})$/)
  const expanded = match
    ? [
        `${match[1]}${Number(match[2])}:${match[3]}`,
        `${match[1]}${match[2]}:${match[3]}`,
        `${Number(match[2])}:${match[3]}`,
      ].join(' ')
    : ''
  const aliases = (SEARCH_ALIASES[zone.value] ?? []).join(' ')
  return `${zone.value.replace(/_/g, ' ')} ${aliases} ${zone.offset} ${expanded}`.toLowerCase()
}

export function filterTimezones(zones: TimezoneOption[], query: string): TimezoneOption[] {
  const needle = query.trim().toLowerCase().replace(/\s+/g, ' ')
  if (!needle) return zones
  const compactNeedle = needle.replace(/\s+/g, '')
  return zones.filter((zone) => {
    const haystack = searchHaystack(zone)
    return haystack.includes(needle) || haystack.replace(/\s+/g, '').includes(compactNeedle)
  })
}

export function groupTimezones(zones: TimezoneOption[]): TimezoneGroup[] {
  const groups = new Map<string, TimezoneGroup>()
  const sorted = [...zones].sort(
    (a, b) => a.offsetMinutes - b.offsetMinutes || a.value.localeCompare(b.value)
  )
  for (const zone of sorted) {
    const existing = groups.get(zone.offset)
    if (existing) {
      existing.zones.push(zone)
      continue
    }
    groups.set(zone.offset, {
      offset: zone.offset,
      offsetMinutes: zone.offsetMinutes,
      zones: [zone],
    })
  }
  return [...groups.values()]
}

/**
 * Groups for the profile select. The signed-in person's current zone stays in
 * the list even when the search hides it, so the control does not go blank.
 */
export function timezoneGroupsForSelect(
  zones: TimezoneOption[],
  query: string,
  selected: string
): { groups: TimezoneGroup[]; matchedCount: number } {
  const matched = filterTimezones(zones, query)
  const visible = [...matched]
  if (selected && !visible.some((zone) => zone.value === selected)) {
    const current = zones.find((zone) => zone.value === selected)
    if (current) visible.push(current)
  }
  return { groups: groupTimezones(visible), matchedCount: matched.length }
}
