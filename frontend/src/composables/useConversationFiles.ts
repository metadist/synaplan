import { computed, ref, watch } from 'vue'

import { chatApi, type ConversationFileRow } from '@/services/api/chatApi'
import { chatGoneStatus } from '@/utils/chatAccessError'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore } from '@/stores/history'

export type { ConversationFileRow }

/**
 * History attachments are camelCase after {@see mapApiMessageRow}, but some
 * loaders and optimistic rows still carry snake_case (`file_id`, `file_type`).
 * Read both so the bar still lists files from a loaded chat.
 */
type HistoryAttachment = {
  id?: number | null
  file_id?: number | null
  filename?: string
  fileName?: string
  fileType?: string
  file_type?: string
}

type HistoryMessageLike = {
  role: string
  files?: HistoryAttachment[]
  backendMessageId?: number | null
  backend_message_id?: number | null
  isStreaming?: boolean
}

const categoryFromType = (fileType: string): ConversationFileRow['category'] => {
  const ext = fileType.toLowerCase()
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'image'
  if (['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'].includes(ext)) return 'video'
  if (['mp3', 'wav', 'ogg', 'm4a', 'flac', 'opus', 'oga', 'aac'].includes(ext)) return 'audio'
  if (
    [
      'pdf',
      'doc',
      'docx',
      'xls',
      'xlsx',
      'ppt',
      'pptx',
      'txt',
      'md',
      'rtf',
      'odt',
      'ods',
      'odp',
      'csv',
    ].includes(ext)
  ) {
    return 'document'
  }

  return 'other'
}

const attachmentId = (file: HistoryAttachment): number | null => {
  const id = file.id ?? file.file_id
  return typeof id === 'number' && Number.isFinite(id) ? id : null
}

const attachmentName = (file: HistoryAttachment): string => file.filename || file.fileName || ''

const attachmentType = (file: HistoryAttachment): string => file.fileType || file.file_type || ''

const historyMessageId = (message: HistoryMessageLike): number | null =>
  message.backendMessageId ?? message.backend_message_id ?? null

const historyFileSignature = (messages: HistoryMessageLike[]): string =>
  messages
    .map((message) =>
      (message.files ?? []).map((file) => String(attachmentId(file) ?? '')).join('+')
    )
    .join('|')

/**
 * Files this chat can still use: the server catalog plus anything already
 * sitting on loaded messages (a just-uploaded PDF is visible before the
 * next GET /chats/{id}/files round-trip).
 */
export function useConversationFiles() {
  const chatsStore = useChatsStore()
  const historyStore = useHistoryStore()
  const authStore = useAuthStore()
  const fromApi = ref<ConversationFileRow[]>([])

  const fromMessages = computed((): ConversationFileRow[] => {
    const seen = new Set<number>()
    const rows: ConversationFileRow[] = []

    for (const message of historyStore.messages as HistoryMessageLike[]) {
      for (const file of message.files ?? []) {
        const id = attachmentId(file)
        if (id === null || seen.has(id)) {
          continue
        }
        seen.add(id)
        const fileType = attachmentType(file)
        rows.push({
          id,
          reference: `file:${id}`,
          name: attachmentName(file),
          category: categoryFromType(fileType),
          origin: message.role === 'assistant' ? 'generated' : 'uploaded',
          fileType,
          messageId: historyMessageId(message),
          hasText: false,
        })
      }
    }

    return rows
  })

  const files = computed((): ConversationFileRow[] => {
    const seen = new Set<string>()
    const merged: ConversationFileRow[] = []

    for (const row of [...fromApi.value, ...fromMessages.value]) {
      const key = row.id !== null ? `id:${row.id}` : row.reference
      if (seen.has(key)) {
        continue
      }
      seen.add(key)
      merged.push(row)
    }

    return merged
  })

  const refresh = async () => {
    const chatId = chatsStore.activeChatId
    if (!chatId || !authStore.isAuthenticated) {
      fromApi.value = []
      return
    }

    try {
      const response = await chatApi.getConversationFiles(chatId)
      fromApi.value = response.files
    } catch (error) {
      fromApi.value = []
      const gone = chatGoneStatus(error)
      if (gone && chatsStore.activeChatId === chatId) {
        void chatsStore.releaseUnavailableChat(chatId, gone)
      }
    }
  }

  watch(
    () => chatsStore.activeChatId,
    () => {
      void refresh()
    },
    { immediate: true }
  )

  // A turn that adds files (upload or generated artefact) must refresh the
  // server catalog — switching chats already does this via activeChatId.
  watch(
    () => historyFileSignature(historyStore.messages as HistoryMessageLike[]),
    (next, prev) => {
      if (next === prev) {
        return
      }
      void refresh()
    }
  )

  watch(
    () =>
      (historyStore.messages as HistoryMessageLike[]).some(
        (message) => message.isStreaming === true
      ),
    (streaming, wasStreaming) => {
      if (wasStreaming && !streaming) {
        void refresh()
      }
    }
  )

  return { files, refresh }
}
