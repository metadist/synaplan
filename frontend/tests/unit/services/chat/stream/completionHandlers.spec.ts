import { describe, expect, it } from 'vitest'
import {
  guestCompletionHandlers,
  memberCompletionHandlers,
} from '@/services/chat/stream/handlers/completion'
import { guestHandlers } from '@/services/chat/stream/handlers/guest'
import { assistantMessage, fakeGuestTurn, fakeMemberTurn } from './fakeTurn'

const generatedFile = {
  id: 77,
  filename: 'report.docx',
  path: 'u/7/report.docx',
  size: 10,
  type: 'docx',
  mime: 'application/msword',
}

describe('member completion', () => {
  it('replaces the live thinking clock with the measured seconds', () => {
    const turn = fakeMemberTurn(
      assistantMessage({
        parts: [{ type: 'thinking', content: 'hmm', thinkingTime: 1, isStreaming: true }],
      })
    )

    memberCompletionHandlers.complete(
      { status: 'complete', messageId: 9, thinking_seconds: 7 },
      turn
    )

    expect(turn.message()?.parts[0]).toMatchObject({ thinkingTime: 7 })
    expect(turn.message()?.parts[0]).not.toHaveProperty('isStreaming')
  })

  it('keeps the live clock when complete carries no duration', () => {
    const turn = fakeMemberTurn(
      assistantMessage({ parts: [{ type: 'thinking', content: 'hmm', thinkingTime: 1 }] })
    )

    memberCompletionHandlers.complete({ status: 'complete', messageId: 9 }, turn)

    expect(turn.message()?.parts[0]?.thinkingTime).toBe(1)
  })

  it('renders the final text and settles the stored turn', () => {
    const turn = fakeMemberTurn(
      assistantMessage({ parts: [{ type: 'text', content: '' }, { type: 'tts_loading' }] })
    )
    turn.text.content = 'Final answer'

    memberCompletionHandlers.complete(
      {
        status: 'complete',
        messageId: 501,
        chatTitle: 'Trip plan',
        topic: 'general',
        memoryIds: [3],
        provider: 'groq',
        model: 'Llama',
      },
      turn
    )

    expect(turn.render).toHaveBeenCalledWith('Final answer')
    expect(turn.message()).toMatchObject({
      backendMessageId: 501,
      topic: 'general',
      memoryIds: [3],
      modelLabel: 'Llama',
      provider: 'groq',
    })
    expect(turn.message()?.parts.some((part) => part.type === 'tts_loading')).toBe(false)
    expect(turn.chats.applyChatTitle).toHaveBeenCalledWith(42, 'Trip plan')
    expect(turn.chats.bumpChatActivity).toHaveBeenCalledWith(42)
    expect(turn.outcome.recordVersion).toHaveBeenCalledWith(501)
    expect(turn.history.reconcileMessage).toHaveBeenCalledWith('assistant-1', 501)
    expect(turn.outcome.scheduleMemoryPoll).toHaveBeenCalledWith(501)
    expect(turn.run.release).toHaveBeenCalled()
  })

  it('persists nothing for an incognito turn and registers its document for deletion', () => {
    const turn = fakeMemberTurn(assistantMessage({ parts: [] }), { incognito: true })

    memberCompletionHandlers.complete(
      { status: 'complete', messageId: 502, chatTitle: 'x', generatedFile },
      turn
    )

    expect(turn.registerIncognitoFile).toHaveBeenCalledWith(77)
    expect(turn.message()?.files?.[0]).toMatchObject({ id: 77, filename: 'report.docx' })
    expect(turn.chats.applyChatTitle).not.toHaveBeenCalled()
    expect(turn.history.reconcileMessage).not.toHaveBeenCalled()
  })

  it('shows the generating token of a media job without answer text', () => {
    const turn = fakeMemberTurn()

    memberCompletionHandlers.complete(
      { status: 'complete', mediaJob: { job_id: 'job-1', type: 'audio', state: 'running' } },
      turn
    )

    expect(turn.renderContent).toHaveBeenCalledWith('__AUDIO_GENERATING__')
    expect(turn.outcome.restoreUnpersistedEdit).toHaveBeenCalled()
  })

  it('keeps a classified failure of a completed answer and the vision hint', () => {
    const turn = fakeMemberTurn()

    memberCompletionHandlers.complete(
      {
        status: 'complete',
        errorReason: 'provider_error',
        error: 'The model failed.',
        error_hint: 'vision_model_required',
      },
      turn
    )

    expect(turn.message()).toMatchObject({
      errorReason: 'provider_error',
      errorMessage: 'The model failed.',
      canRetryModel: true,
    })
    expect(turn.message()?.parts.at(-1)?.content).toContain('aiProvider.error.visionModelHint')
  })
})

describe('guest completion', () => {
  it('marks a cut-off guest answer in its text and settles the usage summary', () => {
    const turn = fakeGuestTurn()
    turn.text.content = 'Partial'

    guestCompletionHandlers.complete(
      { status: 'complete', truncated: true, messageId: 601, usage: { cost: 0.01 } },
      turn
    )

    expect(turn.render).toHaveBeenCalledWith('Partial\n\n---\n\n⚠️ *message.truncated*')
    expect(turn.message()?.backendMessageId).toBe(601)
    expect(turn.usage.loadSummary).toHaveBeenCalled()
    expect(turn.history.finishStreamingMessage).toHaveBeenCalledWith('assistant-1')
    expect(turn.scrollToBottom).toHaveBeenCalled()
  })

  it('stops the cards and finishes the bubble on an error', () => {
    const turn = fakeGuestTurn(
      assistantMessage({ taskPlan: { active: true, replyNode: '', cards: [] } })
    )

    guestCompletionHandlers.error({ status: 'error', error: 'x' }, turn)

    expect(turn.message()?.taskPlan?.active).toBe(false)
    expect(turn.history.finishStreamingMessage).toHaveBeenCalledWith('assistant-1')
  })

  it('updates the remaining count and ends the trial with the signup offer', () => {
    const turn = fakeGuestTurn()

    guestHandlers.guest_remaining(
      { status: 'guest_remaining', remaining: 2, maxMessages: 5, limitReached: false },
      turn
    )
    guestHandlers.guest_limit_reached({ status: 'guest_limit_reached' }, turn)

    expect(turn.guest.updateCount).toHaveBeenCalledWith(2, 5, false)
    expect(turn.guest.showBanner).toHaveBeenCalled()
    expect(turn.history.removeMessage).toHaveBeenCalledWith('assistant-1')
    expect(turn.guest.offerSignup).toHaveBeenCalled()
    expect(turn.run.release).toHaveBeenCalled()
  })
})
