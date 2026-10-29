import { describe, expect, it } from 'vitest'
import { formatElapsed, phaseSummary, researchPhases } from '@/components/research/researchPhases'
import {
  applyResearchSse,
  emptyProgress,
  type ResearchProgress,
} from '@/services/chat/stream/handlers/research'

const t = (key: string, params?: Record<string, unknown>, plural?: number): string =>
  `${key}${params ? `:${JSON.stringify(params)}` : ''}${plural === undefined ? '' : `#${plural}`}`

function frames(lines: string[]): string {
  return lines.join('\n')
}

function progressOf(body: string): ResearchProgress {
  return applyResearchSse(emptyProgress('run-1', 7, 'Which visa do I need?'), body)
}

const GATHERING = frames([
  'id: 1',
  'event: brief',
  'data: {"goal":"Visa rules for a tourist stay in Japan"}',
  '',
  'id: 2',
  'event: plan',
  'data: {"points":[{"id":"p1","title":"Visa types"},{"id":"p2","title":"Processing time"}]}',
  '',
  'id: 3',
  'event: search',
  'data: {"point":"p1","query":"japan tourist visa","scope":"web","results":8}',
  '',
  'id: 4',
  'event: source',
  'data: {"id":"s1","title":"Embassy notice","status":"read"}',
  '',
  'id: 5',
  'event: source',
  'data: {"id":"s2","title":"Old forum","status":"blocked","reason":"There was no text to read."}',
  '',
  'id: 6',
  'event: point',
  'data: {"id":"p1","status":"answered"}',
  '',
])

describe('researchPhases', () => {
  it('starts with understanding the question and lists the road ahead', () => {
    const phases = researchPhases(emptyProgress('run-1', 7, 'Which visa do I need?'))

    expect(phases.map((phase) => [phase.id, phase.state])).toEqual([
      ['understand', 'active'],
      ['plan', 'upcoming'],
      ['gather', 'upcoming'],
      ['write', 'upcoming'],
      ['check', 'upcoming'],
    ])
  })

  it('folds the phases the run passed, opens the one it is in and keeps the rest muted', () => {
    const phases = researchPhases(progressOf(GATHERING))

    expect(phases.map((phase) => [phase.id, phase.state])).toEqual([
      ['understand', 'done'],
      ['plan', 'done'],
      ['gather', 'active'],
      ['write', 'upcoming'],
      ['check', 'upcoming'],
    ])
    expect(phases[2].rows.map((row) => row.kind)).toEqual(['search', 'source', 'source'])
  })

  it('shows the plan as its points with their current state, not the frames that set them', () => {
    const plan = researchPhases(progressOf(GATHERING))[1]

    expect(plan.rows).toEqual([
      { kind: 'point', text: 'Visa types', status: 'answered', ref: 'p1' },
      { kind: 'point', text: 'Processing time', status: 'open', ref: 'p2' },
    ])
  })

  it('sums each phase up in one line', () => {
    const progress = progressOf(GATHERING)
    const [understand, plan, gather] = researchPhases(progress)

    expect(phaseSummary(t, understand, progress)).toBe('Visa rules for a tourist stay in Japan')
    expect(phaseSummary(t, plan, progress)).toBe(
      'research.phase.points:{"count":2}#2 · research.phase.answered:{"count":1}'
    )
    expect(phaseSummary(t, gather, progress)).toBe(
      'research.phase.searches:{"count":1}#1 · research.phase.sourcesRead:{"count":1}#1 · research.phase.gatherBlocked:{"count":1}'
    )
  })

  it('leaves out a phase the run never entered once it has ended, and lists nothing to come', () => {
    const progress = progressOf(
      GATHERING +
        frames([
          'id: 7',
          'event: checking',
          'data: {}',
          '',
          'id: 8',
          'event: check',
          'data: {"statements":4,"supported":3,"removed":1,"same_provider":false}',
          '',
          'id: 9',
          'event: complete',
          'data: {"outcome":{"code":"complete"}}',
          '',
        ])
    )
    const phases = researchPhases(progress)

    expect(phases.map((phase) => [phase.id, phase.state])).toEqual([
      ['understand', 'done'],
      ['plan', 'done'],
      ['gather', 'done'],
      ['check', 'done'],
    ])
    expect(phaseSummary(t, phases[3], progress)).toBe(
      'research.step.check:{"ratio":"3/4"} research.step.checkRemoved:{"count":1}'
    )
  })

  it('says nothing for the check while it is still going', () => {
    const progress = progressOf(GATHERING + frames(['id: 7', 'event: checking', 'data: {}', '']))
    const check = researchPhases(progress).find((phase) => phase.id === 'check')

    expect(check?.state).toBe('active')
    expect(phaseSummary(t, check!, progress)).toBe('')
  })

  it('formats the elapsed time as minutes and seconds', () => {
    expect(formatElapsed(0)).toBe('0:00')
    expect(formatElapsed(9_400)).toBe('0:09')
    expect(formatElapsed(125_000)).toBe('2:05')
  })
})
