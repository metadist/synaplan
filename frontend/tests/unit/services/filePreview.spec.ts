import { describe, expect, it } from 'vitest'

import {
  extensionOf,
  hasTextSnippet,
  kindFromExtension,
  previewBadgeClass,
  previewIcon,
  previewIconForName,
  previewKindForFile,
  previewSnippet,
} from '@/services/filePreview'
import type { FileItem } from '@/services/filesService'

const file = (overrides: Partial<FileItem>): FileItem =>
  ({
    id: 1,
    filename: 'file.bin',
    path: '/x',
    file_type: '',
    file_size: 0,
    mime: '',
    status: 'ok',
    text_preview: '',
    uploaded_at: 0,
    uploaded_date: '2026-01-01',
    message_id: null,
    ...overrides,
  }) as FileItem

describe('filePreview helpers', () => {
  it('extracts the lowercased extension', () => {
    expect(extensionOf('Report.PDF')).toBe('pdf')
    expect(extensionOf('noext')).toBe('noext')
    expect(extensionOf(null)).toBe('')
  })

  it('maps extensions to preview kinds', () => {
    expect(kindFromExtension('png')).toBe('image')
    expect(kindFromExtension('mp4')).toBe('video')
    expect(kindFromExtension('mp3')).toBe('audio')
    expect(kindFromExtension('pdf')).toBe('pdf')
    expect(kindFromExtension('md')).toBe('text')
    expect(kindFromExtension('docx')).toBe('document')
    expect(kindFromExtension('ics')).toBe('calendar')
    expect(kindFromExtension('bin')).toBe('unknown')
  })

  it('prefers the filename extension over the coarse origin_kind', () => {
    // A .txt with origin_kind=document must resolve to text (so it renders a
    // snippet, not a bare document icon).
    expect(previewKindForFile(file({ filename: 'notes.txt', origin_kind: 'document' }))).toBe(
      'text'
    )
    expect(previewKindForFile(file({ filename: 'sheet.xlsx', origin_kind: 'document' }))).toBe(
      'document'
    )
  })

  it('falls back to origin_kind for extension-less generated media', () => {
    expect(previewKindForFile(file({ filename: 'generated', origin_kind: 'video' }))).toBe('video')
    expect(previewKindForFile(file({ filename: 'generated', origin_kind: 'image' }))).toBe('image')
  })

  it('returns a token-based neutral badge (no raw tailwind colors)', () => {
    const badge = previewBadgeClass()
    expect(badge).not.toMatch(/bg-(red|blue|purple|pink|emerald|gray|green|yellow|orange)-\d/)
    expect(badge).toContain('txt-secondary')
  })

  it('provides mdi icons per kind', () => {
    expect(previewIcon('audio')).toBe('mdi:music-note')
    expect(previewIcon('pdf')).toBe('mdi:file-pdf-box')
    expect(previewIconForName('clip.mp4')).toBe('mdi:play-circle-outline')
  })

  it('reads the trimmed text_preview snippet', () => {
    expect(previewSnippet(file({ text_preview: '  hello  ' }))).toBe('hello')
    expect(hasTextSnippet(file({ text_preview: '' }))).toBe(false)
    expect(hasTextSnippet(file({ text_preview: 'x' }))).toBe(true)
  })
})
