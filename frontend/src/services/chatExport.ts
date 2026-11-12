import { httpClient } from '@/services/api/httpClient'

/** Download one chat. The caller has already checked that a session is open. */
export async function saveChatExport(chatId: number, format: 'md' | 'json' | 'pdf'): Promise<void> {
  const blob = await httpClient<Blob>(`/api/v1/chats/${chatId}/export?format=${format}`, {
    responseType: 'blob',
  })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `chat-${chatId}.${format === 'md' ? 'md' : format}`
  link.click()
  URL.revokeObjectURL(url)
}
