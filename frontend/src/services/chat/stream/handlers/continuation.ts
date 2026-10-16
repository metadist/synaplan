/**
 * A cut-off answer picking up where it stopped: the new text and reasoning
 * append to the existing bubble, and a failure keeps the answer marked as
 * cut off so Continue stays offered.
 */
import type { Message } from '@/stores/history'
import type { UsageTotals } from '@/stores/usageTaximeter'
import { appendReasoning, applyDocsToMessage } from '../messageUpdates'
import type { ContinueTurn, StreamHandlers, StreamStatusHandler } from '../streamTurn'

const data: StreamStatusHandler<ContinueTurn> = (payload, turn) => {
  if (!payload.chunk) return
  turn.text.content += payload.chunk
  turn.smoother.push(turn.text.content)
}

const reasoning: StreamStatusHandler<ContinueTurn> = (payload, turn) => {
  const message = turn.history.messages.find((candidate) => candidate.id === turn.message.id)
  if (message && payload.chunk) {
    appendReasoning(message, payload.chunk, 'last')
  }
}

/** A continued answer is a billed turn; the stored message is the source of truth (#1070). */
const complete: StreamStatusHandler<ContinueTurn> = (payload, turn) => {
  const message = turn.message
  turn.smoother.flush()
  turn.renderContent(turn.text.content)
  if (payload.truncated) {
    message.truncated = true
  }
  applyDocsToMessage(message, payload.docs)
  message.isStreaming = false
  turn.history.finishStreamingMessage(message.id)

  if (turn.usage.active) {
    turn.usage.applyComplete(
      (payload.usage ?? null) as Message['usage'],
      (payload.usage_totals ?? null) as UsageTotals | null,
      (payload.usage_extra ?? null) as Message['usageExtra']
    )
    turn.usage.refreshAfterSettlement()
  }
  if (message.backendMessageId) {
    void turn.history.reconcileMessage(message.id, message.backendMessageId)
  }
}

const error: StreamStatusHandler<ContinueTurn> = (_payload, turn) => {
  turn.smoother.flush()
  turn.smoother.cancel()
  turn.message.truncated = true
  turn.message.isStreaming = false
  turn.history.finishStreamingMessage(turn.message.id)
  turn.showError(turn.t('chat.continueFailed'))
}

export const continuationHandlers: StreamHandlers<ContinueTurn> = {
  data,
  reasoning,
  complete,
  error,
}
