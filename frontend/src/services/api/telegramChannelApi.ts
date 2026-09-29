import type { z } from 'zod'
import {
  DeleteApiTelegramChannelDisconnectResponseSchema,
  GetApiTelegramChannelGetResponseSchema,
  PostApiTelegramChannelConnectResponseSchema,
  PostApiTelegramChannelRenewPairingResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type TelegramChannelState = z.infer<typeof GetApiTelegramChannelGetResponseSchema>

export function getTelegramChannel(): Promise<TelegramChannelState> {
  return httpClient('/api/v1/channels/telegram', {
    schema: GetApiTelegramChannelGetResponseSchema,
  })
}

export function connectTelegram(token: string): Promise<TelegramChannelState> {
  return httpClient('/api/v1/channels/telegram', {
    method: 'POST',
    body: JSON.stringify({ token }),
    schema: PostApiTelegramChannelConnectResponseSchema,
  })
}

export function renewTelegramPairing(): Promise<TelegramChannelState> {
  return httpClient('/api/v1/channels/telegram/pairing', {
    method: 'POST',
    schema: PostApiTelegramChannelRenewPairingResponseSchema,
  })
}

export function disconnectTelegram(): Promise<TelegramChannelState> {
  return httpClient('/api/v1/channels/telegram', {
    method: 'DELETE',
    schema: DeleteApiTelegramChannelDisconnectResponseSchema,
  })
}
