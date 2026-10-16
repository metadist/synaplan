export type DesktopJobStatus = 'queued' | 'leased' | 'succeeded' | 'failed' | 'cancelled'

export type JobCardPhase = 'waiting' | 'running' | 'succeeded' | 'failed' | 'cancelled'

export type JobCardDetail =
  { type: 'none' } | { type: 'key'; key: string } | { type: 'device'; text: string }

export interface JobCardView {
  phase: JobCardPhase
  detail: JobCardDetail
}

export interface RestorableJob {
  id: number
  chatId?: number | null
  status: string
  created: number
  updated?: number
}

const DEVICE_MESSAGE_MAX = 280
export const RESTORE_WINDOW_MS = 24 * 60 * 60 * 1000
export const RESTORED_JOB_LIMIT = 5
export const DISMISS_STORAGE_KEY = 'synaplan.desktop.dismissedJobIds'

export function deviceResultText(result: unknown): string | null {
  if (!result || typeof result !== 'object') return null
  const record = result as Record<string, unknown>
  const raw = record.message ?? record.summary
  if (typeof raw !== 'string') return null
  const cleaned = stripControlChars(raw.replace(/<[^>]*>/g, ' '))
    .replace(/\s+/g, ' ')
    .trim()
  if (cleaned === '') return null
  if (cleaned.length <= DEVICE_MESSAGE_MAX) return cleaned
  return `${cleaned.slice(0, DEVICE_MESSAGE_MAX - 1)}…`
}

export function jobCardView(
  status: DesktopJobStatus,
  errorCode: string | null,
  result: unknown,
  waitedLong: boolean
): JobCardView {
  if (status === 'succeeded') {
    const device = deviceResultText(result)
    return {
      phase: 'succeeded',
      detail: device ? { type: 'device', text: device } : { type: 'none' },
    }
  }
  if (status === 'cancelled') {
    return { phase: 'cancelled', detail: { type: 'key', key: 'cancelledDetail' } }
  }
  if (status === 'failed') {
    const device = deviceResultText(result)
    if (device) return { phase: 'failed', detail: { type: 'device', text: device } }
    return { phase: 'failed', detail: { type: 'key', key: failureDetailKey(errorCode) } }
  }
  if (status === 'leased') {
    return {
      phase: 'running',
      detail: waitedLong ? { type: 'key', key: 'stillRunning' } : { type: 'none' },
    }
  }
  return {
    phase: 'waiting',
    detail: waitedLong ? { type: 'key', key: 'queuedTooLong' } : { type: 'none' },
  }
}

export function shouldRestoreDesktopJob(
  job: RestorableJob,
  chatId: number,
  dismissed: ReadonlySet<number>,
  nowMs: number
): boolean {
  if (job.chatId !== chatId || dismissed.has(job.id)) return false
  if (job.status === 'queued' || job.status === 'leased') return true
  if (job.status !== 'failed' && job.status !== 'cancelled' && job.status !== 'succeeded')
    return false
  const stampSec = job.updated && job.updated > 0 ? job.updated : job.created
  return nowMs - stampSec * 1000 <= RESTORE_WINDOW_MS
}

export function readDismissedJobIds(storage: Pick<Storage, 'getItem'>): Set<number> {
  try {
    const raw = storage.getItem(DISMISS_STORAGE_KEY)
    if (!raw) return new Set()
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return new Set()
    return new Set(
      parsed.filter((id): id is number => typeof id === 'number' && Number.isInteger(id))
    )
  } catch {
    return new Set()
  }
}

export function rememberDismissedJobId(
  storage: Pick<Storage, 'getItem' | 'setItem'>,
  id: number
): void {
  const ids = readDismissedJobIds(storage)
  ids.add(id)
  const trimmed = [...ids].slice(-100)
  storage.setItem(DISMISS_STORAGE_KEY, JSON.stringify(trimmed))
}

function stripControlChars(value: string): string {
  let cleaned = ''
  for (const char of value) {
    const code = char.charCodeAt(0)
    cleaned += code < 32 || code === 127 ? ' ' : char
  }
  return cleaned
}

function failureDetailKey(errorCode: string | null): string {
  switch (errorCode) {
    case 'timeout':
      return 'timedOut'
    case 'unknown_skill':
      return 'unknownSkill'
    case 'skill_disabled':
      return 'skillDisabled'
    case 'unknown_type':
      return 'unknownType'
    case 'local_error':
      return 'localError'
    default:
      return errorCode ? 'localError' : 'noAnswer'
  }
}
