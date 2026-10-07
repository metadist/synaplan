/**
 * Calendar-day bounds in an IANA time zone.
 * Date-only strings (YYYY-MM-DD) are wall-clock days in that zone, not UTC midnights.
 */

export type ZonedParts = {
  year: number
  month: number
  day: number
  hour: number
  minute: number
  second: number
}

export function isValidIanaTimezone(timeZone: string): boolean {
  const name = timeZone.trim()
  if (name === '') return false
  try {
    Intl.DateTimeFormat(undefined, { timeZone: name })
    return true
  } catch {
    return false
  }
}

/** Time zone of the open browser. UTC when the runtime cannot name one. */
export function browserTimezone(): string {
  try {
    const name = Intl.DateTimeFormat().resolvedOptions().timeZone
    return isValidIanaTimezone(name) ? name : 'UTC'
  } catch {
    return 'UTC'
  }
}

export function zonedParts(date: Date, timeZone: string): ZonedParts | null {
  try {
    const formatted = new Intl.DateTimeFormat('en-US', {
      timeZone,
      hourCycle: 'h23',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    }).formatToParts(date)
    const map: Record<string, string> = {}
    for (const part of formatted) {
      if (part.type !== 'literal') map[part.type] = part.value
    }
    let hour = Number(map.hour)
    if (hour === 24) hour = 0
    const parts = {
      year: Number(map.year),
      month: Number(map.month),
      day: Number(map.day),
      hour,
      minute: Number(map.minute),
      second: Number(map.second),
    }
    if (Object.values(parts).some((value) => Number.isNaN(value))) return null
    return parts
  } catch {
    return null
  }
}

export function zonedTodayIso(timeZone: string, now: Date = new Date()): string | null {
  const parts = zonedParts(now, timeZone)
  if (!parts) return null
  const month = String(parts.month).padStart(2, '0')
  const day = String(parts.day).padStart(2, '0')
  return `${parts.year}-${month}-${day}`
}

/**
 * Milliseconds to add to a UTC instant to read that zone's wall clock.
 * Positive for zones ahead of UTC.
 */
function zoneOffsetMs(instant: Date, timeZone: string): number | null {
  const parts = zonedParts(instant, timeZone)
  if (!parts) return null
  const wallAsUtc = Date.UTC(
    parts.year,
    parts.month - 1,
    parts.day,
    parts.hour,
    parts.minute,
    parts.second
  )
  return wallAsUtc - instant.getTime()
}

const DAY_MS = 24 * 60 * 60 * 1000

function dayKey(parts: Pick<ZonedParts, 'year' | 'month' | 'day'>): number {
  return parts.year * 10000 + parts.month * 100 + parts.day
}

/**
 * Unix seconds of the first instant of a YYYY-MM-DD day in `timeZone`.
 * Usually local midnight. When midnight is skipped by a clock change, the day
 * starts at the end of that gap. A day skipped entirely starts and ends at the
 * same instant, so it contains nothing.
 */
export function startOfZonedDayUnix(isoDate: string, timeZone: string): number | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(isoDate)) return null
  const [year, month, day] = isoDate.split('-').map(Number)
  if (!year || !month || !day) return null
  const wallMidnight = Date.UTC(year, month - 1, day, 0, 0, 0)
  const target = dayKey({ year, month, day })

  // The offsets a day before and after cover both sides of any clock change.
  const offsetBefore = zoneOffsetMs(new Date(wallMidnight - DAY_MS), timeZone)
  const offsetAfter = zoneOffsetMs(new Date(wallMidnight + DAY_MS), timeZone)
  if (offsetBefore === null || offsetAfter === null) return null

  const candidates = [wallMidnight - offsetBefore, wallMidnight - offsetAfter]
  const exact = candidates.filter((utc) => {
    const parts = zonedParts(new Date(utc), timeZone)
    return (
      parts !== null &&
      dayKey(parts) === target &&
      parts.hour === 0 &&
      parts.minute === 0 &&
      parts.second === 0
    )
  })
  if (exact.length > 0) return Math.floor(Math.min(...exact) / 1000)

  // Midnight falls into a gap: find the first second whose local day is not earlier.
  let low = Math.min(...candidates)
  let high = Math.max(...candidates)
  while (high - low > 1000) {
    const mid = low + Math.floor((high - low) / 2000) * 1000
    const parts = zonedParts(new Date(mid), timeZone)
    if (!parts) return null
    if (dayKey(parts) >= target) high = mid
    else low = mid
  }
  return Math.floor(high / 1000)
}

function nextIsoDate(isoDate: string): string | null {
  const [year, month, day] = isoDate.split('-').map(Number)
  if (!year || !month || !day) return null
  const next = new Date(Date.UTC(year, month - 1, day))
  next.setUTCDate(next.getUTCDate() + 1)
  return next.toISOString().slice(0, 10)
}

/** Unix seconds of 23:59:59 on that calendar day, including short and long DST days. */
export function endOfZonedDayUnix(isoDate: string, timeZone: string): number | null {
  const next = nextIsoDate(isoDate)
  if (!next) return null
  const nextStart = startOfZonedDayUnix(next, timeZone)
  if (nextStart === null) return null
  return nextStart - 1
}
