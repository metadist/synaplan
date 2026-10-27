import { describe, expect, it } from 'vitest'
import { parseSearchRecovery } from '@/utils/searchRecovery'

describe('parseSearchRecovery', () => {
  it('keeps the queries and the match', () => {
    expect(
      parseSearchRecovery({
        attempts: [
          { query: 'Metadsit', hits: 0 },
          { query: 'Metadist', hits: 1 },
        ],
        matched_query: 'Metadist',
        empty: false,
      })
    ).toEqual({
      attempts: [
        { query: 'Metadsit', hits: 0 },
        { query: 'Metadist', hits: 1 },
      ],
      matchedQuery: 'Metadist',
    })
  })

  it('drops a payload that is not a list of searches', () => {
    expect(parseSearchRecovery({ attempts: [] })).toBeUndefined()
    expect(parseSearchRecovery({ attempts: [{ query: '  ', hits: 1 }] })).toBeUndefined()
    expect(parseSearchRecovery(null)).toBeUndefined()
  })
})
