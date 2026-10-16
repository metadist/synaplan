import { vi } from 'vitest'
import type { Message } from '@/stores/history'
import type {
  ContinueTurn,
  GuestTurn,
  MemberTurn,
  StreamTurn,
} from '@/services/chat/stream/streamTurn'
import { VoiceReplyStream } from '@/services/chat/stream/voiceReplyStream'

/**
 * Stream turns for handler tests: a real message object, a processing
 * indicator that remembers what was set, and a spy for every side effect.
 */

interface Indicated {
  indicator: { status: string; metadata: Record<string, unknown> }
}

export function assistantMessage(overrides: Partial<Message> = {}): Message {
  return {
    id: 'assistant-1',
    role: 'assistant',
    parts: [{ type: 'text', content: '' }],
    timestamp: new Date('2026-10-16T12:00:00Z'),
    isStreaming: true,
    ...overrides,
  } as Message
}

export function fakeTurn(message: Message = assistantMessage()): StreamTurn & Indicated {
  const indicator = { status: '', metadata: {} as Record<string, unknown> }
  const messages = [message]

  return {
    indicator,
    messageId: message.id,
    text: { content: '', thinkOpen: false },
    smoother: { push: vi.fn(), flush: vi.fn(), cancel: vi.fn() },
    fallbackModel: { provider: 'openai', model: 'Fallback', model_id: 9 },
    status: {
      set: (status, metadata = {}) => {
        indicator.status = status
        indicator.metadata = metadata
      },
      clear: () => {
        indicator.status = ''
        indicator.metadata = {}
      },
      current: () => indicator.status,
      metadata: () => indicator.metadata,
    },
    history: {
      messages,
      finishLiveThinking: vi.fn(),
      finishStreamingMessage: vi.fn(),
      updateStreamingMessage: vi.fn(),
      removeMessage: vi.fn(),
      setMessageStatus: vi.fn(),
      reconcileMessage: vi.fn(async () => undefined),
      recoverInterruptedTurn: vi.fn(async () => undefined),
    },
    run: {
      ingestTimeline: vi.fn(),
      noteStarted: vi.fn(),
      noteTerminal: vi.fn(),
      trackId: () => 4242,
      release: vi.fn(),
    },
    usage: {
      active: true,
      applyComplete: vi.fn(),
      loadSummary: vi.fn(async () => undefined),
      refreshAfterSettlement: vi.fn(),
    },
    message: () => messages.find((candidate) => candidate.id === message.id),
    isAborted: () => false,
    render: vi.fn(),
    renderContent: vi.fn(),
    ingestApprovalRequired: vi.fn(),
    scrollToBottom: vi.fn(),
    t: (key, params) => (params ? `${key} ${JSON.stringify(params)}` : key),
  }
}

export function fakeMemberTurn(
  message: Message = assistantMessage(),
  overrides: Partial<MemberTurn> = {}
): MemberTurn & Indicated {
  return {
    ...fakeTurn(message),
    chatId: 42,
    incognito: false,
    voice: new VoiceReplyStream(() => null, vi.fn(), 'en'),
    memory: {
      memories: { memories: [] },
      feedbacks: { feedbacks: [], getFeedbackById: () => undefined },
      digests: { addReferences: vi.fn() },
      suggest: vi.fn(),
      offerDelete: vi.fn(),
    },
    chats: {
      applyChatTitle: vi.fn(),
      bumpChatActivity: vi.fn(),
      clearLocalTurnFinished: vi.fn(),
      streamingChatId: () => 42,
    },
    outcome: {
      recordVersion: vi.fn(),
      restoreUnpersistedEdit: vi.fn(),
      scheduleMemoryPoll: vi.fn(),
      showError: vi.fn(),
      showLimit: vi.fn(),
      userLevel: () => 'PRO',
    },
    registerIncognitoFile: vi.fn(),
    ...overrides,
  }
}

export function fakeGuestTurn(message: Message = assistantMessage()): GuestTurn & Indicated {
  return {
    ...fakeTurn(message),
    guest: { updateCount: vi.fn(), showBanner: vi.fn(), offerSignup: vi.fn() },
  }
}

export function fakeContinueTurn(message: Message): ContinueTurn {
  return {
    message,
    text: { content: 'Earlier text', thinkOpen: false },
    smoother: { push: vi.fn(), flush: vi.fn(), cancel: vi.fn() },
    history: fakeTurn(message).history,
    usage: {
      active: true,
      applyComplete: vi.fn(),
      loadSummary: vi.fn(async () => undefined),
      refreshAfterSettlement: vi.fn(),
    },
    renderContent: vi.fn(),
    showError: vi.fn(),
    t: (key) => key,
  }
}
