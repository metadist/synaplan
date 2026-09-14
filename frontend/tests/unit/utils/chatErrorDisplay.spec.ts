import { describe, expect, it } from 'vitest'
import {
  chatErrorReasonKey,
  chatErrorSuggestsOtherModel,
  finalizeSettledInProgressTurn,
  isSettledTaskPlan,
  normalizeChatErrorReason,
  taskPlanHasVisibleOutput,
} from '@/utils/chatErrorDisplay'
import type { Message, TaskPlanState } from '@/stores/history'

const plan = (cards: TaskPlanState['cards']): TaskPlanState => ({
  active: true,
  replyNode: cards[0]?.nodeId ?? 'n1',
  cards,
})

const textCard = (
  state: TaskPlanState['cards'][number]['state'],
  text = ''
): TaskPlanState['cards'][number] => ({
  nodeId: 'n1',
  capability: 'chat',
  kind: 'text',
  state,
  text,
})

const messageFromPlan = (taskPlan: TaskPlanState): Message => ({
  id: 'in-progress-turn',
  role: 'assistant',
  parts: [{ type: 'text', content: '' }],
  timestamp: new Date(),
  isStreaming: true,
  taskPlan,
})

describe('chatErrorDisplay', () => {
  it('maps unknown codes to the generic reason', () => {
    expect(normalizeChatErrorReason('json_validate_failed')).toBe('unknown')
    expect(normalizeChatErrorReason('timeout')).toBe('timeout')
    expect(chatErrorReasonKey('timeout')).toBe('chatError.reason.timeout')
  })

  it('does not suggest another model for auth or quota failures', () => {
    expect(chatErrorSuggestsOtherModel('auth_failed')).toBe(false)
    expect(chatErrorSuggestsOtherModel('quota_exceeded')).toBe(false)
    expect(chatErrorSuggestsOtherModel('timeout')).toBe(true)
    expect(chatErrorSuggestsOtherModel('empty_answer')).toBe(true)
  })

  it('treats a one-step done plan with no output as settled and empty', () => {
    const emptyDone = plan([textCard('done')])
    expect(isSettledTaskPlan(emptyDone)).toBe(true)
    expect(taskPlanHasVisibleOutput(emptyDone)).toBe(false)
  })

  it('does not stall a plan that is still running', () => {
    const running = plan([textCard('running'), textCard('done')])
    running.cards[1] = { ...textCard('done', 'ok'), nodeId: 'n2' }
    expect(isSettledTaskPlan(running)).toBe(false)
    expect(finalizeSettledInProgressTurn(messageFromPlan(running), false).stalled).toBe(false)
  })

  it('leaves a live run alone even when cards look finished', () => {
    const done = plan([textCard('done')])
    const result = finalizeSettledInProgressTurn(messageFromPlan(done), true)
    expect(result.stalled).toBe(false)
    expect(result.message.isStreaming).toBe(true)
    expect(result.message.errorReason).toBeUndefined()
  })

  it('marks an empty finished plan as a saved-reply failure', () => {
    const done = plan([textCard('done')])
    const result = finalizeSettledInProgressTurn(messageFromPlan(done), false)
    expect(result.stalled).toBe(true)
    expect(result.message.isStreaming).toBe(false)
    expect(result.message.taskPlan?.active).toBe(false)
    expect(result.message.errorReason).toBe('empty_answer')
    expect(result.message.canRetryModel).toBe(true)
  })

  it('lifts card draft text onto the bubble when the reply was never saved', () => {
    const done = plan([textCard('done', 'Draft answer from the step.')])
    const result = finalizeSettledInProgressTurn(messageFromPlan(done), false)
    expect(result.stalled).toBe(true)
    expect(result.message.parts[0].content).toBe('Draft answer from the step.')
    expect(result.message.errorReason).toBeUndefined()
  })
})
