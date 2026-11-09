import type { MediaJobInfo, Message } from '@/stores/history'
import { stripStoredAdminDiagnostics } from '@/utils/storedAdminDiagnostics'

const GENERATING_TOKENS = new Set([
  '__IMAGE_GENERATING__',
  '__VIDEO_GENERATING__',
  '__AUDIO_GENERATING__',
])

/** Normalize a media_job payload from API rows or SSE metadata. */
export function parseMediaJobPayload(raw: unknown): MediaJobInfo | null {
  if (!raw || typeof raw !== 'object') return null
  const row = raw as Record<string, unknown>
  const jobId = row.job_id ?? row.jobId
  const type = row.type
  const state = row.state
  if (typeof jobId !== 'string' || jobId === '') return null
  if (typeof type !== 'string' || type === '') return null
  const percent = row.percent
  return {
    jobId,
    type,
    state: typeof state === 'string' ? state : 'running',
    ...mediaJobFailure(row),
    percent: typeof percent === 'number' ? percent : undefined,
    elapsedSeconds:
      typeof row.elapsed_seconds === 'number'
        ? row.elapsed_seconds
        : typeof row.elapsedSeconds === 'number'
          ? row.elapsedSeconds
          : undefined,
    maxWaitSeconds:
      typeof row.max_wait_seconds === 'number'
        ? row.max_wait_seconds
        : typeof row.maxWaitSeconds === 'number'
          ? row.maxWaitSeconds
          : undefined,
  }
}

/** Sentence for the red notice. A generating token is not a failure. */
export function mediaFailureSentence(
  role: string,
  text: string | undefined,
  errorReason: string | null | undefined,
  mediaJob: MediaJobInfo | null
): string | null {
  const failedMedia = mediaJob?.state === 'failed'
  if (role !== 'assistant' || (!errorReason && !failedMedia && !mediaJob?.errorReason)) return null
  const sentence = stripStoredAdminDiagnostics((text ?? '').trim())
  if (sentence === '' || GENERATING_TOKENS.has(sentence)) return null
  return sentence
}

/** User-safe error fields from a media-job payload. The dump is never one of them. */
export function mediaJobFailure(row: {
  error?: unknown
  error_reason?: unknown
  errorReason?: unknown
}): Pick<MediaJobInfo, 'error' | 'errorReason'> {
  const error = typeof row.error === 'string' ? stripStoredAdminDiagnostics(row.error) : undefined
  const reason = row.error_reason ?? row.errorReason
  const errorReason = typeof reason === 'string' && reason !== '' ? reason : undefined
  return {
    ...(error !== undefined ? { error } : {}),
    ...(errorReason ? { errorReason } : {}),
  }
}

/** Copy the notice fields a reload knows and the live bubble does not. */
export function adoptPersistedFailure(local: Message, persisted: Message): void {
  if (persisted.errorMessage) local.errorMessage = persisted.errorMessage
  if (persisted.errorDebug) local.errorDebug = persisted.errorDebug
  if (!persisted.taskPlan || !local.taskPlan) return
  for (const card of local.taskPlan.cards) {
    const match = persisted.taskPlan.cards.find((candidate) => candidate.nodeId === card.nodeId)
    if (!match) continue
    if (match.errorDebug) card.errorDebug = match.errorDebug
    if (card.state === 'failed' && match.error) card.error = match.error
  }
}
