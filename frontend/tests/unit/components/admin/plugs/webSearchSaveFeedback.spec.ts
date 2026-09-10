import { describe, expect, it } from 'vitest'
import { webSearchSaveFeedback } from '@/components/admin/plugs/webSearchSaveFeedback'

const exa = {
  key: 'exa',
  label: 'Exa',
  health: { available: false, reason: 'Exa API key is not configured' },
}

const brave = {
  key: 'brave',
  label: 'Brave Search',
  health: { available: true, reason: null },
}

describe('webSearchSaveFeedback', () => {
  it('confirms only when the active provider is reachable', () => {
    expect(
      webSearchSaveFeedback({
        active: 'brave',
        fallback: '',
        providers: [brave, exa],
      })
    ).toEqual({
      level: 'success',
      key: 'saved',
      params: { provider: 'Brave Search', reason: '' },
    })
  })

  it('warns that search returns nothing when the active provider is unusable', () => {
    expect(
      webSearchSaveFeedback({
        active: 'exa',
        fallback: '',
        providers: [brave, exa],
      })
    ).toEqual({
      level: 'warning',
      key: 'savedInactive',
      params: { provider: 'Exa', reason: 'Exa API key is not configured' },
    })
  })

  it('warns that the fallback will be used when only the active provider is down', () => {
    expect(
      webSearchSaveFeedback({
        active: 'exa',
        fallback: 'brave',
        providers: [brave, exa],
      })
    ).toEqual({
      level: 'warning',
      key: 'savedInactiveFallback',
      params: { provider: 'Exa', reason: 'Exa API key is not configured' },
    })
  })

  it('warns when both the active provider and the fallback are unusable', () => {
    const downBrave = { ...brave, health: { available: false, reason: 'no key' } }
    expect(
      webSearchSaveFeedback({
        active: 'exa',
        fallback: 'brave',
        providers: [downBrave, exa],
      })
    ).toEqual({
      level: 'warning',
      key: 'savedInactiveBoth',
      params: { provider: 'Exa', reason: 'Exa API key is not configured' },
    })
  })
})
