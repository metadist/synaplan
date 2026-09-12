/**
 * Progress timeline for a streaming assistant turn.
 *
 * The backend narrates every phase of a turn as an SSE event
 * (`{ status, message, metadata }`). Historically the chat kept only the LAST
 * status, so each phase overwrote the previous one and the user watched a
 * single line flicker through "Generating…", "Planning…", "Generating…" with
 * no sense of progress. This module folds those events into an ordered list of
 * steps: finished ones keep their duration, the active one keeps ticking.
 *
 * Pure functions only — the Vue side (`ProcessingTimeline.vue`) renders the
 * result and `ChatView.vue` feeds it from its stream handlers.
 */
import type { StreamEventMetadata, StreamUpdatePayload } from '@/types/chatStream'

export type TimelineStepState = 'active' | 'done'

/**
 * Logical step a backend status belongs to. Several statuses share one step:
 * `searching` opens the web step and `search_complete` closes it, so the
 * timeline shows one "Searched the web · 8 sources" row, not two.
 */
export type TimelineStepKey =
  | 'preprocessing'
  | 'understand'
  | 'links'
  | 'web'
  | 'pages'
  | 'plan'
  | 'prompt'
  | 'files'
  | 'memories'
  | 'analyzing'
  | 'editing'
  | 'generate'
  | 'file'
  | 'thinking'
  | 'memories_after'

export interface TimelineStep {
  id: number
  key: TimelineStepKey
  /** Most recent backend status that touched this step. */
  status: string
  /** Metadata merged across every event of this step (later wins). */
  metadata: StreamEventMetadata
  /** Latest free-text message from the backend (English, only a fallback). */
  message?: string
  startedAt: number
  endedAt?: number
  state: TimelineStepState
  /** True for steps that start after the answer body began (memory pass). */
  afterAnswer: boolean
}

export interface TimelineModel {
  name?: string
  /** Service key (`anthropic`). */
  provider?: string
  /** Branded provider name (`Anthropic`), when the backend resolved it. */
  providerLabel?: string
}

export interface TimelineState {
  steps: TimelineStep[]
  /** Wall-clock start of the turn (first event). */
  startedAt?: number
  /** Set on the first answer token / plan / media event. */
  answerStartedAt?: number
  /** The model the answer is generated with, once the backend names it. */
  model: TimelineModel
  nextId: number
}

const STEP_BY_STATUS: Record<string, TimelineStepKey> = {
  preprocessing: 'preprocessing',
  classifying: 'understand',
  classified: 'understand',
  fetching_urls: 'links',
  urls_fetched: 'links',
  searching: 'web',
  search_complete: 'web',
  search_failed: 'web',
  reading_pages: 'pages',
  pages_read: 'pages',
  planning: 'plan',
  analyzing_prompt: 'prompt',
  searching_files: 'files',
  checking_memories: 'memories',
  analyzing: 'analyzing',
  editing: 'editing',
  generating: 'generate',
  generated: 'generate',
  generating_file: 'file',
  thinking: 'thinking',
  analyzing_memories: 'memories_after',
  saving_memories: 'memories_after',
  memories_complete: 'memories_after',
}

/** Statuses that finish the step they belong to. */
const CLOSING_STATUSES = new Set([
  'classified',
  'urls_fetched',
  'search_complete',
  'search_failed',
  'pages_read',
  'generated',
  'memories_complete',
])

/** Events that mean "the answer itself has started". */
const ANSWER_STATUSES = new Set(['plan', 'file', 'audio', 'tts_generating', 'links'])

/** Context loaders that only annotate the memory step with what was found. */
const CONTEXT_COUNT_STATUSES: Record<string, string> = {
  memories_loaded: 'memories_count',
  feedback_loaded: 'feedback_count',
  docs_loaded: 'docs_count',
  digests_loaded: 'digests_count',
}

/**
 * A step that finished this fast never did visible work. `preprocessing` is
 * emitted for every turn even without attachments; showing a 0.0 s row would
 * suggest files were involved.
 */
const TRIVIAL_IF_SHORTER_THAN_MS = 250
const TRIVIAL_STEP_KEYS = new Set<TimelineStepKey>(['preprocessing'])

type CloseReason = 'superseded' | 'answer' | 'terminal'

/**
 * The pipeline emits a generic `generating` (default model, no `stage`) right
 * after classification, before the handler narrates its own steps. If a later
 * step supersedes it, it was a placeholder, not a request that went out.
 * The handler's real `generating` carries `stage: 'request_sent'` (or media
 * details) and is always kept.
 */
function isPlaceholderGenerate(step: TimelineStep): boolean {
  return (
    step.key === 'generate' &&
    step.metadata.stage === undefined &&
    step.metadata.media_type === undefined
  )
}

export function cloneTimelineSteps(steps: TimelineStep[]): TimelineStep[] {
  return steps.map((step) => ({
    ...step,
    metadata: { ...step.metadata },
  }))
}

export function cloneTimelineModel(model: TimelineModel): TimelineModel {
  return { ...model }
}

export function createTimelineState(): TimelineState {
  return { steps: [], model: {}, nextId: 1 }
}

export function activeStep(state: TimelineState): TimelineStep | undefined {
  const last = state.steps[state.steps.length - 1]
  return last && last.state === 'active' ? last : undefined
}

export function stepDurationMs(step: TimelineStep, now: number): number {
  return Math.max(0, (step.endedAt ?? now) - step.startedAt)
}

export function preAnswerSteps(state: TimelineState): TimelineStep[] {
  return state.steps.filter((step) => !step.afterAnswer)
}

export function postAnswerSteps(state: TimelineState): TimelineStep[] {
  return state.steps.filter((step) => step.afterAnswer)
}

function closeActive(state: TimelineState, now: number, reason: CloseReason): void {
  const step = activeStep(state)
  if (!step) return
  step.state = 'done'
  step.endedAt = now
  const trivial =
    TRIVIAL_STEP_KEYS.has(step.key) && now - step.startedAt < TRIVIAL_IF_SHORTER_THAN_MS
  if (trivial || (reason === 'superseded' && isPlaceholderGenerate(step))) {
    state.steps.pop()
  }
}

function rememberModel(state: TimelineState, metadata: StreamEventMetadata | undefined): void {
  if (!metadata) return
  const named =
    typeof metadata.model_name === 'string' && metadata.model_name.trim() !== ''
      ? metadata.model_name
      : typeof metadata.model === 'string' && metadata.model.trim() !== ''
        ? metadata.model
        : undefined
  if (!named) return
  state.model = {
    name: named,
    provider: typeof metadata.provider === 'string' ? metadata.provider : state.model.provider,
    providerLabel:
      typeof metadata.provider_label === 'string'
        ? metadata.provider_label
        : state.model.providerLabel,
  }
}

/** Visible answer text after stripping buffered `<think>` blocks. */
export function visibleAnswerText(chunk: string): string {
  return chunk
    .replace(/<think>[\s\S]*?<\/think>/gi, '')
    .replace(/<think>[\s\S]*$/i, '')
    .trim()
}

function annotateMemoryStep(state: TimelineState, field: string, count: number): void {
  for (let i = state.steps.length - 1; i >= 0; i--) {
    const step = state.steps[i]
    if (step.key === 'memories') {
      step.metadata = { ...step.metadata, [field]: count }
      return
    }
  }
}

function countFromPayload(payload: StreamUpdatePayload): number | undefined {
  const meta = payload.metadata
  if (!meta) return undefined
  if (typeof meta.count === 'number') return meta.count
  for (const key of ['memories', 'feedbacks', 'docs', 'digests'] as const) {
    const rows = meta[key]
    if (Array.isArray(rows)) return rows.length
  }
  return undefined
}

/**
 * Fold one stream event into the timeline. Mutates `state` in place (the
 * caller owns the reactive wrapper) and returns it for convenience.
 */
export function ingestTimelineEvent(
  state: TimelineState,
  payload: StreamUpdatePayload,
  now: number = Date.now()
): TimelineState {
  const status = payload.status
  if (typeof status !== 'string') return state

  if (status === 'started') {
    const fresh = createTimelineState()
    fresh.startedAt = now
    Object.assign(state, fresh)
    return state
  }

  state.startedAt ??= now

  if (status === 'complete' || status === 'error') {
    closeActive(state, now, 'terminal')
    return state
  }

  if (status === 'data' && typeof payload.chunk === 'string' && payload.chunk !== '') {
    if (visibleAnswerText(payload.chunk) === '') {
      return state
    }
    if (state.answerStartedAt === undefined) {
      state.answerStartedAt = now
      closeActive(state, now, 'answer')
    }
    return state
  }

  if (ANSWER_STATUSES.has(status)) {
    if (state.answerStartedAt === undefined) {
      state.answerStartedAt = now
      closeActive(state, now, 'answer')
    }
    return state
  }

  if (status === 'reasoning') {
    // Live reasoning tokens: the model is thinking even if no `thinking`
    // status preceded them (older backends, replayed runs).
    if (activeStep(state)?.key !== 'thinking') {
      openStep(state, 'thinking', payload, now)
    }
    return state
  }

  const countField = CONTEXT_COUNT_STATUSES[status]
  if (countField) {
    const count = countFromPayload(payload)
    if (typeof count === 'number') annotateMemoryStep(state, countField, count)
    return state
  }

  const key = STEP_BY_STATUS[status]
  if (!key) return state

  // The sorter names itself on `classifying`; that is not the answering model.
  if (key !== 'understand') {
    rememberModel(state, payload.metadata)
  }

  const current = activeStep(state)
  if (current && current.key === key) {
    current.status = status
    current.metadata = { ...current.metadata, ...(payload.metadata ?? {}) }
    if (typeof payload.message === 'string' && payload.message !== '') {
      current.message = payload.message
    }
    if (CLOSING_STATUSES.has(status)) {
      current.state = 'done'
      current.endedAt = now
    }
    return state
  }

  if (CLOSING_STATUSES.has(status)) {
    const existing = [...state.steps].reverse().find((step) => step.key === key)
    if (existing) {
      // Trailing `generated` after reasoning: the generate row is no longer
      // last. Update it instead of opening a duplicate after-answer row.
      existing.status = status
      existing.metadata = { ...existing.metadata, ...(payload.metadata ?? {}) }
      if (existing.state === 'active') {
        existing.state = 'done'
        existing.endedAt = now
      }
      return state
    }
  }

  openStep(state, key, payload, now)
  if (CLOSING_STATUSES.has(status)) {
    // A closing status without its opener (fixed-prompt turns emit only
    // `classified`): record the step as already finished.
    const step = activeStep(state)
    if (step) {
      step.state = 'done'
      step.endedAt = now
    }
  }
  return state
}

function openStep(
  state: TimelineState,
  key: TimelineStepKey,
  payload: StreamUpdatePayload,
  now: number
): void {
  closeActive(state, now, 'superseded')
  state.steps.push({
    id: state.nextId++,
    key,
    status: typeof payload.status === 'string' ? payload.status : key,
    metadata: { ...(payload.metadata ?? {}) },
    message:
      typeof payload.message === 'string' && payload.message !== '' ? payload.message : undefined,
    startedAt: now,
    state: 'active',
    afterAnswer: state.answerStartedAt !== undefined,
  })
}

/**
 * Build a single-step timeline from a bare status, for callers that only know
 * the current status (tests, callers that do not feed the reducer).
 */
export function timelineFromStatus(
  status: string,
  metadata: StreamEventMetadata | null | undefined,
  now: number = Date.now()
): TimelineState {
  const state = createTimelineState()
  state.startedAt = now
  const key = STEP_BY_STATUS[status]
  if (!key) return state
  state.steps.push({
    id: state.nextId++,
    key,
    status,
    metadata: { ...(metadata ?? {}) },
    startedAt: now,
    state: 'active',
    afterAnswer: false,
  })
  rememberModel(state, metadata ?? undefined)
  return state
}

/** Seconds with one decimal below 10 s, whole seconds above. */
export function formatDurationSeconds(ms: number): string {
  const seconds = ms / 1000
  if (seconds < 10) return `${seconds.toFixed(1)}s`
  return `${Math.round(seconds)}s`
}
