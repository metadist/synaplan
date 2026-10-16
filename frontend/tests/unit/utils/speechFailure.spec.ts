import { describe, expect, it } from 'vitest'
import { speechFailureMessageKey } from '@/utils/speechFailure'

describe('speechFailureMessageKey', () => {
  it('names the recovery for a speech setup problem', () => {
    expect(speechFailureMessageKey('speech_off', 'dictation')).toBe('chatInput.speechOff')
    expect(speechFailureMessageKey('binary_missing', 'file')).toBe('chatInput.speechBinaryMissing')
    expect(speechFailureMessageKey('model_missing', 'dictation')).toBe(
      'chatInput.speechModelMissing'
    )
  })

  it('does not pass an unknown or empty code through as text', () => {
    expect(speechFailureMessageKey(undefined, 'dictation')).toBe('chatInput.dictationSttFailed')
    expect(speechFailureMessageKey('HTTP 502', 'file')).toBe('chatInput.audioTranscriptionFailed')
    expect(speechFailureMessageKey('transcription_failed', 'dictation')).toBe(
      'chatInput.dictationSttFailed'
    )
  })
})
