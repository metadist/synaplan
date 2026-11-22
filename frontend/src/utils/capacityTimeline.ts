/**
 * Formatting for Admin › Capacity › Requests, AI calls, Tools and Outbound
 * (architecture update AU33). Pure functions over the timeline reports so
 * the tabs stay templates.
 */

export type PartKey = 'wait' | 'php' | 'ai' | 'tools' | 'outbound' | 'db'

export interface RequestPart {
  key: PartKey
  ms: number
}

export interface TimedRequest {
  total_ms: number
  php_ms: number
  wait_ms?: number | null
  db_ms: number
  ai: readonly { duration_ms: number }[]
  tools: readonly { duration_ms: number }[]
  egress: readonly { duration_ms: number }[]
}

/** `0 ms`, `< 1 ms`, `820 ms`, `1.2 s`, `14 s`, `2 min 5 s`. */
export function formatDuration(ms: number | null | undefined): string {
  if (ms == null || !Number.isFinite(ms) || ms < 0) return '–'
  if (ms > 0 && ms < 1) return '< 1 ms'
  if (ms < 1000) return `${Math.round(ms)} ms`
  const seconds = ms / 1000
  if (seconds < 10) return `${seconds.toFixed(1)} s`
  if (seconds < 60) return `${Math.round(seconds)} s`
  const minutes = Math.floor(seconds / 60)
  const rest = Math.round(seconds - minutes * 60)
  return rest === 0 ? `${minutes} min` : `${minutes} min ${rest} s`
}

/** Requests per minute over the window the report read; one decimal below ten. */
export function perMinute(count: number, windowSeconds: number): string {
  if (count <= 0) return '0'
  const minutes = Math.max(1, windowSeconds) / 60
  const rate = count / Math.max(minutes, 1 / 60)
  return rate < 10 ? (Math.round(rate * 10) / 10).toString() : Math.round(rate).toString()
}

/** Whole percent of `part` in `total`; 0 when there is no total. */
export function share(part: number, total: number): number {
  if (total <= 0 || part <= 0) return 0
  return Math.min(100, Math.round((part / total) * 100))
}

const sum = (items: readonly { duration_ms: number }[]): number =>
  items.reduce((acc, item) => acc + Math.max(0, item.duration_ms), 0)

/**
 * Splits one request's total time into parts that add up to the total.
 * The timings overlap: PHP time contains the AI, tool and database time,
 * and AI and tool calls make outbound calls of their own. So waiting comes
 * first, then AI, tools and database; outbound is only what AI and tools do
 * not already explain; PHP is the rest.
 */
export function requestParts(request: TimedRequest): RequestPart[] {
  const total = Math.max(0, request.total_ms)
  let left = total
  const take = (ms: number): number => {
    const taken = Math.min(Math.max(0, Math.round(ms)), left)
    left -= taken
    return taken
  }
  const ai = sum(request.ai)
  const tools = sum(request.tools)
  const parts: RequestPart[] = [
    { key: 'wait', ms: take(request.wait_ms ?? 0) },
    { key: 'ai', ms: take(ai) },
    { key: 'tools', ms: take(tools) },
    { key: 'db', ms: take(request.db_ms) },
    { key: 'outbound', ms: take(sum(request.egress) - ai - tools) },
  ]
  parts.push({ key: 'php', ms: left })
  return parts
}

/** The part that took longest, for the one-line summary of a request. */
export function largestPart(parts: readonly RequestPart[]): RequestPart | null {
  return parts.reduce<RequestPart | null>(
    (best, part) => (part.ms > 0 && (!best || part.ms > best.ms) ? part : best),
    null
  )
}

export type SortDirection = 'asc' | 'desc'

/** Stable sort by one numeric or text column; nulls last either way. */
export function sortRows<T, K extends keyof T>(
  rows: readonly T[],
  key: K,
  direction: SortDirection
): T[] {
  const factor = direction === 'asc' ? 1 : -1
  return rows
    .map((row, index) => ({ row, index }))
    .sort((a, b) => {
      const x = a.row[key]
      const y = b.row[key]
      if (x == null && y == null) return a.index - b.index
      if (x == null) return 1
      if (y == null) return -1
      if (x < y) return -1 * factor
      if (x > y) return 1 * factor
      return a.index - b.index
    })
    .map(({ row }) => row)
}

/** A decimal-string cost from the report, with four decimals. */
export function formatCost(cost: string): string {
  const value = Number.parseFloat(cost)
  return Number.isFinite(value) ? value.toFixed(4) : '0.0000'
}
