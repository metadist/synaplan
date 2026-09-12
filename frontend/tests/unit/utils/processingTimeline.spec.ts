import { describe, expect, it } from 'vitest'
import {
  activeStep,
  cloneTimelineModel,
  cloneTimelineSteps,
  consumeVisibleAnswer,
  createTimelineState,
  formatDurationSeconds,
  ingestTimelineEvent,
  postAnswerSteps,
  preAnswerSteps,
  stepDurationMs,
  timelineFromStatus,
  type TimelineState,
} from '@/utils/processingTimeline'
import { describeStep, intentSentence, type Translate } from '@/utils/processingStepCopy'

const t: Translate = (key, params) => (params ? `${key}:${JSON.stringify(params)}` : key)

/** Feed a scripted stream: [offsetMs, payload] pairs from a fixed origin. */
function play(events: Array<[number, Record<string, unknown>]>): TimelineState {
  const state = createTimelineState()
  const origin = 1_000_000
  for (const [offset, payload] of events) {
    ingestTimelineEvent(state, payload, origin + offset)
  }
  return state
}

describe('ingestTimelineEvent', () => {
  it('accumulates steps and keeps durations of finished ones', () => {
    const state = play([
      [0, { status: 'started' }],
      [10, { status: 'preprocessing' }],
      [20, { status: 'classifying', metadata: { model_name: 'Sorter' } }],
      [1_400, { status: 'classified', metadata: { intent: 'chat', web_search: false } }],
      [1_450, { status: 'generating', metadata: { model_name: 'Grok 4', stage: 'request_sent' } }],
    ])

    // `preprocessing` finished in 10 ms → trivial, dropped
    expect(state.steps.map((s) => s.key)).toEqual(['understand', 'generate'])

    const understand = state.steps[0]
    expect(understand.state).toBe('done')
    expect(stepDurationMs(understand, 0)).toBe(1_380)
    // metadata merges across classifying → classified
    expect(understand.metadata).toMatchObject({ model_name: 'Sorter', intent: 'chat' })

    expect(activeStep(state)?.key).toBe('generate')
    expect(state.model).toEqual({ name: 'Grok 4', provider: undefined })
  })

  it('closes the active step on the first answer token and flags later steps as post-answer', () => {
    const state = play([
      [0, { status: 'started' }],
      [100, { status: 'generating', metadata: { model_name: 'M' } }],
      [2_100, { status: 'data', chunk: 'Hello' }],
      [2_200, { status: 'data', chunk: ' world' }],
      [5_000, { status: 'analyzing_memories' }],
      [5_500, { status: 'saving_memories' }],
      [6_000, { status: 'memories_complete' }],
    ])

    expect(state.answerStartedAt).toBe(1_002_100)
    const [generate, memories] = state.steps
    expect(generate.state).toBe('done')
    expect(stepDurationMs(generate, 0)).toBe(2_000)
    expect(memories.key).toBe('memories_after')
    expect(memories.status).toBe('memories_complete')
    expect(memories.state).toBe('done')
    expect(preAnswerSteps(state).map((s) => s.key)).toEqual(['generate'])
    expect(postAnswerSteps(state).map((s) => s.key)).toEqual(['memories_after'])
  })

  it('records a closing status without its opener as an already finished step', () => {
    const state = play([
      [0, { status: 'started' }],
      [5, { status: 'classified', metadata: { intent: 'image_generation', media_type: 'image' } }],
    ])
    expect(state.steps).toHaveLength(1)
    expect(state.steps[0]).toMatchObject({ key: 'understand', state: 'done' })
  })

  it('folds web search open/progress/complete into one step', () => {
    const state = play([
      [0, { status: 'searching' }],
      [800, { status: 'search_complete', metadata: { results_count: 8 } }],
      [900, { status: 'reading_pages', metadata: { pages_total: 4, pages_read: 0 } }],
      [1_200, { status: 'reading_pages', metadata: { pages_total: 4, pages_read: 2 } }],
      [1_500, { status: 'pages_read', metadata: { pages_read: 4 } }],
    ])
    expect(state.steps.map((s) => [s.key, s.state])).toEqual([
      ['web', 'done'],
      ['pages', 'done'],
    ])
    expect(state.steps[1].metadata).toMatchObject({ pages_total: 4, pages_read: 4 })
  })

  it('drops the placeholder generating emitted before the handler narrates its own steps', () => {
    // Exact order of a plain chat turn as streamed by the backend.
    const state = play([
      [0, { status: 'started' }],
      [5, { status: 'preprocessing' }],
      [10, { status: 'classifying', metadata: { model_name: 'Sorter' } }],
      [900, { status: 'classified', metadata: { intent: 'chat' } }],
      [910, { status: 'generating', metadata: { model_name: 'Default', model_id: 1 } }],
      [915, { status: 'processing' }],
      [920, { status: 'analyzing_prompt' }],
      [1_300, { status: 'checking_memories' }],
      [1_900, { status: 'generating', metadata: { model_name: 'Grok 4', stage: 'request_sent' } }],
      [4_000, { status: 'data', chunk: 'Paris' }],
      [4_100, { status: 'generated' }],
    ])
    expect(state.steps.map((s) => [s.key, s.state])).toEqual([
      ['understand', 'done'],
      ['prompt', 'done'],
      ['memories', 'done'],
      ['generate', 'done'],
    ])
    expect(state.steps[3].metadata.model_name).toBe('Grok 4')
    expect(state.model.name).toBe('Grok 4')
  })

  it('keeps a generic generating step when the answer itself closes it', () => {
    const state = play([
      [0, { status: 'generating', metadata: { model_name: 'M' } }],
      [1_000, { status: 'data', chunk: 'Hi' }],
    ])
    expect(state.steps.map((s) => [s.key, s.state])).toEqual([['generate', 'done']])
  })

  it('does not treat a <think> block split across data chunks as the answer', () => {
    const state = play([
      [0, { status: 'generating', metadata: { model_name: 'Gemini', stage: 'request_sent' } }],
      [300, { status: 'thinking' }],
      [400, { status: 'data', chunk: '<think>Let me' }],
      [500, { status: 'data', chunk: ' keep thinking</think>' }],
      [4_000, { status: 'data', chunk: 'The answer' }],
    ])
    expect(state.answerStartedAt).toBe(1_000_000 + 4_000)
    expect(state.steps.map((s) => [s.key, s.state])).toEqual([
      ['generate', 'done'],
      ['thinking', 'done'],
    ])
  })

  it('does not treat a reasoning-only data chunk as the answer', () => {
    const state = play([
      [0, { status: 'generating', metadata: { model_name: 'Gemini', stage: 'request_sent' } }],
      [300, { status: 'thinking' }],
      [400, { status: 'data', chunk: '<think>Let me think</think>' }],
      [4_000, { status: 'data', chunk: 'The answer' }],
    ])
    expect(state.answerStartedAt).toBe(1_000_000 + 4_000)
    expect(state.steps.map((s) => [s.key, s.state])).toEqual([
      ['generate', 'done'],
      ['thinking', 'done'],
    ])
  })

  it('does not open a second generate row when generated arrives after reasoning', () => {
    const state = play([
      [0, { status: 'generating', metadata: { model_name: 'Gemini', stage: 'request_sent' } }],
      [300, { status: 'thinking' }],
      [400, { status: 'reasoning', chunk: 'hmm' }],
      [4_000, { status: 'data', chunk: 'Hi' }],
      [4_100, { status: 'generated' }],
    ])
    expect(state.steps.filter((s) => s.key === 'generate')).toHaveLength(1)
    expect(state.steps.map((s) => [s.key, s.state])).toEqual([
      ['generate', 'done'],
      ['thinking', 'done'],
    ])
  })

  it('opens a thinking step from live reasoning chunks and closes generate', () => {
    const state = play([
      [0, { status: 'generating', metadata: { model_name: 'Gemini', stage: 'request_sent' } }],
      [300, { status: 'thinking' }],
      [400, { status: 'reasoning', chunk: 'Let me think' }],
      [4_000, { status: 'data', chunk: '<think>Let me think</think>Answer' }],
    ])
    expect(state.steps.map((s) => [s.key, s.state])).toEqual([
      ['generate', 'done'],
      ['thinking', 'done'],
    ])
    expect(stepDurationMs(state.steps[1], 0)).toBe(3_700)
  })

  it('annotates the memory step with loaded counts even after it closed', () => {
    const state = play([
      [0, { status: 'checking_memories' }],
      [200, { status: 'generating', metadata: { model_name: 'M' } }],
      [
        250,
        { status: 'memories_loaded', metadata: { memories: [{ id: 1 }, { id: 2 }, { id: 3 }] } },
      ],
    ])
    expect(state.steps[0].metadata.memories_count).toBe(3)
  })

  it('resets on started and ignores statuses that carry no step', () => {
    const state = play([
      [0, { status: 'generating' }],
      [10, { status: 'processing' }],
      [20, { status: 'perf', metadata: {} }],
      [30, { status: 'task_update' }],
      [40, { status: 'started' }],
    ])
    expect(state.steps).toEqual([])
    expect(state.startedAt).toBe(1_000_040)
  })
})

describe('timelineFromStatus', () => {
  it('builds a single active step from a bare status', () => {
    const state = timelineFromStatus('analyzing_prompt', { model_name: 'X' })
    expect(state.steps).toHaveLength(1)
    expect(state.steps[0]).toMatchObject({ key: 'prompt', state: 'active' })
    expect(state.model.name).toBe('X')
  })

  it('yields no step for unknown statuses', () => {
    expect(timelineFromStatus('started', null).steps).toEqual([])
  })
})

describe('describeStep', () => {
  const base = { id: 1, startedAt: 0, afterAnswer: false, message: undefined }

  it('names the model while waiting and in the finished label', () => {
    const active = describeStep(
      { ...base, key: 'generate', status: 'generating', metadata: {}, state: 'active' },
      { name: 'Grok 4' },
      t
    )
    expect(active.title).toBe('processing.timeline.sendingTo:{"model":"Grok 4"}')
    expect(active.detail).toBe('processing.timeline.waitingFor:{"model":"Grok 4"}')

    const done = describeStep(
      { ...base, key: 'generate', status: 'generated', metadata: {}, state: 'done', endedAt: 1 },
      { name: 'Grok 4' },
      t
    )
    expect(done.title).toBe('processing.timeline.sentTo:{"model":"Grok 4"}')
  })

  it('does not pair a step-level metadata.model with the sorter provider', () => {
    const copy = describeStep(
      {
        ...base,
        key: 'generate',
        status: 'generating',
        metadata: { model: 'analyzer-v2' },
        state: 'active',
      },
      { name: 'Sorter', providerLabel: 'Groq' },
      t
    )
    expect(copy.title).toBe('processing.timeline.sendingTo:{"model":"analyzer-v2"}')
    expect(copy.title).not.toContain('Groq')
  })

  it('names the provider next to the model when the backend resolved it', () => {
    const copy = describeStep(
      {
        ...base,
        key: 'generate',
        status: 'generating',
        metadata: {
          model_name: 'claude-opus-4-8',
          provider: 'anthropic',
          provider_label: 'Anthropic',
        },
        state: 'active',
      },
      {},
      t
    )
    expect(copy.title).toBe(
      'processing.timeline.sendingTo:{"model":"processing.timeline.modelByProvider:{\\"model\\":\\"claude-opus-4-8\\",\\"provider\\":\\"Anthropic\\"}"}'
    )
  })

  it('reads generically when the admin hides model names', () => {
    const copy = describeStep(
      {
        ...base,
        key: 'generate',
        status: 'generating',
        metadata: { model_name: 'claude-opus-4-8', provider_label: 'Anthropic' },
        state: 'active',
      },
      { name: 'claude-opus-4-8', providerLabel: 'Anthropic' },
      t,
      { showModels: false }
    )
    expect(copy.title).toBe('processing.generatingTitle')
    expect(copy.detail).toBe('processing.generatingDesc')
  })

  it('tells which page is being read or summarised', () => {
    const reading = describeStep(
      {
        ...base,
        key: 'pages',
        status: 'reading_pages',
        metadata: {
          pages_total: 3,
          pages_read: 1,
          hosts: ['tagesschau.de', 'handelsblatt.com', 'faz.net'],
          current_host: 'handelsblatt.com',
          stage: 'fetching',
        },
        state: 'active',
      },
      {},
      t
    )
    expect(reading.detail).toBe('processing.timeline.readingPage:{"host":"handelsblatt.com"}')
    expect(reading.chip).toBe('1/3')

    const condensing = describeStep(
      {
        ...base,
        key: 'pages',
        status: 'reading_pages',
        metadata: {
          pages_total: 3,
          pages_read: 1,
          current_host: 'handelsblatt.com',
          stage: 'condensing',
        },
        state: 'active',
      },
      {},
      t
    )
    expect(condensing.detail).toBe('processing.timeline.condensingPage:{"host":"handelsblatt.com"}')

    const done = describeStep(
      {
        ...base,
        key: 'pages',
        status: 'pages_read',
        metadata: { pages_read: 3, hosts: ['tagesschau.de', 'faz.net'] },
        state: 'done',
        endedAt: 1,
      },
      {},
      t
    )
    expect(done.detail).toBe('tagesschau.de · faz.net')
  })

  it('phrases media generation with the media type', () => {
    const copy = describeStep(
      {
        ...base,
        key: 'generate',
        status: 'generating',
        metadata: { media_type: 'audio', model_name: 'TTS-1' },
        state: 'active',
      },
      {},
      t
    )
    expect(copy.title).toBe(
      'processing.timeline.generatingMediaWith:{"type":"processing.timeline.mediaAudio","model":"TTS-1"}'
    )
  })

  it('falls back to the generic label without a model', () => {
    const copy = describeStep(
      { ...base, key: 'generate', status: 'generating', metadata: {}, state: 'active' },
      {},
      t
    )
    expect(copy.title).toBe('processing.generatingTitle')
  })

  it('summarises the classification as an intent sentence', () => {
    expect(intentSentence({ intent: 'chat' }, t)).toBe('processing.timeline.intentChat')
    expect(intentSentence({ intent: 'chat', web_search: true, multi_step: true }, t)).toBe(
      'processing.timeline.intentWebSearch · processing.timeline.intentMultiStep'
    )
    expect(intentSentence({ media_type: 'video' }, t)).toBe('processing.timeline.intentVideo')
    expect(intentSentence({ intent: 'document_generation' }, t)).toBe(
      'processing.timeline.intentDocument'
    )
    expect(intentSentence({}, t)).toBeUndefined()
  })

  it('reports the thinking duration once done', () => {
    const copy = describeStep(
      {
        ...base,
        key: 'thinking',
        status: 'thinking',
        metadata: {},
        state: 'done',
        startedAt: 0,
        endedAt: 6_400,
      },
      { name: 'Gemini' },
      t
    )
    expect(copy.title).toBe('message.thoughtFor:{"n":6}')
  })
})

describe('consumeVisibleAnswer', () => {
  it('keeps an open <think> block out of the visible text until it closes', () => {
    const first = consumeVisibleAnswer('<think>Let me', false)
    expect(first.text.trim()).toBe('')
    expect(first.insideThink).toBe(true)

    const second = consumeVisibleAnswer(' keep thinking</think>', first.insideThink)
    expect(second.text.trim()).toBe('')
    expect(second.insideThink).toBe(false)

    const third = consumeVisibleAnswer('The answer', second.insideThink)
    expect(third.text.trim()).toBe('The answer')
  })
})

describe('formatDurationSeconds', () => {
  it('uses one decimal below ten seconds', () => {
    expect(formatDurationSeconds(1_380)).toBe('1.4s')
    expect(formatDurationSeconds(12_600)).toBe('13s')
  })
})

describe('cloneTimelineSteps', () => {
  it('detaches steps and metadata from the live timeline', () => {
    const state = createTimelineState()
    state.steps.push({
      id: 1,
      key: 'understand',
      status: 'classified',
      metadata: { intent: 'chat' },
      startedAt: 1,
      endedAt: 2,
      state: 'done',
      afterAnswer: false,
    })
    const clone = cloneTimelineSteps(state.steps)
    state.steps[0].metadata.intent = 'image'
    state.steps[0].status = 'classifying'
    expect(clone[0].metadata.intent).toBe('chat')
    expect(clone[0].status).toBe('classified')
    expect(cloneTimelineModel({ name: 'M', provider: 'groq' })).toEqual({
      name: 'M',
      provider: 'groq',
    })
  })
})
