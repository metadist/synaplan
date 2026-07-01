import { describe, it, expect } from 'vitest'
import { audioRecordingFilename } from '@/services/api/chatApi'

describe('audioRecordingFilename', () => {
  it('maps Safari/macOS audio/mp4 to .m4a (audio-routed, not video .mp4)', () => {
    expect(audioRecordingFilename('audio/mp4')).toBe('recording.m4a')
    expect(audioRecordingFilename('audio/x-m4a')).toBe('recording.m4a')
    expect(audioRecordingFilename('audio/aac')).toBe('recording.m4a')
  })

  it('keeps Chrome/Firefox containers correct', () => {
    expect(audioRecordingFilename('audio/webm')).toBe('recording.webm')
    expect(audioRecordingFilename('audio/ogg')).toBe('recording.ogg')
    expect(audioRecordingFilename('audio/mpeg')).toBe('recording.mp3')
    expect(audioRecordingFilename('audio/wav')).toBe('recording.wav')
  })

  it('strips codec parameters from the MIME type', () => {
    expect(audioRecordingFilename('audio/webm;codecs=opus')).toBe('recording.webm')
    expect(audioRecordingFilename('audio/mp4; codecs="mp4a.40.2"')).toBe('recording.m4a')
  })

  it('defaults to webm for unknown or missing MIME types', () => {
    expect(audioRecordingFilename(undefined)).toBe('recording.webm')
    expect(audioRecordingFilename('')).toBe('recording.webm')
    expect(audioRecordingFilename('application/octet-stream')).toBe('recording.webm')
  })
})
