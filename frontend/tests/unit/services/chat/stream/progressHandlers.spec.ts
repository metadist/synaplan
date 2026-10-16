import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  guestProgressHandlers,
  memberProgressHandlers,
  progressHandlers,
} from '@/services/chat/stream/handlers/progress'
import { assistantMessage, fakeMemberTurn, fakeTurn } from './fakeTurn'

describe('progress handlers', () => {
  afterEach(() => vi.useRealTimers())

  it.each([
    ['started', {}, {}],
    ['preprocessing', { message: 'Reading' }, { customMessage: 'Reading' }],
    ['analyzing', { message: 'Looking' }, { customMessage: 'Looking' }],
    ['searching', { message: 'Searching' }, { customMessage: 'Searching' }],
    ['editing', { metadata: { source: 'img-1' } }, { source: 'img-1' }],
    ['generating_file', { metadata: { stage: 'converting' } }, { stage: 'converting' }],
    ['planning', {}, {}],
    ['checking_memories', {}, {}],
  ])('shows %s in the processing indicator', (status, payload, metadata) => {
    const turn = fakeTurn()

    progressHandlers[status]({ status, ...payload }, turn)

    expect(turn.indicator).toEqual({ status, metadata })
  })

  it('names the routing model while classifying', () => {
    const turn = fakeTurn()

    progressHandlers.classifying(
      { status: 'classifying', metadata: { provider: 'groq', model_name: 'Sorter' } },
      turn
    )

    expect(turn.indicator.status).toBe('classifying')
    expect(turn.message()).toMatchObject({ provider: 'groq', modelLabel: 'Sorter' })
  })

  it('gives a spoken reply the language the classifier named', () => {
    const turn = fakeMemberTurn()
    const setLanguage = vi.spyOn(turn.voice, 'setLanguage')

    memberProgressHandlers.classified({ status: 'classified', metadata: { language: 'de' } }, turn)

    expect(turn.indicator).toEqual({ status: 'classified', metadata: { language: 'de' } })
    expect(setLanguage).toHaveBeenCalledWith('de')
  })

  it('shows the sources as soon as the search completes', () => {
    const turn = fakeTurn()
    const results = [{ url: 'https://example.test', title: 'Example' }]

    progressHandlers.search_complete(
      { status: 'search_complete', metadata: { query: 'q', results } },
      turn
    )

    expect(turn.message()).toMatchObject({
      searchResults: results,
      webSearch: { query: 'q', resultsCount: 1 },
    })
  })

  it('refreshes the sources and counts the pages read', () => {
    const turn = fakeTurn(assistantMessage({ webSearch: { query: 'q', resultsCount: 2 } }))
    const results = [{ url: 'https://example.test', fetched: true }]

    progressHandlers.pages_read(
      { status: 'pages_read', metadata: { pages_total: 3, pages_read: 2, results } },
      turn
    )

    expect(turn.indicator).toEqual({
      status: 'pages_read',
      metadata: {
        customMessage: undefined,
        pages_total: 3,
        pages_read: 2,
        urls_total: undefined,
        urls_read: undefined,
      },
    })
    expect(turn.message()).toMatchObject({
      searchResults: results,
      webSearch: { query: 'q', resultsCount: 2, pagesRead: 2 },
    })
  })

  it('names the answering model and attaches a media job while generating', () => {
    const turn = fakeTurn()

    progressHandlers.generating(
      {
        status: 'generating',
        message: 'Drawing',
        metadata: {
          provider: 'google',
          model_name: 'Imagen',
          media_job: { job_id: 'job-5', type: 'image', state: 'running' },
        },
      },
      turn
    )

    expect(turn.indicator.metadata).toMatchObject({ customMessage: 'Drawing', provider: 'google' })
    expect(turn.message()).toMatchObject({
      provider: 'google',
      modelLabel: 'Imagen',
      mediaJob: { jobId: 'job-5' },
    })
  })

  it('collects the document steps in the indicator and on the answer', () => {
    const turn = fakeTurn()

    progressHandlers.document_step(
      { status: 'document_step', metadata: { labelKey: 'step.one' } },
      turn
    )
    progressHandlers.document_step(
      { status: 'document_step', metadata: { labelKey: 'step.two', ok: false } },
      turn
    )

    expect(turn.indicator.status).toBe('generating_file')
    expect(turn.indicator.metadata.documentSteps).toEqual([
      { labelKey: 'step.one', labelParams: {}, ok: true },
      { labelKey: 'step.two', labelParams: {}, ok: false },
    ])
    expect(turn.message()?.documentChanges).toHaveLength(2)
  })

  it('shows a guest the thinking phase without a message, a member with one', () => {
    const guest = fakeTurn()
    const member = fakeMemberTurn()

    guestProgressHandlers.thinking({ status: 'thinking', message: 'Thinking' }, guest)
    memberProgressHandlers.thinking({ status: 'thinking', message: 'Thinking' }, member)

    expect(guest.indicator.metadata).toEqual({})
    expect(member.indicator.metadata).toEqual({ customMessage: 'Thinking' })
  })

  it('clears a finished memory analysis after two seconds unless another phase took over', () => {
    vi.useFakeTimers()
    const turn = fakeMemberTurn()

    memberProgressHandlers.memories_complete({ status: 'memories_complete' }, turn)
    vi.advanceTimersByTime(2000)

    expect(turn.indicator.status).toBe('')
  })
})
