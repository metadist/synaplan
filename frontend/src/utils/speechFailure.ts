/**
 * Map a server speech_failure code to a chatInput message key.
 *
 * Setup codes name the next step. Anything else is the recording itself,
 * and the raw provider text is never shown.
 */
export function speechFailureMessageKey(
  code: string | undefined,
  source: 'dictation' | 'file'
): string {
  switch (code) {
    case 'speech_off':
      return 'chatInput.speechOff'
    case 'binary_missing':
      return 'chatInput.speechBinaryMissing'
    case 'model_missing':
      return 'chatInput.speechModelMissing'
    default:
      return source === 'file' ? 'chatInput.audioTranscriptionFailed' : 'chatInput.dictationSttFailed'
  }
}
