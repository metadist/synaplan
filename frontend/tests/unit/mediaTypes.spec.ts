import { describe, expect, it } from 'vitest'

import {
  buildUploadUrl,
  isAudioFileType,
  isImageFileType,
  isVideoFileType,
} from '@/utils/mediaTypes'

/**
 * Issue #955 regression coverage.
 *
 * Before the fix, `history.ts` only rendered an `<audio>` player for
 * messages whose `BFILETYPE` was the literal string `audio`. WhatsApp
 * voice notes and direct user uploads kept their raw extension (`ogg`,
 * `mp3`, …) on `BFILETYPE`, so they silently degraded to a text-only
 * bubble with a download badge. These tests pin the extension-aware
 * detection so the gap can't be reintroduced.
 */
describe('isAudioFileType', () => {
  it.each([
    ['audio'],
    ['ogg'],
    ['mp3'],
    ['wav'],
    ['m4a'],
    ['opus'],
    ['flac'],
    ['webm'],
    ['amr'],
    ['aac'],
    ['OGG'], // case-insensitive
    ['  MP3  '], // trimmed
  ])('returns true for "%s"', (type) => {
    expect(isAudioFileType(type)).toBe(true)
  })

  it.each([
    [''],
    [null],
    [undefined],
    ['png'],
    ['pdf'],
    ['docx'],
    ['mp4'],
    ['image'],
    ['video'],
    ['text'],
  ])('returns false for %j', (type) => {
    expect(isAudioFileType(type)).toBe(false)
  })
})

describe('isImageFileType', () => {
  it.each([['image'], ['png'], ['jpg'], ['JPEG'], ['webp'], ['gif'], ['svg']])(
    'returns true for "%s"',
    (type) => {
      expect(isImageFileType(type)).toBe(true)
    }
  )

  it.each([[''], [null], [undefined], ['audio'], ['mp3'], ['mp4'], ['pdf']])(
    'returns false for %j',
    (type) => {
      expect(isImageFileType(type)).toBe(false)
    }
  )
})

describe('isVideoFileType', () => {
  it.each([['video'], ['mp4'], ['MOV'], ['avi'], ['mkv']])('returns true for "%s"', (type) => {
    expect(isVideoFileType(type)).toBe(true)
  })

  it.each([[''], [null], [undefined], ['audio'], ['mp3'], ['ogg'], ['png']])(
    'returns false for %j',
    (type) => {
      expect(isVideoFileType(type)).toBe(false)
    }
  )
})

describe('buildUploadUrl', () => {
  it('prefixes relative paths with the static-serve endpoint', () => {
    const url = buildUploadUrl('13/000/00013/2025/12/voice.ogg')
    expect(url).toBe('/api/v1/files/uploads/13/000/00013/2025/12/voice.ogg')
  })

  it('strips a leading slash before prefixing', () => {
    const url = buildUploadUrl('/13/voice.ogg')
    expect(url).toBe('/api/v1/files/uploads/13/voice.ogg')
  })

  it('returns absolute URLs unchanged', () => {
    expect(buildUploadUrl('https://cdn.example.com/voice.ogg')).toBe(
      'https://cdn.example.com/voice.ogg'
    )
    expect(buildUploadUrl('http://localhost/voice.ogg')).toBe('http://localhost/voice.ogg')
  })

  it('returns paths that already point at the upload endpoint unchanged', () => {
    expect(buildUploadUrl('/api/v1/files/uploads/13/voice.ogg')).toBe(
      '/api/v1/files/uploads/13/voice.ogg'
    )
    expect(buildUploadUrl('/up/13/voice.ogg')).toBe('/up/13/voice.ogg')
  })

  it('returns an empty string for falsy input', () => {
    expect(buildUploadUrl('')).toBe('')
    expect(buildUploadUrl(null)).toBe('')
    expect(buildUploadUrl(undefined)).toBe('')
    expect(buildUploadUrl('   ')).toBe('')
  })
})
