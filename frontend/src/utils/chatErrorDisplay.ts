import type { Message, TaskCardState, TaskPlanState } from '@/stores/history'

/**
 * Machine-readable chat failure reasons the notice can explain without
 * leaking provider internals. Mirrors `ChatFailureReason` plus the client-only
 * `empty_answer` used when a turn settled on the server but never saved a reply.
 */
export const CHAT_ERROR_REASONS = [
  'schema_mismatch',
  'context_length_exceeded',
  'request_too_large',
  'rate_limited',
  'quota_exceeded',
  'auth_failed',
  'model_unavailable',
  'content_filtered',
  'timeout',
  'upstream_unavailable',
  'unknown',
  'empty_answer',
] as const

export type ChatErrorReason = (typeof CHAT_ERROR_REASONS)[number]

const KNOWN_REASONS = new Set<string>(CHAT_ERROR_REASONS)

const TERMINAL_CARD_STATES = new Set<TaskCardState>(['done', 'failed', 'skipped', 'cancelled'])

export function normalizeChatErrorReason(reason: string | null | undefined): ChatErrorReason {
  if (reason && KNOWN_REASONS.has(reason)) {
    return reason as ChatErrorReason
  }
  return 'unknown'
}

export function chatErrorReasonKey(reason: string | null | undefined): string {
  return `chatError.reason.${normalizeChatErrorReason(reason)}`
}

/** Auth and quota are operator/account problems — switching model will not help. */
export function chatErrorSuggestsOtherModel(reason: string | null | undefined): boolean {
  const normalized = normalizeChatErrorReason(reason)
  return normalized !== 'auth_failed' && normalized !== 'quota_exceeded'
}

export function isSettledTaskPlan(plan: TaskPlanState | null | undefined): plan is TaskPlanState {
  if (!plan || plan.cards.length === 0) {
    return false
  }
  return plan.cards.every((card) => TERMINAL_CARD_STATES.has(card.state))
}

export function taskPlanHasVisibleOutput(plan: TaskPlanState | null | undefined): boolean {
  if (!plan) {
    return false
  }
  return plan.cards.some((card) => {
    if (typeof card.text === 'string' && card.text.trim() !== '') {
      return true
    }
    if (typeof card.url === 'string' && card.url.trim() !== '') {
      return true
    }
    if (card.kind === 'search' && (card.query || (card.resultsCount ?? 0) > 0)) {
      return true
    }
    return false
  })
}

function collectTaskPlanDraftText(plan: TaskPlanState): string {
  return plan.cards
    .map((card) => (typeof card.text === 'string' ? card.text.trim() : ''))
    .filter((text) => text !== '')
    .join('\n\n')
}

function partsHaveRenderableContent(parts: Message['parts']): boolean {
  return parts.some(
    (part) =>
      (typeof part.content === 'string' && part.content.trim() !== '') ||
      Boolean(part.url) ||
      Boolean(part.imageUrl)
  )
}

/**
 * A still-running user turn whose task cards have all settled, with no live
 * stream to attach to, will never grow an answer in this tab. Stop the
 * "just a moment" wait and — when nothing visible was produced — surface the
 * same recovery the live error notice uses.
 */
export function finalizeSettledInProgressTurn(
  message: Message,
  hasActiveRun: boolean
): { message: Message; stalled: boolean } {
  if (hasActiveRun || !isSettledTaskPlan(message.taskPlan)) {
    return { message, stalled: false }
  }

  const plan = message.taskPlan
  const draft = collectTaskPlanDraftText(plan)
  const hasOutput = taskPlanHasVisibleOutput(plan)
  const parts =
    draft !== '' && !partsHaveRenderableContent(message.parts)
      ? [{ type: 'text' as const, content: draft }]
      : message.parts

  const next: Message = {
    ...message,
    parts,
    isStreaming: false,
    taskPlan: { ...plan, active: false },
  }

  if (!hasOutput) {
    next.errorReason = next.errorReason ?? 'empty_answer'
    next.canRetryModel = true
  }

  return { message: next, stalled: true }
}
