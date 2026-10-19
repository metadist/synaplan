import type { MessageFile } from '@/stores/history'

export interface MessageFilePreview {
  id: number
  filename: string
  fileSize?: number
  fileType: string
  fileMime?: string
}

export function messageFilePreview(file: MessageFile): MessageFilePreview {
  return {
    id: file.id,
    filename: file.filename,
    fileSize: file.fileSize,
    fileType: file.fileType,
    fileMime: file.fileMime,
  }
}

export function reattachFromPreview(file: MessageFilePreview): MessageFile {
  return {
    id: file.id,
    filename: file.filename,
    fileType: file.fileType,
    filePath: '',
    fileSize: file.fileSize,
    fileMime: file.fileMime,
  }
}
