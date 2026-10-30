import { describe, expect, it } from 'vitest'
import { AI_CREATED_SOURCE_QUERY, librarySourceParams } from '@/services/fileSources'

describe('librarySourceParams', () => {
  it('hides AI-created files unless a source is chosen', () => {
    expect(librarySourceParams('')).toEqual({ excludeSource: AI_CREATED_SOURCE_QUERY })
    expect(AI_CREATED_SOURCE_QUERY).toBe('generated,compute')
  })

  it('uses an explicit source instead of the exclusion', () => {
    expect(librarySourceParams('generated')).toEqual({ source: 'generated' })
    expect(librarySourceParams('web_upload')).toEqual({ source: 'web_upload' })
  })
})
