/**
 * Date buckets for the chat history in the sidebar. The keys match the
 * existing `chat.browser.*` labels, so every locale already has the copy.
 */
export type ChatDateGroup = 'today' | 'yesterday' | 'lastWeek' | 'lastMonth' | 'older'

const DAY_MS = 24 * 60 * 60 * 1000
const LAST_WEEK_DAYS = 7
const LAST_MONTH_DAYS = 30

function startOfDay(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime()
}

/**
 * Bucket a chat by its last activity, in the viewer's local calendar days.
 * An unreadable timestamp lands in "older" rather than breaking the list.
 */
export function chatDateGroup(timestamp: string | null | undefined, now: Date): ChatDateGroup {
  const time = Date.parse(timestamp ?? '')
  if (Number.isNaN(time)) return 'older'
  const today = startOfDay(now)
  if (time >= today) return 'today'
  if (time >= today - DAY_MS) return 'yesterday'
  if (time >= today - LAST_WEEK_DAYS * DAY_MS) return 'lastWeek'
  if (time >= today - LAST_MONTH_DAYS * DAY_MS) return 'lastMonth'
  return 'older'
}
