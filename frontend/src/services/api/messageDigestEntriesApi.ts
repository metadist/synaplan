import { z } from 'zod'
import {
  DeleteAllUserMessageDigestsResponseSchema,
  DeleteUserMessageDigestResponseSchema,
  GetUserMessageDigestEntriesResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type MessageDigestEntriesPage = z.infer<typeof GetUserMessageDigestEntriesResponseSchema>
export type MessageDigestListEntry = MessageDigestEntriesPage['entries'][number]

export async function listMessageDigestEntries(
  page = 1,
  limit = 25
): Promise<MessageDigestEntriesPage> {
  return httpClient(`/api/v1/user/message-digests/entries?page=${page}&limit=${limit}`, {
    schema: GetUserMessageDigestEntriesResponseSchema,
  })
}

export async function deleteMessageDigestEntry(
  id: number
): Promise<z.infer<typeof DeleteUserMessageDigestResponseSchema>> {
  return httpClient(`/api/v1/user/message-digests/${id}`, {
    method: 'DELETE',
    schema: DeleteUserMessageDigestResponseSchema,
  })
}

export async function deleteAllMessageDigestEntries(): Promise<
  z.infer<typeof DeleteAllUserMessageDigestsResponseSchema>
> {
  return httpClient('/api/v1/user/message-digests', {
    method: 'DELETE',
    schema: DeleteAllUserMessageDigestsResponseSchema,
  })
}
