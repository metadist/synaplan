import { describe, expect, it } from 'vitest'

import {
  fileDisplayName,
  isVoiceMemoFilename,
  parseFileUploadedDate,
  vectorStateOf,
} from '@/utils/fileDisplayName'

const t = (key: string, values?: Record<string, unknown>): string => {
  if (key === 'files.voiceMemo') return 'Voice memo'
  if (key === 'files.voiceMemoNamed') return `Voice memo · ${values?.time}`
  return key
}

describe('isVoiceMemoFilename', () => {
  it('matches generic chat recording names', () => {
    expect(isVoiceMemoFilename('recording.webm')).toBe(true)
    expect(isVoiceMemoFilename('recording.m4a')).toBe(true)
    expect(isVoiceMemoFilename('recording.ogg')).toBe(true)
    expect(isVoiceMemoFilename('Recording.WEBM')).toBe(true)
  })

  it('rejects real titles', () => {
    expect(isVoiceMemoFilename('meeting-notes.webm')).toBe(false)
    expect(isVoiceMemoFilename('tts_abc.mp3')).toBe(false)
  })
})

describe('fileDisplayName', () => {
  it('keeps ordinary names', () => {
    expect(fileDisplayName({ filename: 'contract.pdf' }, t, 'en')).toBe('contract.pdf')
  })

  it('prefers a user-facing display_name that is not a generic recording', () => {
    expect(
      fileDisplayName({ filename: 'recording.webm', display_name: 'Call with Alex.webm' }, t, 'en')
    ).toBe('Call with Alex.webm')
  })

  it('labels generic voice notes with the capture time', () => {
    expect(
      fileDisplayName(
        { filename: 'recording.webm', uploaded_date: '2026-08-20 12:40:41' },
        t,
        'en-GB'
      )
    ).toBe('Voice memo · 12:40:41')
  })

  it('falls back to an untimed label when the timestamp is missing', () => {
    expect(fileDisplayName({ filename: 'recording.m4a' }, t, 'en')).toBe('Voice memo')
  })
})

describe('parseFileUploadedDate', () => {
  it('parses the file-list wall-clock format', () => {
    const date = parseFileUploadedDate('2026-08-20 12:40:41')
    expect(date).not.toBeNull()
    expect(date?.getFullYear()).toBe(2026)
    expect(date?.getMonth()).toBe(7)
    expect(date?.getDate()).toBe(20)
    expect(date?.getHours()).toBe(12)
    expect(date?.getMinutes()).toBe(40)
    expect(date?.getSeconds()).toBe(41)
  })
})

describe('vectorStateOf', () => {
  it('prefers vector_state and falls back to is_vectorized', () => {
    expect(vectorStateOf({ vector_state: 'failed' })).toBe('failed')
    expect(vectorStateOf({ is_vectorized: true })).toBe('vectorized')
    expect(vectorStateOf({})).toBe('none')
  })
})
