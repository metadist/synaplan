import { describe, expect, it } from 'vitest'
import { fileOnlyTurnMessages } from '@/utils/fileOnlyTurn'

describe('fileOnlyTurnMessages', () => {
  it('keeps typed text for the bubble and the backend', () => {
    expect(fileOnlyTurnMessages('what is on this?', true, false, 'voice')).toEqual({
      display: 'what is on this?',
      backend: 'what is on this?',
    })
  })

  it('does not invent a review command for a file-only send', () => {
    expect(fileOnlyTurnMessages('', true, false, 'voice')).toEqual({
      display: '',
      backend: '',
    })
    expect(fileOnlyTurnMessages('   ', true, false, 'voice')).toEqual({
      display: '',
      backend: '',
    })
  })

  it('keeps a voice placeholder in the bubble and sends empty text', () => {
    expect(fileOnlyTurnMessages('', true, true, 'Please respond to my voice message.')).toEqual({
      display: 'Please respond to my voice message.',
      backend: '',
    })
  })

  it('does nothing when there are no files', () => {
    expect(fileOnlyTurnMessages('', false, false, 'voice')).toEqual({
      display: '',
      backend: '',
    })
  })
})
