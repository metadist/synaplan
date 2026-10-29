import { describe, expect, it } from 'vitest'
import { autoResearchHandlers } from '@/services/chat/stream/handlers/autoResearch'
import {
  applyResearchFrame,
  applyResearchSse,
  currentStep,
  emptyProgress,
  parseResearchSse,
  progressFromRun,
  sourceCounts,
} from '@/services/chat/stream/handlers/research'
import { fakeMemberTurn } from './fakeTurn'

const FIXTURE = [
  'id: 1',
  'event: plan',
  'data: {"points":[{"id":"p1","title":"Delivery times"}]}',
  '',
  'id: 2',
  'event: source',
  'data: {"id":"s1","title":"Harbour notice","status":"found"}',
  '',
  'id: 3',
  'event: source',
  'data: {"id":"s1","status":"read"}',
  '',
  'id: 4',
  'event: point',
  'data: {"id":"p1","status":"answered"}',
  '',
  'id: 5',
  'event: check',
  'data: {"statements":4,"supported":3,"removed":1,"same_provider":true}',
  '',
  'id: 6',
  'event: complete',
  'data: {"outcome":{"code":"complete"}}',
  '',
  'event: queued',
  'data: {"since_seconds":20}',
  '',
].join('\n')

describe('research event handler', () => {
  it('builds the card from a fixture stream and ignores a late queued frame', () => {
    let progress = emptyProgress('run-1', 7, 'When do the ferries run?')

    for (const frame of parseResearchSse(FIXTURE)) {
      progress = applyResearchFrame(progress, frame)
    }

    expect(progress.status).toBe('complete')
    expect(progress.lastSeq).toBe(6)
    expect(progress.queuedSeconds).toBeNull()
    expect(progress.points).toEqual([{ id: 'p1', title: 'Delivery times', status: 'answered' }])
    expect(sourceCounts(progress)).toEqual({ found: 1, read: 1, blocked: 0 })
    expect(progress.check).toEqual({ statements: 4, supported: 3, removed: 1 })
  })

  it('records every step once, in order, and updates a source or point in place', () => {
    let progress = emptyProgress('run-1', 7, 'When do the ferries run?')
    for (const frame of parseResearchSse(FIXTURE)) progress = applyResearchFrame(progress, frame)

    expect(progress.steps).toEqual([
      { kind: 'plan', text: '1', status: null, ref: null },
      { kind: 'source', text: 'Harbour notice', status: 'read', ref: 's1' },
      { kind: 'point', text: '', status: 'answered', ref: 'p1' },
      {
        kind: 'check',
        text: '3/4',
        status: null,
        ref: null,
        check: { removed: 1, sameProvider: true },
      },
      { kind: 'complete', text: 'complete', status: null, ref: null },
    ])
    expect(currentStep(progress)).toEqual({
      kind: 'complete',
      text: 'complete',
      status: null,
      ref: null,
    })
  })

  it('applies a replayed page once', () => {
    let progress = emptyProgress('run-1', 7, 'When do the ferries run?')
    const page = parseResearchSse(FIXTURE).slice(0, 3)
    for (const frame of [...page, ...page]) progress = applyResearchFrame(progress, frame)

    expect(progress.steps.map((step) => step.kind)).toEqual(['plan', 'source'])
    expect(progress.lastSeq).toBe(3)
  })

  it('quotes the query, the goal and the section a step concerned', () => {
    const body = [
      'event: brief',
      'data: {"goal":"Ferry times for Friday","language":"en","output":"brief"}',
      '',
      'event: search',
      'data: {"point":"p1","query":"ferry timetable friday","scope":"web"}',
      '',
      'event: writing',
      'data: {"section":"Times"}',
      '',
      'event: round',
      'data: {"n":1,"max":2,"next":"read"}',
      '',
    ].join('\n')

    const progress = applyResearchSse(emptyProgress('run-3', 7, 'Ferries?'), body)

    expect(progress.steps.map((step) => [step.kind, step.text])).toEqual([
      ['brief', 'Ferry times for Friday'],
      ['search', 'ferry timetable friday'],
      ['writing', 'Times'],
      ['round', '1/2'],
    ])
  })

  it('keeps the model’s own words: thoughts, why a source was picked, what it said, the verdicts', () => {
    const body = [
      'event: thought',
      'data: {"text":"Two shops agree on the price. I check the official fee next."}',
      '',
      'event: plan',
      'data: {"points":[{"id":"p1","title":"Fee","why":"The person asked for the cost first."}]}',
      '',
      'event: source',
      'data: {"id":"s1","title":"Embassy","status":"found","why":"Official fee table."}',
      '',
      'event: finding',
      'data: {"source":"s1","point":"p1","fact":"The fee is 30 EUR.","confidence":"high","quote":"Fee: 30 EUR"}',
      '',
      'event: source',
      'data: {"id":"s1","status":"read","findings":1}',
      '',
      'event: point',
      'data: {"id":"p1","status":"answered","note":"Confirmed by the embassy."}',
      '',
      'event: round',
      'data: {"n":1,"max":3,"next":"write","reason":"covered"}',
      '',
      'event: verdict',
      'data: {"statement":"The fee is 50 EUR.","verdict":"removed","reason":"uncited"}',
      '',
    ].join('\n')

    const progress = applyResearchSse(emptyProgress('run-4', 7, 'Visa fee?'), body)

    expect(progress.steps).toEqual([
      {
        kind: 'thought',
        text: 'Two shops agree on the price. I check the official fee next.',
        status: null,
        ref: null,
      },
      { kind: 'plan', text: '1', status: null, ref: null },
      {
        kind: 'source',
        text: 'Embassy',
        status: 'read',
        ref: 's1',
        note: 'Official fee table.',
        count: 1,
      },
      {
        kind: 'finding',
        text: 'The fee is 30 EUR.',
        status: 'high',
        ref: 'p1',
        quote: 'Fee: 30 EUR',
        source: 's1',
      },
      { kind: 'point', text: '', status: 'answered', ref: 'p1', note: 'Confirmed by the embassy.' },
      { kind: 'round', text: '1/3', status: 'write', ref: null, note: 'covered' },
      {
        kind: 'verdict',
        text: 'The fee is 50 EUR.',
        status: 'removed',
        ref: null,
        note: 'uncited',
      },
    ])
    expect(progress.points).toEqual([
      {
        id: 'p1',
        title: 'Fee',
        status: 'answered',
        why: 'The person asked for the cost first.',
        note: 'Confirmed by the embassy.',
      },
    ])
    expect(progress.sourceTitles).toEqual({ s1: 'Embassy' })
  })

  it('grows the lead’s note while it writes it and swaps it for the finished note', () => {
    const writing = applyResearchSse(
      emptyProgress('run-6', 7, 'Visa fee?'),
      [
        'event: thinking',
        'data: {"text":"Two shops "}',
        '',
        'event: thinking',
        'data: {"text":"agree."}',
        '',
      ].join('\n')
    )
    const done = applyResearchFrame(writing, {
      seq: null,
      event: 'thought',
      data: { text: 'Two shops agree.' },
    })

    expect(writing.thinking).toBe('Two shops agree.')
    expect(writing.steps).toEqual([])
    expect(done.thinking).toBe('')
    expect(done.steps.map((step) => [step.kind, step.text])).toEqual([
      ['thought', 'Two shops agree.'],
    ])
  })

  it('grows the draft as the writer streams it and keeps it on a reload', () => {
    const body = [
      'event: writing',
      'data: {"section":"Answer"}',
      '',
      'event: draft',
      'data: {"text":"The fee is "}',
      '',
      'event: draft',
      'data: {"text":"30 EUR."}',
      '',
    ].join('\n')

    const progress = applyResearchSse(emptyProgress('run-5', 7, 'Visa fee?'), body)

    expect(progress.draft).toBe('The fee is 30 EUR.')
    expect(progress.phase).toBe('writing')
    expect(progress.steps.map((step) => step.kind)).toEqual(['writing'])
    expect(
      progressFromRun(7, {
        id: 'run-5',
        status: 'complete',
        question: 'Visa fee?',
        sources: [{ id: 's1', title: 'Embassy', status: 'read' }],
      }).sourceTitles
    ).toEqual({ s1: 'Embassy' })
  })

  it('marks a stop and keeps the check that arrived with the run', () => {
    const started = emptyProgress('run-2', 7, 'When do the ferries run?')
    const stopped = applyResearchFrame(started, {
      seq: 2,
      event: 'stopped',
      data: {},
    })
    const fromRun = progressFromRun(7, {
      id: 'run-2',
      status: 'stopped',
      question: 'When do the ferries run?',
      plan: { points: [{ id: 'p1', title: 'Delivery times', status: 'open' }] },
      sources: [{ id: 's1', title: 'Harbour notice', status: 'blocked' }],
      evidence: [{ source: 's1', quotes: [{ text: 'The last boat is at 18:00.' }] }],
      check: { statements: 2, supported: 1, removed: 1 },
    })

    expect(stopped.status).toBe('stopped')
    expect(fromRun.snippets).toEqual([
      { title: 'Harbour notice', quote: 'The last boat is at 18:00.' },
    ])
    expect(sourceCounts(fromRun).blocked).toBe(1)
    expect(fromRun.check?.removed).toBe(1)
  })

  it('keeps the ceiling an automatic run chose and the outcome it ended with', () => {
    const planned = applyResearchFrame(emptyProgress('run-3', 7, 'Which visa do I need?'), {
      seq: 1,
      event: 'plan',
      data: { points: [{ id: 'p1', title: 'Visa types' }], ceiling: 'quick' },
    })
    const fromRun = progressFromRun(7, {
      id: 'run-3',
      status: 'complete',
      question: 'Which visa do I need?',
      ceiling: 'quick',
      outcome: { code: 'answered_without_research' },
    })

    expect(planned.ceiling).toBe('quick')
    expect(fromRun.ceiling).toBe('quick')
    expect(fromRun.outcome).toBe('answered_without_research')
  })
})

describe('research_started on the chat stream', () => {
  it('hands the run the chat started to the turn', () => {
    const turn = fakeMemberTurn()

    autoResearchHandlers.research_started(
      {
        status: 'research_started',
        metadata: { run_id: 'rr_0123456789abcdef', minutes: 6, why: 'several_parts' },
      },
      turn
    )

    expect(turn.research.adopt).toHaveBeenCalledWith('rr_0123456789abcdef')
    expect(turn.message()?.researchRunId).toBe('rr_0123456789abcdef')
  })

  it('ignores an event without a run id', () => {
    const turn = fakeMemberTurn()

    autoResearchHandlers.research_started({ status: 'research_started', metadata: {} }, turn)

    expect(turn.research.adopt).not.toHaveBeenCalled()
  })
})
