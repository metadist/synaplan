/**
 * How a signed-in turn fails: a dropped connection is not a failure, a gone
 * chat and a limit remove the empty answer and say why, a Stop is a neutral
 * notice, and every other error stores the backend's classified sentence on
 * the answer. Only that sentence is shown; transport strings never are.
 */
import type { LimitCheckResult } from '@/composables/useLimitCheck'
import type { Message } from '@/stores/history'
import type { StreamUpdatePayload } from '@/types/chatStream'
import {
  chatErrorSuggestsOtherModel,
  shouldFinishWithoutErrorOnTransportDrop,
} from '@/utils/chatErrorDisplay'
import { isCancellationError, isRecoverableStreamError } from '@/utils/streamError'
import { applyAssistantChatModelFooter, applyRouting } from '../messageUpdates'
import type { MemberTurn, StreamStatusHandler } from '../streamTurn'

const CHAT_GONE_TOAST_MS = 5000

function limitTypeOf(data: StreamUpdatePayload): LimitCheckResult['limitType'] {
  return data.limit_type === 'hourly' ||
    data.limit_type === 'monthly' ||
    data.limit_type === 'lifetime'
    ? data.limit_type
    : 'lifetime'
}

function hasAnswerText(message: Message | undefined): boolean {
  return !!message?.parts.some(
    (part) => part.type === 'text' && part.content && part.content.trim() !== ''
  )
}

/** The turn is over for this view: Stop has nothing left to cancel, an edit returns to its stored text. */
function endTurn(turn: MemberTurn): void {
  turn.run.release()
  turn.outcome.restoreUnpersistedEdit()
}

/** A pure transport drop (#1265): the stored chat is reconciled, an incognito draft stays as it is. */
function finishedOnTransportDrop(turn: MemberTurn): boolean {
  const canReconcile = !turn.incognito && Boolean(turn.chatId)
  if (!shouldFinishWithoutErrorOnTransportDrop({ canReconcile, message: turn.message() }))
    return false

  turn.history.finishStreamingMessage(turn.messageId)
  if (canReconcile && turn.chatId) {
    void turn.history.recoverInterruptedTurn(turn.chatId)
  }
  turn.run.release()

  return true
}

/** Status, model and topic stay visible on the failed answer without a reload. */
function applyFailureDetails(data: StreamUpdatePayload, turn: MemberTurn): void {
  const message = turn.message()
  if (!message) return
  if (message.taskPlan) {
    message.taskPlan.active = false
  }
  if (data.messageId) {
    message.backendMessageId = data.messageId
  }
  applyRouting(message, data)
  applyAssistantChatModelFooter(
    message,
    {
      provider: data.provider,
      model: data.model,
      model_id: data.model_id ?? null,
      aiModels: data.aiModels ?? null,
    },
    turn.fallbackModel
  )
}

function endAsChatGone(turn: MemberTurn): void {
  turn.history.removeMessage(turn.messageId)
  turn.outcome.showError(turn.t('chat.notFound'), CHAT_GONE_TOAST_MS)
  endTurn(turn)
}

/** The prompt stays, marked rate-limited, next to the limit modal. */
function endAsRateLimited(data: StreamUpdatePayload, turn: MemberTurn): void {
  turn.history.removeMessage(turn.messageId)
  const userLevel = data.user_level || turn.outcome.userLevel()
  const prompts = turn.history.messages.filter((message) => message.role === 'user')
  const prompt = prompts[prompts.length - 1]
  if (prompt) {
    turn.history.setMessageStatus(prompt.id, 'rate_limited', 'rate_limit', {
      limitType: limitTypeOf(data),
      actionType: data.action_type || 'MESSAGES',
      used: data.used || 0,
      limit: data.limit || 0,
      remaining: data.remaining || 0,
      resetAt: data.reset_at || null,
      userLevel,
    })
  }
  turn.outcome.showLimit({
    allowed: false,
    limitType: limitTypeOf(data),
    actionType: data.action_type || 'MESSAGES',
    used: data.used || 0,
    limit: data.limit || 0,
    remaining: data.remaining || 0,
    resetTime: data.reset_at || null,
    userLevel,
    phoneVerified: data.phone_verified || false,
  })
  endTurn(turn)
}

/** The monthly cost budget is spent: the modal offers a one-time top-up. */
function endAsBudgetExceeded(data: StreamUpdatePayload, turn: MemberTurn): void {
  turn.history.removeMessage(turn.messageId)
  turn.outcome.showLimit({
    allowed: false,
    limitType: 'monthly',
    actionType: data.action_type || 'MESSAGES',
    used: Number(data.used) || 0,
    limit: Number(data.limit) || 0,
    remaining: Number(data.remaining) || 0,
    resetTime: null,
    userLevel: data.user_level || turn.outcome.userLevel(),
    phoneVerified: data.phone_verified || false,
    topupAvailable: data.topup_available === true,
  })
  endTurn(turn)
}

/** The same translated notice as the Stop button, never the backend's English string. */
function endAsCancelled(turn: MemberTurn): void {
  if (!hasAnswerText(turn.message())) {
    turn.history.updateStreamingMessage(turn.messageId, turn.t('message.cancelledByUser'))
  }
  turn.history.finishStreamingMessage(turn.messageId)
  const chatId = turn.chats.streamingChatId()
  if (chatId) {
    turn.chats.clearLocalTurnFinished(chatId)
  }
  endTurn(turn)
}

/** The localized text is also the stored message body, so the live bubble matches the reload. */
function endAsFailure(data: StreamUpdatePayload, errorText: string, turn: MemberTurn): void {
  const message = turn.message()
  const answered = hasAnswerText(message)
  if (message) {
    const classified = typeof data.errorReason === 'string' && data.errorReason !== ''
    message.errorReason = classified ? (data.errorReason as string) : 'unknown'
    message.canRetryModel =
      data.canRetryModel !== false && chatErrorSuggestsOtherModel(message.errorReason)
    message.errorDebug = typeof data.errorDebug === 'string' ? data.errorDebug : null
    if (classified && typeof data.error === 'string' && data.error.trim() !== '') {
      message.errorMessage = data.error
    }
  }
  if (!answered) {
    turn.history.updateStreamingMessage(turn.messageId, errorText)
  }
  turn.history.finishStreamingMessage(turn.messageId)
  if (typeof data.messageId === 'number') {
    turn.outcome.recordVersion(data.messageId)
  } else {
    turn.outcome.restoreUnpersistedEdit()
  }
  turn.run.release()
}

/** Matches control flow on the raw string: unclassified events (chat gone, rate limit) carry no reason. */
export const memberErrorHandler: StreamStatusHandler<MemberTurn> = (data, turn) => {
  turn.smoother.flush()
  turn.smoother.cancel()
  const rawError = typeof data.error === 'string' ? data.error : ''
  const classified = typeof data.errorReason === 'string' && data.errorReason.trim() !== ''
  const errorText =
    classified && rawError.trim() !== '' ? rawError : turn.t('chatError.reason.unknown')
  console.error('Error:', errorText, data)
  turn.status.clear()

  if (isRecoverableStreamError(data) && finishedOnTransportDrop(turn)) return
  applyFailureDetails(data, turn)

  const raw = rawError.toLowerCase()
  if (raw.includes('chat not found') || raw.includes('access denied')) return endAsChatGone(turn)
  if (raw.includes('rate limit')) return endAsRateLimited(data, turn)
  if (
    data.code === 'COST_BUDGET_EXCEEDED' ||
    (data.limit_type === 'monthly' && data.topup_available === true)
  ) {
    return endAsBudgetExceeded(data, turn)
  }
  if (isCancellationError(data)) return endAsCancelled(turn)
  endAsFailure(data, errorText, turn)
}
