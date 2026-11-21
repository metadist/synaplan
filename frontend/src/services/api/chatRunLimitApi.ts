import type { z } from 'zod'
import { GetChatRunLimitGetResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ChatRunLimit = z.infer<typeof GetChatRunLimitGetResponseSchema>

export async function getChatRunLimit(): Promise<ChatRunLimit> {
  return httpClient('/api/v1/config/chat-run-limit', {
    schema: GetChatRunLimitGetResponseSchema,
  })
}

export async function saveChatRunLimit(percent: number | null): Promise<ChatRunLimit> {
  return httpClient('/api/v1/config/chat-run-limit', {
    method: 'PUT',
    body: JSON.stringify({ percent }),
    schema: GetChatRunLimitGetResponseSchema,
  })
}
