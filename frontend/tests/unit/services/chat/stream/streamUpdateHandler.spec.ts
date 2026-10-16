import { describe, expect, it, vi } from 'vitest'
import {
  createContinueStreamHandler,
  createGuestStreamHandler,
  createMemberStreamHandler,
} from '@/services/chat/stream/streamUpdateHandler'
import { assistantMessage, fakeContinueTurn, fakeGuestTurn, fakeMemberTurn } from './fakeTurn'

describe('stream update handler', () => {
  it('drops every event of an aborted turn', () => {
    const turn = { ...fakeMemberTurn(), isAborted: () => true }

    createMemberStreamHandler(turn)({ status: 'data', chunk: 'late' })

    expect(turn.run.ingestTimeline).not.toHaveBeenCalled()
    expect(turn.text.content).toBe('')
  })

  it('feeds the timeline with every event and notes the end of the run', () => {
    const turn = fakeMemberTurn()
    const onUpdate = createMemberStreamHandler(turn)

    onUpdate({ status: 'started' })
    onUpdate({ status: 'complete', messageId: 7 })

    expect(turn.run.ingestTimeline).toHaveBeenCalledTimes(2)
    expect(turn.run.noteTerminal).toHaveBeenCalledTimes(1)
  })

  it('warns about an unknown status on a member turn and ignores it on a guest turn', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

    createGuestStreamHandler(fakeGuestTurn())({ status: 'surprise' })
    expect(warn).not.toHaveBeenCalled()

    createMemberStreamHandler(fakeMemberTurn())({ status: 'surprise' })
    expect(warn).toHaveBeenCalledWith('⚠️ Unknown status:', 'surprise', { status: 'surprise' })
  })

  it('never lets a guest stream reach memory code', () => {
    const turn = fakeGuestTurn()
    const onUpdate = createGuestStreamHandler(turn)

    onUpdate({ status: 'memories_loaded', metadata: { memories: [{ id: 1 }] } })
    onUpdate({ status: 'memory_suggested', metadata: { id: 2 } })

    expect(turn.message()?.memoryIds).toBeUndefined()
  })

  it('appends a continued answer and its reasoning to the cut-off bubble', () => {
    const message = assistantMessage({ isStreaming: true, backendMessageId: 501, truncated: false })
    const turn = fakeContinueTurn(message)
    const onUpdate = createContinueStreamHandler(turn)

    onUpdate({ status: 'reasoning', chunk: 'More thought' })
    onUpdate({ status: 'data', chunk: ' and more.' })
    onUpdate({ status: 'complete', truncated: true })

    expect(message.parts.at(-1)).toMatchObject({ type: 'thinking', content: 'More thought' })
    expect(turn.renderContent).toHaveBeenCalledWith('Earlier text and more.')
    expect(message).toMatchObject({ truncated: true, isStreaming: false })
    expect(turn.usage.refreshAfterSettlement).toHaveBeenCalled()
    expect(turn.history.reconcileMessage).toHaveBeenCalledWith('assistant-1', 501)
  })

  it('keeps a failed continuation cut off and says so', () => {
    const message = assistantMessage({ truncated: false })
    const turn = fakeContinueTurn(message)

    createContinueStreamHandler(turn)({ status: 'error', error: 'boom' })

    expect(message.truncated).toBe(true)
    expect(turn.showError).toHaveBeenCalledWith('chat.continueFailed')
  })
})
