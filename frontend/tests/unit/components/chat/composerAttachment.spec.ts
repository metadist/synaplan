import { describe, expect, it } from 'vitest'

import { composerMediaKind, type UploadedFile } from '@/components/chat/composerAttachment'

const file = (overrides: Partial<UploadedFile>): UploadedFile => ({
  file_id: 1,
  filename: 'notes.txt',
  file_type: 'txt',
  processing: false,
  ...overrides,
})

describe('composerMediaKind', () => {
  it('classifies a filename when reattach sends no file type', () => {
    expect(composerMediaKind(file({ file_type: '', filename: 'shot.png' }))).toBe('image')
    expect(composerMediaKind(file({ file_type: '', filename: 'clip.mp4' }))).toBe('video')
    expect(composerMediaKind(file({ file_type: '', name: 'shot.png', filename: '' }))).toBe('image')
  })

  it('keeps bare kinds and extensions', () => {
    expect(composerMediaKind(file({ file_type: 'image', filename: 'generated' }))).toBe('image')
    expect(composerMediaKind(file({ file_type: 'png', filename: 'shot.png' }))).toBe('image')
    expect(composerMediaKind(file({ file_type: 'pdf', filename: 'contract.pdf' }))).toBeNull()
  })

  it('treats webm as video only when the MIME says so', () => {
    expect(composerMediaKind(file({ file_type: 'webm', filename: 'clip.webm' }))).toBeNull()
    expect(
      composerMediaKind(file({ file_type: '', filename: 'clip.webm', mime: 'video/webm' }))
    ).toBe('video')
    expect(
      composerMediaKind(file({ file_type: 'webm', filename: 'note.webm', mime: 'audio/webm' }))
    ).toBeNull()
  })
})
