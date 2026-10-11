import { describe, expect, it } from 'vitest'
import { chooseControlBarFit } from '@/utils/fitControlBar'

describe('chooseControlBarFit', () => {
  it('keeps the tools summary and the model name when the row fits', () => {
    expect(chooseControlBarFit(() => true)).toEqual({
      hideToolsSummary: false,
      collapseModelName: false,
    })
  })

  it('hides the tools summary before it collapses the model name', () => {
    expect(chooseControlBarFit((hideSummary) => hideSummary)).toEqual({
      hideToolsSummary: true,
      collapseModelName: false,
    })
  })

  it('collapses the model name only after the summary is already gone', () => {
    expect(chooseControlBarFit((hideSummary, collapseName) => hideSummary && collapseName)).toEqual(
      { hideToolsSummary: true, collapseModelName: true }
    )
  })
})
