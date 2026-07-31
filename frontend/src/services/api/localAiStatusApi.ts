import type { z } from 'zod'
import { GetApiConfigLocalAiDownloadStatusResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type LocalAiDownloadStatus = z.infer<typeof GetApiConfigLocalAiDownloadStatusResponseSchema>

/**
 * Poll Ollama auto-download progress written by the backend entrypoint.
 */
export const getLocalAiDownloadStatus = async (): Promise<LocalAiDownloadStatus> => {
  return httpClient('/api/v1/config/local-ai/status', {
    schema: GetApiConfigLocalAiDownloadStatusResponseSchema,
  })
}
