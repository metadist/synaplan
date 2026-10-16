/**
 * The processing indicator while a turn works: each pipeline phase the
 * backend announces, from `started` to `generating`, and the model the
 * bubble names on the way.
 */
import type { Message } from '@/stores/history'
import type { StreamEventMetadata } from '@/types/chatStream'
import { applyMediaJobToMessage, applySearchResults } from '../messageUpdates'
import type { MemberTurn, StreamHandlers, StreamStatusHandler, StreamTurn } from '../streamTurn'

/** Steps that only need a label in the thinking indicator. */
const PIPELINE_PROGRESS_STATUSES = [
  'analyzing_prompt',
  'planning',
  'searching_files',
  'checking_memories',
]

/** Links the user pasted (`fetching_urls`, `urls_fetched`) and result pages of a web search. */
const RESEARCH_STATUSES = ['fetching_urls', 'urls_fetched', 'reading_pages', 'pages_read']

const MEMORIES_COMPLETE_VISIBLE_MS = 2000

type ProgressHandler = (status: string) => StreamStatusHandler<StreamTurn>

const withMessage: ProgressHandler = (status) => (data, turn) =>
  turn.status.set(status, { customMessage: data.message })
const withOptionalMessage: ProgressHandler = (status) => (data, turn) =>
  turn.status.set(status, { customMessage: data.message || undefined })
const withMetadata: ProgressHandler = (status) => (data, turn) =>
  turn.status.set(status, data.metadata || {})
const labelOnly: ProgressHandler = (status) => (_data, turn) => turn.status.set(status, {})
const nothing: StreamStatusHandler<StreamTurn> = () => {}

function nameModel(message: Message | undefined, metadata: StreamEventMetadata | undefined): void {
  if (!message || !metadata) return
  if (typeof metadata.provider === 'string') {
    message.provider = metadata.provider
  }
  if (typeof metadata.model_name === 'string') {
    message.modelLabel = metadata.model_name
  }
}

const classifying: StreamStatusHandler<StreamTurn> = (data, turn) => {
  turn.status.set('classifying', data.metadata || {})
  nameModel(turn.message(), data.metadata)
}

const classified: StreamStatusHandler<StreamTurn> = (data, turn) => {
  turn.status.set('classified', data.metadata || {})
}

/** The reply language picks the voice of a spoken answer. */
const classifiedForMember: StreamStatusHandler<MemberTurn> = (data, turn) => {
  classified(data, turn)
  if (typeof data.metadata?.language === 'string') {
    turn.voice.setLanguage(data.metadata.language)
  }
}

/** Sources arrive seconds before the answer: show them now, above the generating indicator. */
const searchComplete: StreamStatusHandler<StreamTurn> = (data, turn) => {
  turn.status.set('search_complete', data.metadata || {})
  const message = turn.message()
  if (message) {
    applySearchResults(
      message,
      data.metadata?.results,
      typeof data.metadata?.query === 'string' ? data.metadata.query : ''
    )
  }
}

/** `pages_read` also refreshes the sources with their `fetched` flag and the pages-read count. */
const research: ProgressHandler = (status) => (data, turn) => {
  const meta = data.metadata || {}
  turn.status.set(status, {
    customMessage: data.message || undefined,
    pages_total: meta.pages_total,
    pages_read: meta.pages_read,
    urls_total: meta.urls_total,
    urls_read: meta.urls_read,
  })
  if (status !== 'pages_read') return

  const message = turn.message()
  if (!message) return
  if (Array.isArray(meta.results) && meta.results.length > 0) {
    message.searchResults = meta.results as NonNullable<Message['searchResults']>
  }
  const pagesRead = typeof meta.pages_read === 'number' ? meta.pages_read : 0
  message.webSearch = { ...(message.webSearch || {}), pagesRead }
}

const generating: StreamStatusHandler<StreamTurn> = (data, turn) => {
  turn.status.set('generating', {
    customMessage: data.message || undefined,
    ...(data.metadata || {}),
  })
  const message = turn.message()
  if (message && data.metadata) {
    nameModel(message, data.metadata)
    applyMediaJobToMessage(message, data.metadata)
  }
}

/** Staged document generation (officemaker): one line per step, also kept on the answer. */
const documentStep: StreamStatusHandler<StreamTurn> = (data, turn) => {
  const meta = (data.metadata ?? {}) as Record<string, unknown>
  const step = {
    labelKey:
      typeof meta.labelKey === 'string'
        ? meta.labelKey
        : (data.message ?? 'processing.documentStepFailed'),
    labelParams:
      meta.labelParams && typeof meta.labelParams === 'object'
        ? (meta.labelParams as Record<string, unknown>)
        : {},
    ok: meta.ok !== false,
  }
  const current = turn.status.metadata()
  const existing = Array.isArray(current.documentSteps) ? current.documentSteps : []
  turn.status.set('generating_file', {
    ...current,
    stage: 'writing',
    documentSteps: [...existing, step],
  })
  const message = turn.message()
  if (message) {
    message.documentChanges = [...(message.documentChanges ?? []), step]
  }
}

const memoriesComplete: StreamStatusHandler<StreamTurn> = (data, turn) => {
  withOptionalMessage('memories_complete')(data, turn)
  setTimeout(() => {
    if (turn.status.current() === 'memories_complete') {
      turn.status.clear()
    }
  }, MEMORIES_COMPLETE_VISIBLE_MS)
}

function each(statuses: string[], handler: ProgressHandler): StreamHandlers<StreamTurn> {
  return Object.fromEntries(statuses.map((status) => [status, handler(status)]))
}

export const progressHandlers: StreamHandlers<StreamTurn> = {
  started: labelOnly('started'),
  preprocessing: withMessage('preprocessing'),
  analyzing: withMessage('analyzing'),
  editing: withMetadata('editing'),
  ...each(PIPELINE_PROGRESS_STATUSES, labelOnly),
  classifying,
  classified,
  searching: withMessage('searching'),
  search_complete: searchComplete,
  ...each(RESEARCH_STATUSES, research),
  generating,
  generating_file: withMetadata('generating_file'),
  document_step: documentStep,
  processing: nothing,
}

export const guestProgressHandlers: StreamHandlers<StreamTurn> = {
  thinking: labelOnly('thinking'),
}

export const memberProgressHandlers: StreamHandlers<MemberTurn> = {
  classified: classifiedForMember,
  thinking: withOptionalMessage('thinking'),
  analyzing_memories: withOptionalMessage('analyzing_memories'),
  saving_memories: withOptionalMessage('saving_memories'),
  memories_complete: memoriesComplete,
  status: nothing,
}
