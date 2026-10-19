import { ref } from 'vue'

import type { ExistingAttachment } from '@/components/chat/composerAttachment'

export interface ConversationPreview {
  id: number
  filename: string
  fileType: string
  mime?: string
}

export interface ConversationFileAttach {
  id: number | null
  name: string
  fileType?: string
  mime?: string
}

type ComposerWithAttach = {
  attachExistingFile: (file: ExistingAttachment) => void
} | null

export function useConversationFilePreview(composer: () => ComposerWithAttach) {
  const conversationPreview = ref<ConversationPreview | null>(null)

  const previewConversationFile = (file: ConversationFileAttach) => {
    if (file.id == null) return
    conversationPreview.value = {
      id: file.id,
      filename: file.name,
      fileType: file.fileType || '',
      mime: file.mime,
    }
  }

  const downloadConversationPreview = async () => {
    const file = conversationPreview.value
    if (!file) return
    const { downloadFile } = await import('@/services/filesService')
    await downloadFile(file.id, file.filename)
  }

  const attachConversationFile = (file: ConversationFileAttach) => {
    if (file.id === null) return
    composer()?.attachExistingFile({
      file_id: file.id,
      filename: file.name,
      file_type: file.fileType || '',
      mime: file.mime,
    })
  }

  const attachOpenPreview = () => {
    const file = conversationPreview.value
    if (!file) return
    attachConversationFile({
      id: file.id,
      name: file.filename,
      fileType: file.fileType,
      mime: file.mime,
    })
  }

  const reattachMessageFile = (file: {
    id: number
    filename: string
    fileType?: string
    fileMime?: string
  }) => {
    composer()?.attachExistingFile({
      file_id: file.id,
      filename: file.filename,
      file_type: file.fileType || '',
      mime: file.fileMime,
    })
  }

  return {
    conversationPreview,
    previewConversationFile,
    downloadConversationPreview,
    attachConversationFile,
    attachOpenPreview,
    reattachMessageFile,
  }
}
