import { z } from 'zod'
import {
  GetApiResearchCapabilitiesResponseSchema,
  GetApiResearchShowResponseSchema,
  PostApiResearchCancelResponseSchema,
  PostApiResearchStartResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ResearchCapabilities = z.infer<typeof GetApiResearchCapabilitiesResponseSchema>
export type ResearchRunView = z.infer<typeof GetApiResearchShowResponseSchema>

export async function fetchResearchCapabilities(): Promise<ResearchCapabilities> {
  return httpClient('/api/v1/research/capabilities', {
    schema: GetApiResearchCapabilitiesResponseSchema,
  })
}

export async function startResearch(body: {
  question: string
  depth: 'quick' | 'standard' | 'thorough'
  chat_id: number
  sources: { web: boolean; knowledge: boolean }
}): Promise<z.infer<typeof PostApiResearchStartResponseSchema>> {
  return httpClient('/api/v1/research', {
    method: 'POST',
    body: JSON.stringify(body),
    headers: { 'Idempotency-Key': crypto.randomUUID() },
    schema: PostApiResearchStartResponseSchema,
  })
}

export async function fetchResearchRun(id: string): Promise<ResearchRunView> {
  return httpClient(`/api/v1/research/${encodeURIComponent(id)}`, {
    schema: GetApiResearchShowResponseSchema,
  })
}

export async function cancelResearch(id: string): Promise<ResearchRunView> {
  return httpClient(`/api/v1/research/${encodeURIComponent(id)}/cancel`, {
    method: 'POST',
    schema: PostApiResearchCancelResponseSchema,
  })
}

/** The events route is SSE text. The JSON schema for it is empty on purpose. */
export async function fetchResearchEvents(id: string, after: number): Promise<string> {
  return httpClient(`/api/v1/research/${encodeURIComponent(id)}/events`, {
    params: { after: String(after) },
    responseType: 'text',
  })
}
