/**
 * Date buckets for the chat history in the sidebar. The keys match the
 * existing `chat.browser.*` labels, so every locale already has the copy.
 */
export type ChatDateGroup = 'today' | 'yesterday' | 'lastWeek' | 'lastMonth' | 'older'

const LAST_WEEK_DAYS = 7
const LAST_MONTH_DAYS = 30

/**
 * Bucket a chat by its last activity, in the viewer's local calendar days.
 * An unreadable timestamp lands in "older" rather than breaking the list.
 *
 * Boundaries are built from local midnights with `setDate()`, never by
 * subtracting fixed 24h blocks: across a daylight-saving transition a
 * calendar day is 23 or 25 hours long, so millisecond cutoffs misclassify
 * chats near the boundary (e.g. Nov 1 00:30 landing in "Last Week").
 */
export function chatDateGroup(timestamp: string | null | undefined, now: Date): ChatDateGroup {
  const time = Date.parse(timestamp ?? '')
  if (Number.isNaN(time)) return 'older'
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const startYesterday = new Date(startToday)
  startYesterday.setDate(startYesterday.getDate() - 1)
  const startLastWeek = new Date(startToday)
  startLastWeek.setDate(startLastWeek.getDate() - LAST_WEEK_DAYS)
  const startLastMonth = new Date(startToday)
  startLastMonth.setDate(startLastMonth.getDate() - LAST_MONTH_DAYS)
  if (time >= startToday.getTime()) return 'today'
  if (time >= startYesterday.getTime()) return 'yesterday'
  if (time >= startLastWeek.getTime()) return 'lastWeek'
  if (time >= startLastMonth.getTime()) return 'lastMonth'
  return 'older'
}
