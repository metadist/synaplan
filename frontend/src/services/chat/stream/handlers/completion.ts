/**
 * How a turn ends on the wire: `complete` renders the final answer and stores
 * what the server reports about it, `error` names the failure in one honest
 * sentence. Each variant states only where it differs: a guest has no chat to
 * name, no version to link and no limit modal beyond the trial.
 */
import { nextTick } from 'vue'
import type { Message } from '@/stores/history'
import type { UsageTotals } from '@/stores/usageTaximeter'
import type { StreamGeneratedFile, StreamUpdatePayload } from '@/types/chatStream'
import { chatErrorSuggestsOtherModel } from '@/utils/chatErrorDisplay'
import {
  applyAnswerSources,
  applyAssistantChatModelFooter,
  applyDocumentComplete,
  applyGeneratedFile,
  applyMediaJobToMessage,
  applyRouting,
  generatingTokenForMediaJob,
} from '../messageUpdates'
import type {
  GuestTurn,
  MemberTurn,
  StreamHandlers,
  StreamStatusHandler,
  StreamTurn,
} from '../streamTurn'
import { memberErrorHandler } from './memberError'

/** Everything received, painted at once; a media job without text shows its generating token. */
function renderFinalAnswer(data: StreamUpdatePayload, turn: StreamTurn): void {
  turn.smoother.flush()
  if (turn.text.content) {
    turn.render(turn.text.content)
  } else if (data.mediaJob || data.media_job) {
    turn.renderContent(generatingTokenForMediaJob(data.mediaJob ?? data.media_job))
  }
}

/** The server's measured seconds replace the live clock, so reload shows the same number. */
function applyMeasuredThinking(message: Message, data: StreamUpdatePayload): void {
  if (typeof data.thinking_seconds !== 'number' || data.thinking_seconds <= 0) {
    return
  }
  const part = message.parts.find((candidate) => candidate.type === 'thinking')
  if (part) {
    part.thinkingTime = data.thinking_seconds
  }
}

/** What both variants store on a finished answer. The finished plan freezes its cards. */
function applyFinishedAnswer(message: Message, data: StreamUpdatePayload, turn: StreamTurn): void {
  applyMeasuredThinking(message, data)
  applyMediaJobToMessage(message, data.mediaJob ?? data.media_job)
  if (message.taskPlan) {
    message.taskPlan.active = false
  }
  if (data.messageId) {
    message.backendMessageId = data.messageId
  }
  applyDocumentComplete(message, data)
  applyAnswerSources(message, data)
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

/** This turn's usage on the answer badge, folded into the session taximeter; `null` while it is off. */
function applyTurnUsage(
  message: Message,
  data: StreamUpdatePayload,
  turn: StreamTurn
): { totals: UsageTotals | null } | null {
  if (!turn.usage.active) return null

  const usage = (data.usage ?? null) as Message['usage']
  const usageExtra = (data.usage_extra ?? null) as Message['usageExtra']
  const totals = (data.usage_totals ?? null) as UsageTotals | null
  if (usage) {
    message.usage = usage
  }
  if (usageExtra && usageExtra.length > 0) {
    message.usageExtra = usageExtra
  }
  turn.usage.applyComplete(usage, totals, usageExtra)

  return { totals }
}

/** Guests cannot reconcile against the stored message, so the streamed state is final. */
const completeForGuest: StreamStatusHandler<GuestTurn> = (data, turn) => {
  if (data.truncated) {
    turn.text.content += '\n\n---\n\n⚠️ *' + turn.t('message.truncated') + '*'
  }
  renderFinalAnswer(data, turn)
  turn.status.clear()

  const message = turn.message()
  if (message) {
    applyFinishedAnswer(message, data, turn)
    if (data.generatedFile) {
      applyGeneratedFile(
        message,
        data.generatedFile,
        turn.t('message.fileGenerated', { filename: data.generatedFile.filename })
      )
    }
    const usage = applyTurnUsage(message, data, turn)
    if (usage && !usage.totals) {
      void turn.usage.loadSummary()
    }
  }
  turn.history.finishStreamingMessage(turn.messageId)
  turn.scrollToBottom()
}

/** An incognito document is ephemeral: the session end deletes it. */
function attachGeneratedFileForMember(
  message: Message,
  file: StreamGeneratedFile,
  turn: MemberTurn
): void {
  if (turn.incognito) {
    turn.registerIncognitoFile(file.id)
  }
  applyGeneratedFile(message, file, turn.t('message.fileGenerated', { filename: file.filename }))
  // FIXME: cargo-cult reactivity — the store already tracks these mutations;
  // replacing the row only re-keys the bubble.
  nextTick(() => {
    const index = turn.history.messages.findIndex((candidate) => candidate.id === message.id)
    if (index !== -1) {
      turn.history.messages.splice(index, 1, {
        ...message,
        files: message.files ? [...message.files] : undefined,
        parts: [...message.parts],
        timestamp: new Date(message.timestamp),
      })
    }
  })
}

/** A classified failure stored on an answer that still completed (e.g. a fallback reply). */
function applyCompletedFailure(
  message: Message,
  data: StreamUpdatePayload,
  turn: MemberTurn
): void {
  if (typeof data.errorReason === 'string' && data.errorReason !== '') {
    message.errorReason = data.errorReason
    message.canRetryModel =
      data.canRetryModel !== false && chatErrorSuggestsOtherModel(data.errorReason)
    message.errorDebug = typeof data.errorDebug === 'string' ? data.errorDebug : null
    if (typeof data.error === 'string' && data.error.trim() !== '') {
      message.errorMessage = data.error
    }
  }
  if (data.error_hint === 'vision_model_required') {
    const hint =
      '\n\n---\n\n**💡 ' +
      turn.t('aiProvider.error.visionModelHint') +
      '**\n\n' +
      turn.t('aiProvider.error.visionModelHintDetail')
    message.parts.push({ type: 'text', content: hint })
  }
}

function applyFinishedAnswerForMember(
  message: Message,
  data: StreamUpdatePayload,
  turn: MemberTurn
): void {
  if (data.truncated) {
    message.truncated = true
  }
  // TTS may have failed silently: no loading indicator may outlive the turn.
  message.parts = message.parts.filter((part) => part.type !== 'tts_loading')
  if (data.generatedFile) {
    attachGeneratedFileForMember(message, data.generatedFile, turn)
  }
  applyFinishedAnswer(message, data, turn)
  if (Array.isArray(data.memoryIds) && data.memoryIds.length > 0) {
    message.memoryIds = data.memoryIds
  }
  if (Array.isArray(data.feedbackIds) && data.feedbackIds.length > 0) {
    message.feedbackIds = data.feedbackIds
  }
  if (applyTurnUsage(message, data, turn)) {
    turn.usage.refreshAfterSettlement()
  }
  applyRouting(message, data)
  applyCompletedFailure(message, data, turn)
  message.parts.forEach((part) => {
    if (part.type === 'thinking' && part.isStreaming) {
      delete part.isStreaming
    }
  })
}

/**
 * Incognito persisted nothing: no title, no sidebar bump, no reconcile, no
 * memory poll. Otherwise the stored message is the source of truth (#1070)
 * and the worker's memory extraction is polled for.
 */
function settleStoredTurn(data: StreamUpdatePayload, turn: MemberTurn): void {
  const chatId = turn.chatId
  if (!turn.incognito && chatId) {
    if (data.chatTitle) {
      turn.chats.applyChatTitle(chatId, data.chatTitle)
    }
    turn.chats.bumpChatActivity(chatId)
  }
  turn.history.finishStreamingMessage(turn.messageId)
  if (typeof data.messageId === 'number') {
    turn.outcome.recordVersion(data.messageId)
  } else {
    turn.outcome.restoreUnpersistedEdit()
  }
  if (data.messageId && !turn.incognito) {
    void turn.history.reconcileMessage(turn.messageId, data.messageId)
    turn.outcome.scheduleMemoryPoll(data.messageId)
  }
}

/** One render tick: the final text, the cleared status and `isStreaming = false` land together. */
const completeForMember: StreamStatusHandler<MemberTurn> = (data, turn) => {
  renderFinalAnswer(data, turn)
  turn.voice.finish()
  turn.status.clear()

  const message = turn.message()
  if (message) {
    applyFinishedAnswerForMember(message, data, turn)
  } else {
    console.error('❌ Could not find message with id:', turn.messageId)
  }
  settleStoredTurn(data, turn)
  turn.run.release()
}

const errorForGuest: StreamStatusHandler<GuestTurn> = (_data, turn) => {
  turn.smoother.flush()
  turn.smoother.cancel()
  turn.status.clear()
  const message = turn.message()
  if (message?.taskPlan) {
    message.taskPlan.active = false
  }
  turn.history.finishStreamingMessage(turn.messageId)
}

export const guestCompletionHandlers: StreamHandlers<GuestTurn> = {
  complete: completeForGuest,
  error: errorForGuest,
}

export const memberCompletionHandlers: StreamHandlers<MemberTurn> = {
  complete: completeForMember,
  error: memberErrorHandler,
}
