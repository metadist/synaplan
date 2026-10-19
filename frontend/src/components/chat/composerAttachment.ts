import { onUnmounted, watch, type Ref } from 'vue'
import { isImageFileType, isVideoFileType } from '@/utils/mediaTypes'

export interface UploadedFile {
  file_id: number
  filename: string
  file_type: string
  name?: string
  processing: boolean
  /** Picked in the composer, not from the Files library — DELETE on remove. */
  staged?: boolean
  /** MIME from the local File or the upload response. Not persisted. */
  mime?: string
  /** Object URL for an in-session preview. Not persisted; revoked when the row goes away. */
  previewUrl?: string
}

/** A file already stored on the server, reattached from a message or this chat. */
export interface ExistingAttachment {
  file_id: number
  filename: string
  file_type: string
  mime?: string
}

export function existingUploadedFile(file: ExistingAttachment): UploadedFile {
  return {
    file_id: file.file_id,
    filename: file.filename,
    file_type: file.file_type,
    mime: file.mime || undefined,
    processing: false,
  }
}

export function attachmentKey(file: UploadedFile, index: number): string {
  return `${file.filename || file.name || 'file'}-${index}`
}

/**
 * Bare kinds (`image`, `png`) stay as stored. A filename (`shot.png`) — the
 * fallback when reattach has no file type — is reduced to its extension, which
 * is all the media helpers match.
 */
function typeOrExtension(value: string): string {
  const trimmed = value.trim()
  if (!trimmed.includes('.') || trimmed.includes('/')) return trimmed
  return trimmed.split('.').pop() || trimmed
}

export function composerMediaKind(file: UploadedFile): 'image' | 'video' | null {
  const type = typeOrExtension(file.file_type || file.filename || file.name || '')
  if (isImageFileType(type, file.mime)) return 'image'
  if (isVideoFileType(type, file.mime)) return 'video'
  return null
}

export function attachmentPreviewSrc(file: UploadedFile): string {
  if (file.previewUrl) return file.previewUrl
  if (file.file_id > 0) return `/api/v1/files/${file.file_id}/download`
  return ''
}

export function stagedUploadPlaceholder(file: File): UploadedFile {
  return {
    file_id: 0,
    filename: file.name,
    file_type: file.name.split('.').pop() || 'unknown',
    name: file.name,
    processing: true,
    staged: true,
    mime: file.type || undefined,
    previewUrl: localPreviewUrl(file),
  }
}

export function readyUploadedFile(
  previous: UploadedFile,
  result: { file_id: number; filename: string; file_type: string; mime?: string }
): UploadedFile {
  return {
    file_id: result.file_id,
    filename: result.filename,
    file_type: result.file_type,
    name: previous.name,
    processing: false,
    staged: true,
    mime: result.mime || previous.mime,
    previewUrl: previous.previewUrl,
  }
}

/** Local object URL for a picture or video, so the preview shows before the upload returns. */
export function localPreviewUrl(file: File): string | undefined {
  const type = file.name.split('.').pop() || ''
  if (!isImageFileType(type, file.type) && !isVideoFileType(type, file.type)) return undefined
  if (typeof URL.createObjectURL !== 'function') return undefined
  try {
    return URL.createObjectURL(file)
  } catch {
    return undefined
  }
}

function revokeBlobUrl(url: string): void {
  if (typeof URL.revokeObjectURL !== 'function') return
  try {
    URL.revokeObjectURL(url)
  } catch {
    // Already revoked, or the URL was not created by this document.
  }
}

/** Revoke preview object URLs when their row leaves the composer or the composer unmounts. */
export function useComposerPreviewUrls(files: Ref<UploadedFile[]>): void {
  const tracked = new Set<string>()

  watch(
    files,
    (list) => {
      const current = new Set<string>()
      for (const file of list) {
        if (file.previewUrl) current.add(file.previewUrl)
      }
      for (const url of tracked) {
        if (!current.has(url)) {
          revokeBlobUrl(url)
          tracked.delete(url)
        }
      }
      for (const url of current) tracked.add(url)
    },
    { deep: true }
  )

  onUnmounted(() => {
    for (const url of tracked) revokeBlobUrl(url)
    tracked.clear()
  })
}
