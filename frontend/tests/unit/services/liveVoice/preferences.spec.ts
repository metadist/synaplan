import { beforeEach, describe, expect, it } from 'vitest'
import {
  preferredVoice,
  resolveLiveVoice,
  setPreferredVoice,
} from '@/services/liveVoice/preferences'

const KEY = 'synaplan.liveVoice.voices'
const REALTIME = ['marin', 'alloy', 'cedar']
const LIVE = ['marin', 'quartz', 'cinder']

describe('live voice preference', () => {
  beforeEach(() => {
    localStorage.removeItem(KEY)
  })

  it('keeps a voice on the model that chose it', () => {
    setPreferredVoice(389, 'quartz')

    expect(preferredVoice(389, 'openai', LIVE)).toBe('quartz')
    expect(preferredVoice(391, 'openai', REALTIME)).toBe('')
    expect(JSON.parse(localStorage.getItem(KEY) ?? '{}')).toEqual({
      models: { '389': 'quartz' },
    })
  })

  it('uses an older provider voice only when this model still offers it', () => {
    localStorage.setItem(KEY, JSON.stringify({ openai: 'marin', xai: 'quartz' }))

    expect(preferredVoice(391, 'openai', REALTIME)).toBe('marin')
    expect(preferredVoice(389, 'openai', LIVE)).toBe('marin')
    expect(preferredVoice(394, 'xai', ['eve', 'ara'])).toBe('')
  })

  it('lets the model choice replace the older provider voice', () => {
    localStorage.setItem(KEY, JSON.stringify({ openai: 'marin' }))
    setPreferredVoice(389, 'quartz')

    expect(preferredVoice(389, 'openai', LIVE)).toBe('quartz')
    expect(preferredVoice(391, 'openai', REALTIME)).toBe('marin')
    expect(JSON.parse(localStorage.getItem(KEY) ?? '{}').openai).toBe('marin')
  })

  it('resolves to the model default when nothing valid is stored', () => {
    localStorage.setItem(KEY, JSON.stringify({ models: { '389': 'quartz' } }))

    expect(resolveLiveVoice(391, 'openai', REALTIME, 'marin')).toBe('marin')
    expect(resolveLiveVoice(389, 'openai', LIVE, 'marin')).toBe('quartz')
    expect(resolveLiveVoice(395, 'compatible', [], '')).toBe('')
  })
})
