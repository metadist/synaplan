import { describe, expect, it } from 'vitest'
import { researchStepCopy } from '@/components/research/researchStepCopy'

const t = (key: string, params?: Record<string, unknown>): string =>
  params ? `${key}:${JSON.stringify(params)}` : key

describe('researchStepCopy', () => {
  it('tells what the fact-check left out and who checked', () => {
    const copy = researchStepCopy(
      t,
      {
        kind: 'check',
        text: '35/49',
        status: null,
        ref: null,
        check: { removed: 14, sameProvider: true },
      },
      []
    )

    expect(copy.title).toBe('research.step.check:{"ratio":"35/49"}')
    expect(copy.detail).toBe(
      'research.step.checkRemoved:{"count":14} research.step.checkSameProvider'
    )
  })

  it('says nothing more when every statement stayed', () => {
    const copy = researchStepCopy(
      t,
      {
        kind: 'check',
        text: '5/5',
        status: null,
        ref: null,
        check: { removed: 0, sameProvider: false },
      },
      []
    )

    expect(copy.detail).toBe('')
  })
})
