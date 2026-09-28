import { describe, expect, it } from 'vitest'
import {
  initialReasoningLevel,
  modelReasoningLevels,
  reasoningSendFlags,
} from '@/utils/reasoningLevel'

describe('reasoning level', () => {
  it('hides the level control when the model does not publish one', () => {
    expect(modelReasoningLevels({ reasoningLevels: [] })).toEqual([])
    expect(modelReasoningLevels(null)).toEqual([])
    expect(modelReasoningLevels({ reasoningLevels: ['low', 'high'] })).toEqual(['low', 'high'])
  })

  it('starts on the catalog default and drops a level the model rejects', () => {
    const levels = ['medium', 'high', 'xhigh']
    expect(initialReasoningLevel(levels, 'high')).toBe('high')
    expect(initialReasoningLevel(levels, 'max')).toBe('medium')
    expect(initialReasoningLevel(levels, null)).toBe('medium')
  })

  it('sends the chosen level and treats only none and minimal as off', () => {
    const levels = ['none', 'low', 'medium', 'high']
    expect(reasoningSendFlags(levels, 'none', true)).toEqual({
      includeReasoning: false,
      reasoningEffort: 'none',
    })
    expect(reasoningSendFlags(levels, 'low', false)).toEqual({
      includeReasoning: true,
      reasoningEffort: 'low',
    })
    expect(reasoningSendFlags(['minimal', 'low', 'high'], 'minimal', true)).toEqual({
      includeReasoning: false,
      reasoningEffort: 'minimal',
    })
  })

  it('keeps the Thinking toggle when there is no level to send', () => {
    expect(reasoningSendFlags([], '', true)).toEqual({ includeReasoning: true })
    expect(reasoningSendFlags(['low', 'high'], 'xhigh', false)).toEqual({
      includeReasoning: false,
    })
  })
})
