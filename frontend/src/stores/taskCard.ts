import type { FolderRead } from '@/utils/folderRead'
import type { SearchRecovery } from '@/utils/searchRecovery'

export const TASK_CARD_KINDS = [
  'text',
  'image',
  'video',
  'audio',
  'document',
  'search',
  'extract',
  'email',
  'folder',
  'compute',
] as const
export type TaskCardKind = (typeof TASK_CARD_KINDS)[number]

export const TASK_CARD_STATES = [
  'pending',
  'running',
  'done',
  'failed',
  'skipped',
  'cancelled',
  'waiting_approval',
] as const
export type TaskCardState = (typeof TASK_CARD_STATES)[number]

/** Runtime guards for values arriving over SSE — never trust the wire. */
export function isTaskCardKind(value: unknown): value is TaskCardKind {
  return typeof value === 'string' && (TASK_CARD_KINDS as readonly string[]).includes(value)
}

export function isTaskCardState(value: unknown): value is TaskCardState {
  return typeof value === 'string' && (TASK_CARD_STATES as readonly string[]).includes(value)
}

export interface TaskCard {
  nodeId: string
  capability: string
  kind: TaskCardKind
  state: TaskCardState
  /** Upstream step ids this step waited on (empty/undefined for roots). */
  dependsOn?: string[]
  text?: string
  url?: string
  mediaType?: string
  // Failure details from the `task_update` SSE event (failed/skipped states).
  error?: string
  // Resolved generation prompt of a failed media node — payload for the
  // "retry this step with the next model" action.
  prompt?: string
  // Web search card compact summary — populated by WebSearchRunner/DagExecutor
  // so the card shows "Searched the web · N sources" instead of the raw dump.
  query?: string
  resultsCount?: number
  // Live media-generation progress from the `task_progress` SSE event: a 0-100
  // estimate, the provider's coarse status, and elapsed seconds. Drive a moving
  // bar instead of a static spinner while a video renders.
  progressPercent?: number
  providerStatus?: string
  elapsedSeconds?: number
  /** Async media job key when the node detached to a background worker. */
  jobId?: string
  /** True when this run mounted the user's persistent file-work folder. */
  usedWorkspace?: boolean
  /** Sanitized parameters sent to the step, capped. */
  stepInput?: string
  /** Capped output or error text for the expanded step. */
  stepOutput?: string
  stepOutputTruncated?: boolean
  durationMs?: number
  /** What a folder-read step read, live while it runs and after it settles. */
  folderRead?: FolderRead
  /** Empty-search widening: the queries tried, and which one matched. */
  recovery?: SearchRecovery
  /** True while a wider query is in flight. */
  retrying?: boolean
  /**
   * #1229 smart collapse: the card's prose is already contained in the final
   * answer body, so the card collapses to its header (set by ResultAssembler
   * at assembly time and by markRedundantTaskPlanProse client-side).
   */
  redundant?: boolean
  askUser?: {
    question: string
    options: string[]
    recommended: string
    allowText: boolean
    expiresAt: number
  }
}
