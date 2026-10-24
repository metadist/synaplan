import { beforeEach, describe, expect, it, vi } from 'vitest'
import { memberErrorHandler } from '@/services/chat/stream/handlers/memberError'
import type { Message } from '@/stores/history'
import { assistantMessage, fakeMemberTurn } from './fakeTurn'

describe('member error handler', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  it('finishes a dropped connection without an error and recovers the stored turn', () => {
    const turn = fakeMemberTurn(
      assistantMessage({ parts: [{ type: 'text', content: 'Already answered' }] })
    )

    memberErrorHandler({ status: 'error', error: 'Connection interrupted' }, turn)

    expect(turn.history.finishStreamingMessage).toHaveBeenCalledWith('assistant-1')
    expect(turn.history.recoverInterruptedTurn).toHaveBeenCalledWith(42)
    expect(turn.message()?.errorReason).toBeUndefined()
  })

  it('removes the empty answer of a chat that is gone', () => {
    const turn = fakeMemberTurn()

    memberErrorHandler({ status: 'error', error: 'Chat not found' }, turn)

    expect(turn.history.removeMessage).toHaveBeenCalledWith('assistant-1')
    expect(turn.outcome.showError).toHaveBeenCalledWith('chat.notFound', 5000)
    expect(turn.outcome.restoreUnpersistedEdit).toHaveBeenCalled()
  })

  it('marks the prompt rate-limited and opens the limit modal', () => {
    const prompt = {
      id: 'user-1',
      role: 'user',
      parts: [],
      timestamp: new Date(),
    } as unknown as Message
    const turn = fakeMemberTurn()
    turn.history.messages.unshift(prompt)

    memberErrorHandler(
      { status: 'error', error: 'Rate limit exceeded', limit_type: 'hourly', used: 5, limit: 5 },
      turn
    )

    expect(turn.history.setMessageStatus).toHaveBeenCalledWith(
      'user-1',
      'rate_limited',
      'rate_limit',
      expect.objectContaining({ limitType: 'hourly', used: 5, userLevel: 'PRO' })
    )
    expect(turn.outcome.showLimit).toHaveBeenCalledWith(
      expect.objectContaining({ allowed: false, limitType: 'hourly' })
    )
  })

  it('gives a busy refusal its text back and offers one retry', () => {
    const prompt = {
      id: 'user-1',
      role: 'user',
      parts: [{ type: 'text', content: 'Summarise the attached report' }],
      timestamp: new Date(),
    } as unknown as Message
    const turn = fakeMemberTurn()
    turn.history.messages.unshift(prompt)

    memberErrorHandler({ status: 'error', code: 'server_busy' }, turn)

    expect(turn.history.removeMessage).toHaveBeenCalledWith('assistant-1')
    expect(turn.history.removeMessage).toHaveBeenCalledWith('user-1')
    expect(turn.outcome.busyRefused).toHaveBeenCalledWith('Summarise the attached report')
    expect(turn.history.updateStreamingMessage).not.toHaveBeenCalled()
  })

  it('keeps a stored prompt and offers no resend for it', () => {
    const prompt = {
      id: 'user-9',
      role: 'user',
      parts: [{ type: 'text', content: 'Answer again' }],
      backendMessageId: 9,
      timestamp: new Date(),
    } as unknown as Message
    const turn = fakeMemberTurn()
    turn.history.messages.unshift(prompt)

    memberErrorHandler({ status: 'error', code: 'server_busy' }, turn)

    expect(turn.history.removeMessage).not.toHaveBeenCalledWith('user-9')
    expect(turn.outcome.busyRefused).not.toHaveBeenCalled()
    expect(turn.history.updateStreamingMessage).toHaveBeenCalledWith(
      'assistant-1',
      'chatError.busyAnswer'
    )
  })

  it('keeps a send with files visible and offers no plain-text resend', () => {
    const prompt = {
      id: 'user-2',
      role: 'user',
      parts: [{ type: 'text', content: 'Summarise the attached report' }],
      files: [{ fileId: 5 }],
      timestamp: new Date(),
    } as unknown as Message
    const turn = fakeMemberTurn()
    turn.history.messages.unshift(prompt)

    memberErrorHandler({ status: 'error', code: 'server_busy' }, turn)

    expect(turn.history.removeMessage).toHaveBeenCalledWith('assistant-1')
    expect(turn.history.removeMessage).not.toHaveBeenCalledWith('user-2')
    expect(turn.outcome.busyRefused).not.toHaveBeenCalled()
    expect(turn.outcome.showError).toHaveBeenCalledWith('chatError.busyWithFiles', 15000)
  })

  it('restores a paste-only send whole instead of dropping it', () => {
    const prompt = {
      id: 'user-3',
      role: 'user',
      parts: [
        { type: 'pastedText', content: 'Quarterly report, page one' },
        { type: 'text', content: 'Summarise this' },
      ],
      timestamp: new Date(),
    } as unknown as Message
    const turn = fakeMemberTurn()
    turn.history.messages.unshift(prompt)

    memberErrorHandler({ status: 'error', code: 'server_busy' }, turn)

    expect(turn.outcome.busyRefused).toHaveBeenCalledWith(
      'Quarterly report, page one\n\nSummarise this'
    )
    expect(turn.history.removeMessage).toHaveBeenCalledWith('user-3')
  })

  it('shows one localized sentence per refusal class, never a status code', () => {
    const server = fakeMemberTurn()
    memberErrorHandler({ status: 'error', code: 'server_error' }, server)
    expect(server.history.updateStreamingMessage).toHaveBeenCalledWith(
      'assistant-1',
      'chatError.busyServer'
    )

    const limit = fakeMemberTurn()
    memberErrorHandler({ status: 'error', code: 'rate_limited' }, limit)
    expect(limit.history.updateStreamingMessage).toHaveBeenCalledWith(
      'assistant-1',
      'chatError.busyLimit'
    )
  })

  it('offers a top-up when the monthly budget is spent', () => {
    const turn = fakeMemberTurn()

    memberErrorHandler(
      {
        status: 'error',
        error: 'Budget',
        code: 'COST_BUDGET_EXCEEDED',
        used: 3.5,
        topup_available: true,
      },
      turn
    )

    expect(turn.outcome.showLimit).toHaveBeenCalledWith(
      expect.objectContaining({ limitType: 'monthly', used: 3.5, topupAvailable: true })
    )
  })

  it('shows the Stop notice for a cancelled turn with no text yet', () => {
    const turn = fakeMemberTurn()

    memberErrorHandler({ status: 'error', error: 'Stream cancelled by user' }, turn)

    expect(turn.history.updateStreamingMessage).toHaveBeenCalledWith(
      'assistant-1',
      'message.cancelledByUser'
    )
    expect(turn.chats.clearLocalTurnFinished).toHaveBeenCalledWith(42)
  })

  it('stores the classified failure and shows its sentence, never a transport string', () => {
    const classified = fakeMemberTurn()
    const unclassified = fakeMemberTurn()

    memberErrorHandler(
      {
        status: 'error',
        error: 'The model is overloaded.',
        errorReason: 'provider_error',
        messageId: 9,
      },
      classified
    )
    memberErrorHandler({ status: 'error', error: 'Connection failed (HTTP 500)' }, unclassified)

    expect(classified.message()).toMatchObject({
      errorReason: 'provider_error',
      errorMessage: 'The model is overloaded.',
      backendMessageId: 9,
    })
    expect(classified.history.updateStreamingMessage).toHaveBeenCalledWith(
      'assistant-1',
      'The model is overloaded.'
    )
    expect(classified.outcome.recordVersion).toHaveBeenCalledWith(9)
    expect(unclassified.message()?.errorReason).toBe('unknown')
    expect(unclassified.history.updateStreamingMessage).toHaveBeenCalledWith(
      'assistant-1',
      'chatError.reason.unknown'
    )
  })
})
