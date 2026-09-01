import type { FileVectorState } from '@/services/filesService'

/** Generic chat voice-note names from `audioRecordingFilename()`. */
const VOICE_MEMO_BASENAMES = new Set([
  'recording.webm',
  'recording.m4a',
  'recording.ogg',
  'recording.mp3',
  'recording.wav',
])

export type FileNameFields = {
  filename: string
  display_name?: string
  original_name?: string | null
  uploaded_date?: string
}

export function isVoiceMemoFilename(name: string | null | undefined): boolean {
  if (!name) return false
  const base = name.trim().split(/[/\\]/).pop()?.toLowerCase() ?? ''
  return VOICE_MEMO_BASENAMES.has(base)
}

/** Parse the file-list timestamp (`Y-m-d H:i:s`) as local wall time. */
export function parseFileUploadedDate(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/.exec(value.trim())
  if (match) {
    return new Date(
      Number(match[1]),
      Number(match[2]) - 1,
      Number(match[3]),
      Number(match[4]),
      Number(match[5]),
      Number(match[6] ?? 0)
    )
  }
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

export function fileDisplayName(
  file: FileNameFields,
  translate: (key: string, values?: Record<string, unknown>) => string,
  locale: string
): string {
  const raw = file.display_name || file.original_name || file.filename
  if (file.display_name && !isVoiceMemoFilename(file.display_name)) {
    return file.display_name
  }
  if (!isVoiceMemoFilename(raw) && !isVoiceMemoFilename(file.filename)) {
    return raw
  }

  const uploaded = file.uploaded_date ? parseFileUploadedDate(file.uploaded_date) : null
  if (!uploaded) {
    return translate('files.voiceMemo')
  }
  const time = new Intl.DateTimeFormat(locale, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(uploaded)
  return translate('files.voiceMemoNamed', { time })
}

export function vectorStateOf(file: {
  vector_state?: FileVectorState
  is_vectorized?: boolean
}): FileVectorState {
  return file.vector_state ?? (file.is_vectorized ? 'vectorized' : 'none')
}
