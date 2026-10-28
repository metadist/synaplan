/**
 * Everything a stream status handler may touch for one turn. Each side effect
 * is named, so a unit test replaces the whole turn with a fake: the assistant
 * message being written, the view's processing indicator, the text received
 * so far, and the view's own reactions.
 */
import type { LimitCheckResult } from '@/composables/useLimitCheck'
import type { Feedback } from '@/services/api/userFeedbackApi'
import type { Message } from '@/stores/history'
import type { UsageTotals } from '@/stores/usageTaximeter'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import type { StreamUpdatePayload } from '@/types/chatStream'
import type { SmoothStream } from '@/utils/smoothStream'
import type { VoiceReplyStream } from './voiceReplyStream'

export type StreamingProcessingMetadata = {
  model_name?: string
  provider?: string
  topic?: string
  language?: string
  customMessage?: string
  results_count?: number
  pages_total?: number
  pages_read?: number
  urls_total?: number
  urls_read?: number
  handler?: string
  stage?: string
  filename?: string
  documentSteps?: Array<{
    labelKey: string
    labelParams?: Record<string, unknown>
    ok?: boolean
  }>
  [key: string]: unknown
}

/** The answer text received so far, and whether a `<think>` block is still open in it. */
export interface StreamText {
  content: string
  thinkOpen: boolean
}

/** The model shown when the server names none. */
export interface StreamModelFallback {
  provider?: string
  model?: string
  model_id?: number | null
}

export interface ProcessingIndicator {
  set(status: string, metadata?: StreamingProcessingMetadata): void
  clear(): void
  current(): string
  metadata(): StreamingProcessingMetadata
}

export interface StreamHistory {
  readonly messages: Message[]
  finishLiveThinking(messageId: string): void
  finishStreamingMessage(messageId: string): void
  updateStreamingMessage(messageId: string, content: string): void
  removeMessage(messageId: string): void
  setMessageStatus(
    messageId: string,
    status: NonNullable<Message['status']>,
    errorType?: NonNullable<Message['errorType']>,
    errorData?: Message['errorData']
  ): void
  reconcileMessage(messageId: string, backendMessageId: number): Promise<unknown>
  recoverInterruptedTurn(chatId: number): Promise<unknown>
}

/** The resumable run behind the turn and the module state that tracks it. */
export interface StreamRun {
  ingestTimeline(data: StreamUpdatePayload): void
  noteStarted(runId: unknown): void
  noteTerminal(data: StreamUpdatePayload): void
  trackId(): number | undefined
  /** Forget the turn: no Stop, no abort, no chat to clear any more. */
  release(): void
}

export interface StreamUsage {
  readonly active: boolean
  applyComplete(
    usage: Message['usage'],
    totals: UsageTotals | null,
    usageExtra: Message['usageExtra']
  ): void
  loadSummary(): Promise<unknown>
  refreshAfterSettlement(): void
}

export interface StreamTurn {
  readonly messageId: string
  readonly text: StreamText
  readonly smoother: SmoothStream
  readonly fallbackModel: StreamModelFallback
  readonly status: ProcessingIndicator
  readonly history: StreamHistory
  readonly run: StreamRun
  readonly usage: StreamUsage
  message(): Message | undefined
  isAborted(): boolean
  /** Paint the answer text without ever letting the bubble shrink. */
  render(text: string): void
  /** Paint raw streamed content (thinking blocks, markers, envelopes). */
  renderContent(content: string): void
  ingestApprovalRequired(data: StreamUpdatePayload): void
  scrollToBottom(): void
  t(key: string, params?: Record<string, unknown>): string
}

export interface MemoryBadges {
  readonly memories: { memories: UserMemory[] }
  readonly feedbacks: { feedbacks: Feedback[]; getFeedbackById(id: number): Feedback | undefined }
  readonly digests: {
    addReferences(
      refs: Array<{
        messageId: number
        chatId: number
        title: string
        channel: string
        sourceDate: number
      }>
    ): void
  }
  suggest(memory: UserMemory): void
  offerDelete(memory: UserMemory): void
}

export interface ChatActivity {
  applyChatTitle(chatId: number, title: string): void
  bumpChatActivity(chatId: number): void
  clearLocalTurnFinished(chatId: number): void
  /** The chat the turn streams into, while Stop can still reach it. */
  streamingChatId(): number | undefined
}

/** What a signed-in turn reports back to the conversation once it ends. */
export interface TurnOutcome {
  recordVersion(backendMessageId: number): void
  restoreUnpersistedEdit(): void
  /** A busy refusal: text back into the composer, with a Try again that resends it. */
  busyRefused(text: string): void
  scheduleMemoryPoll(backendMessageId: number): void
  showError(message: string, durationMs?: number): void
  showLimit(notice: LimitCheckResult): void
  userLevel(): string
}

/** The research the chat started on its own for this turn (`research_started`). */
export interface TurnResearch {
  adopt(runId: string): void
}

export interface MemberTurn extends StreamTurn {
  readonly chatId: number | undefined
  readonly incognito: boolean
  readonly voice: VoiceReplyStream
  readonly memory: MemoryBadges
  readonly chats: ChatActivity
  readonly outcome: TurnOutcome
  readonly research: TurnResearch
  registerIncognitoFile(fileId: number): void
}

export interface GuestTurn extends StreamTurn {
  readonly guest: {
    updateCount(remaining: number, max: number, reached: boolean): void
    showBanner(): void
    /** Offer the upgrade sheet, or the plain signup modal without a purchase channel. */
    offerSignup(): void
  }
}

/** A cut-off answer picking up where it stopped. */
export interface ContinueTurn {
  readonly message: Message
  readonly text: StreamText
  readonly smoother: SmoothStream
  readonly history: StreamHistory
  readonly usage: StreamUsage
  renderContent(content: string): void
  showError(message: string): void
  t(key: string, params?: Record<string, unknown>): string
}

export type StreamStatusHandler<T> = (data: StreamUpdatePayload, turn: T) => void

export type StreamHandlers<T> = Readonly<Record<string, StreamStatusHandler<T>>>
