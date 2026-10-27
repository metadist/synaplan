import { describe, expect, it } from 'vitest'
import { runStatusCode } from '@/utils/savedTaskRun'

const cards = [
  { nodeId: 'n1', capability: 'research', state: 'waiting_approval' },
  { nodeId: 'n2', capability: 'tool_call', state: 'pending' },
]

describe('runStatusCode', () => {
  it('reports a run paused on research as waiting for research', () => {
    expect(
      runStatusCode({ status: 'waiting_approval', waitingNode: 'n1', planSnapshot: { cards } })
    ).toBe('waiting_research')
  })

  it('keeps an approval wait as an approval wait', () => {
    expect(
      runStatusCode({ status: 'waiting_approval', waitingNode: 'n2', planSnapshot: { cards } })
    ).toBe('waiting_approval')
  })

  it('leaves every other status alone', () => {
    expect(runStatusCode({ status: 'completed', waitingNode: null, planSnapshot: null })).toBe(
      'completed'
    )
  })
})
