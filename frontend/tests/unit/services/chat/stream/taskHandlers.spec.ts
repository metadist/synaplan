import { describe, expect, it } from 'vitest'
import { taskHandlers } from '@/services/chat/stream/handlers/tasks'
import { assistantMessage, fakeTurn } from './fakeTurn'

const plan = {
  status: 'plan',
  metadata: {
    plan: [
      { node_id: 'n1', capability: 'summarize', kind: 'text' },
      { node_id: 'n2', capability: 'draw', kind: 'unknown-kind' },
    ],
    reply_node: 'n3',
  },
}

function planned() {
  const turn = fakeTurn()
  turn.status.set('classified', {})
  taskHandlers.plan(plan, turn)
  return turn
}

describe('task handlers', () => {
  it('renders one pending card per node and keeps the turn id on the plan', () => {
    const turn = planned()

    expect(turn.indicator.status).toBe('')
    expect(turn.message()).toMatchObject({
      wasMultitask: true,
      taskPlan: {
        active: true,
        trackId: 4242,
        replyNode: 'n3',
        cards: [
          { nodeId: 'n1', capability: 'summarize', kind: 'text', state: 'pending' },
          { nodeId: 'n2', capability: 'draw', kind: 'text', state: 'pending' },
        ],
      },
    })
  })

  it('ignores an empty plan', () => {
    const turn = fakeTurn()

    taskHandlers.plan({ status: 'plan', metadata: { plan: [] } }, turn)

    expect(turn.message()?.taskPlan).toBeUndefined()
  })

  it('retracts the cards of a discarded plan', () => {
    const turn = planned()

    taskHandlers.plan_discarded({ status: 'plan_discarded' }, turn)

    expect(turn.message()).toMatchObject({ taskPlan: null, wasMultitask: false })
  })

  it('moves a card to its new state with the step trace', () => {
    const turn = planned()

    taskHandlers.task_update(
      {
        status: 'task_update',
        metadata: {
          node_id: 'n1',
          state: 'done',
          text: 'Summary',
          duration_ms: 900,
          step_output: 'out',
          results_count: 3,
        },
      },
      turn
    )

    expect(turn.ingestApprovalRequired).toHaveBeenCalled()
    expect(turn.message()?.taskPlan?.cards[0]).toMatchObject({
      state: 'done',
      text: 'Summary',
      durationMs: 900,
      stepOutput: 'out',
      resultsCount: 3,
    })
  })

  it('keeps a step the user cancelled cancelled', () => {
    const turn = planned()
    turn.message()!.taskPlan!.cards[0].state = 'cancelled'

    taskHandlers.task_update(
      { status: 'task_update', metadata: { node_id: 'n1', state: 'failed', error: 'aborted' } },
      turn
    )

    expect(turn.message()?.taskPlan?.cards[0]).toMatchObject({ state: 'cancelled' })
    expect(turn.message()?.taskPlan?.cards[0].error).toBeUndefined()
  })

  it('appends task text, attaches the produced file and shows render progress', () => {
    const turn = planned()

    taskHandlers.task_chunk(
      { status: 'task_chunk', metadata: { node_id: 'n1', chunk: 'Hel' } },
      turn
    )
    taskHandlers.task_chunk(
      { status: 'task_chunk', metadata: { node_id: 'n1', chunk: 'lo' } },
      turn
    )
    taskHandlers.task_file(
      {
        status: 'task_file',
        metadata: { node_id: 'n2', url: '/api/v1/files/uploads/a.png', type: 'image' },
      },
      turn
    )
    taskHandlers.task_progress(
      {
        status: 'task_progress',
        metadata: { node_id: 'n2', percent: 60, provider_status: 'rendering', elapsed_seconds: 12 },
      },
      turn
    )

    const [text, image] = turn.message()!.taskPlan!.cards
    expect(text.text).toBe('Hello')
    expect(image).toMatchObject({
      mediaType: 'image',
      progressPercent: 60,
      providerStatus: 'rendering',
      elapsedSeconds: 12,
    })
    expect(image.url).toContain('/api/v1/files/uploads/a.png')
  })

  it('leaves a message without a plan alone', () => {
    const message = assistantMessage()
    const turn = fakeTurn(message)

    taskHandlers.task_chunk({ status: 'task_chunk', metadata: { node_id: 'n1', chunk: 'x' } }, turn)

    expect(message.taskPlan).toBeUndefined()
  })
})
