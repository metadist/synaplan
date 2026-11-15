import { attachmentMediaKind } from '@/components/chat/composerAttachment'
import { isOfficeConvertEnabled } from '@/composables/useOfficeConvertFeature'
import { extensionOf, kindFromExtension } from '@/services/filePreview'
import type { MessageFile } from '@/stores/history'

export function messageFileSrc(file: MessageFile, guestSessionId: string | null): string {
  if (guestSessionId) {
    return `/api/v1/guest/files/${encodeURIComponent(guestSessionId)}/${file.id}/download`
  }
  return `/api/v1/files/${file.id}/download`
}

export function isMessageMedia(file: MessageFile): boolean {
  return attachmentMediaKind(file.fileType || '', file.fileMime, file.filename) !== null
}

export function showOfficeActions(file: MessageFile): boolean {
  if (!file.id) return false
  const kind = kindFromExtension(extensionOf(file.filename) || file.fileType)
  if (kind === 'pdf') return true
  return isOfficeConvertEnabled() && kind === 'document'
}

export function officeSiblingIds(files: MessageFile[]): number[] {
  return files
    .filter((file) => {
      if (!file.id) return false
      const kind = kindFromExtension(extensionOf(file.filename) || file.fileType)
      return kind === 'document' || kind === 'pdf'
    })
    .map((file) => file.id)
}
