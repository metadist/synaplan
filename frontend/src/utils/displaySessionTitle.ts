/**
 * API session titles store a UTC clock with a trailing Z when the account
 * has no timezone. Show that clock in the viewer's local time.
 * A stamp without Z was already formatted in the account timezone.
 */
export function displaySessionTitle(title: string, timeZone?: string): string {
  const match = /^(.* · )(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})Z$/.exec(title.trim())
  if (!match) {
    return title
  }

  const instant = new Date(`${match[2]}T${match[3]}:00Z`)
  if (Number.isNaN(instant.getTime())) {
    return title
  }

  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(instant)

  const pick = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((part) => part.type === type)?.value ?? ''

  return `${match[1]}${pick('year')}-${pick('month')}-${pick('day')} ${pick('hour')}:${pick('minute')}`
}
