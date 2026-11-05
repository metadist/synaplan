import { z } from 'zod'
import { ApiError, httpClient } from './httpClient'

const noticeSchema = z.object({
  success: z.boolean(),
  text: z.string(),
  defaultText: z.string(),
})

export async function readChannelNotice(
  channel: 'mail' | 'whatsapp' | 'telegram',
  bindingId: number
): Promise<{ text: string; defaultText: string }> {
  const data = await httpClient(`/api/v1/channels/${channel}/notice?binding=${bindingId}`, {
    schema: noticeSchema,
  })
  return { text: data.text, defaultText: data.defaultText }
}

export async function saveChannelNotice(
  channel: 'mail' | 'whatsapp' | 'telegram',
  bindingId: number,
  text: string
): Promise<void> {
  await httpClient(`/api/v1/channels/${channel}/notice`, {
    method: 'PUT',
    body: JSON.stringify({ bindingId, text }),
  })
}

export function noticeError(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}
