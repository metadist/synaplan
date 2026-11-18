import { httpClient } from '@/services/api/httpClient'
import { saveOrDownloadBlob } from '@/services/api/nativeDownload'

export const REPLY_EXPORT_FORMATS = ['docx', 'html', 'md'] as const
export type ReplyExportFormat = (typeof REPLY_EXPORT_FORMATS)[number]

/**
 * Download one reply as a file and save it. The server names the file
 * after the reply's title; the message id is only the fallback name.
 */
export async function downloadReplyExport(
  messageId: number,
  format: ReplyExportFormat
): Promise<void> {
  const file = await httpClient<File>(`/api/v1/messages/${messageId}/export`, {
    params: { format },
    responseType: 'file',
  })
  await saveOrDownloadBlob(file, file.name || `research-${messageId}.${format}`)
}
