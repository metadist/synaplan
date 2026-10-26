/** `YYYY-MM-DD` in the browser's time zone, for a date input. */
export function unixToDateInput(seconds: number): string {
  const date = new Date(seconds * 1000)
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

/** The last second of that day in the browser's time zone, so "valid until" includes the day. */
export function dateInputToUnixEndOfDay(value: string): number {
  const [year, month, day] = value.split('-').map(Number)
  return Math.floor(new Date(year, month - 1, day, 23, 59, 59).getTime() / 1000)
}
