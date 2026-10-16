import { describe, expect, it, vi } from 'vitest'
import { contentHandlers, memberContentHandlers } from '@/services/chat/stream/handlers/content'
import { assistantMessage, fakeMemberTurn, fakeTurn } from './fakeTurn'

const runningPlan = { active: true, replyNode: 'n3', cards: [] }

describe('content handlers', () => {
  it('streams the answer text and folds the thinking indicator on the first visible token', () => {
    const turn = fakeTurn()
    turn.status.set('generating', {})

    contentHandlers.data({ status: 'data', chunk: '<think>hidden' }, turn)
    expect(turn.indicator.status).toBe('generating')

    contentHandlers.data({ status: 'data', chunk: '</think>Visible' }, turn)

    expect(turn.indicator.status).toBe('')
    expect(turn.history.finishLiveThinking).toHaveBeenCalledWith('assistant-1')
    expect(turn.text.content).toBe('<think>hidden</think>Visible')
    expect(turn.smoother.push).toHaveBeenLastCalledWith('<think>hidden</think>Visible')
  })

  it('only accumulates the reply text while a task plan runs', () => {
    const turn = fakeTurn(assistantMessage({ taskPlan: runningPlan }))

    contentHandlers.data({ status: 'data', chunk: 'Assembled' }, turn)

    expect(turn.text.content).toBe('Assembled')
    expect(turn.smoother.push).not.toHaveBeenCalled()
  })

  it('feeds a spoken reply every chunk a member receives', () => {
    const turn = fakeMemberTurn()
    const feed = vi.spyOn(turn.voice, 'feed')

    memberContentHandlers.data({ status: 'data', chunk: 'Hello.' }, turn)

    expect(feed).toHaveBeenCalledWith('Hello.')
  })

  it('opens the reasoning part first, before the answer text', () => {
    const turn = fakeTurn()

    contentHandlers.reasoning({ status: 'reasoning', chunk: 'Weighing ' }, turn)
    contentHandlers.reasoning({ status: 'reasoning', chunk: 'options' }, turn)

    expect(turn.message()?.parts[0]).toMatchObject({
      type: 'thinking',
      content: 'Weighing options',
      isStreaming: true,
    })
  })

  it('shows generated media outside a plan, never inside one, and ignores other types', () => {
    const outside = fakeTurn()
    const inside = fakeTurn(assistantMessage({ taskPlan: runningPlan }))

    contentHandlers.file(
      { status: 'file', url: '/api/v1/files/uploads/a.png', type: 'image' },
      outside
    )
    contentHandlers.file(
      { status: 'file', url: '/api/v1/files/uploads/a.pdf', type: 'pdf' },
      outside
    )
    contentHandlers.file(
      { status: 'file', url: '/api/v1/files/uploads/b.png', type: 'image' },
      inside
    )

    expect(outside.message()?.parts.filter((part) => part.type === 'image')).toHaveLength(1)
    expect(inside.message()?.parts.some((part) => part.type === 'image')).toBe(false)
  })

  it('lists links with their host, falling back to the raw address', () => {
    const turn = fakeTurn()

    contentHandlers.links(
      {
        status: 'links',
        links: [{ url: 'https://example.test/a', title: 'A' }, { url: 'not a url' }],
      },
      turn
    )

    expect(turn.message()?.parts.at(-1)).toEqual({
      type: 'links',
      items: [
        { title: 'A', url: 'https://example.test/a', desc: undefined, host: 'example.test' },
        { title: 'not a url', url: 'not a url', desc: undefined, host: 'not a url' },
      ],
    })
  })

  it('shows the read-aloud loader, then attaches the spoken reply', () => {
    const turn = fakeTurn()

    contentHandlers.tts_generating({ status: 'tts_generating' }, turn)
    contentHandlers.audio({ status: 'audio', url: '/api/v1/files/uploads/reply.mp3' }, turn)

    expect(turn.message()?.parts.some((part) => part.type === 'audio')).toBe(true)
  })

  it('registers an incognito member reply audio for deletion at session end', () => {
    const turn = fakeMemberTurn(assistantMessage(), { incognito: true })

    memberContentHandlers.audio(
      { status: 'audio', url: '/api/v1/files/uploads/reply.mp3', file_id: 77 },
      turn
    )

    expect(turn.registerIncognitoFile).toHaveBeenCalledWith(77)
  })

  it('marks a failed voice reply and stops a member spoken reply', () => {
    const turn = fakeMemberTurn()
    const stop = vi.spyOn(turn.voice, 'stop')

    memberContentHandlers.voice_reply_failed(
      { status: 'voice_reply_failed', reason: 'provider_error' },
      turn
    )

    expect(turn.message()?.voiceReplyFailed).toBe('provider_error')
    expect(stop).toHaveBeenCalled()
  })

  it('records the run behind the turn and the documentation it cites', () => {
    const turn = fakeTurn()

    contentHandlers.run_started({ status: 'run_started', runId: 'run-9' }, turn)
    contentHandlers.docs_loaded(
      { status: 'docs_loaded', metadata: { docs: [{ slug: 'a', title: 'A', url: '/docs/a' }] } },
      turn
    )

    expect(turn.run.noteStarted).toHaveBeenCalledWith('run-9')
    expect(turn.message()?.docs).toEqual([{ slug: 'a', title: 'A', url: '/docs/a' }])
  })
})
