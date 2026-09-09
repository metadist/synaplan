import {
  GetApiWhatsappAssistantGetResponseSchema,
  PutApiWhatsappAssistantPutResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export async function getWhatsAppAssistant(): Promise<number | null> {
  const data = await httpClient('/api/v1/channels/whatsapp/assistant', {
    schema: GetApiWhatsappAssistantGetResponseSchema,
  })
  return data.agentId ?? null
}

export async function setWhatsAppAssistant(agentId: number | null): Promise<number | null> {
  const data = await httpClient('/api/v1/channels/whatsapp/assistant', {
    method: 'PUT',
    body: JSON.stringify({ agentId }),
    schema: PutApiWhatsappAssistantPutResponseSchema,
  })
  return data.agentId ?? null
}
