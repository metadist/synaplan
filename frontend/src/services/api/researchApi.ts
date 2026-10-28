import { z } from 'zod'
import {
  GetApiResearchCapabilitiesResponseSchema,
  GetApiResearchShowResponseSchema,
  PostApiResearchCancelResponseSchema,
  PostApiResearchInputResponseSchema,
  PostApiResearchStartResponseSchema,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type ResearchCapabilities = z.infer<typeof GetApiResearchCapabilitiesResponseSchema>
export type ResearchRunView = z.infer<typeof GetApiResearchShowResponseSchema>

export async function fetchResearchCapabilities(): Promise<ResearchCapabilities> {
  return httpClient('/api/v1/research/capabilities', {
    schema: GetApiResearchCapabilitiesResponseSchema,
    // A guest is not signed in. A 401 from this probe must not log them out.
    skipAuth: true,
  })
}

export type ResearchDepth = 'quick' | 'standard' | 'thorough' | 'auto'

export async function startResearch(body: {
  question: string
  depth: ResearchDepth
  chat_id: number
  sources: { web: boolean; knowledge: boolean }
  /** `deeper` after a direct answer the person wants more on; `brief` for a short look. */
  hint?: 'brief' | 'deeper'
  /** The last turns of the chat, newest last, so the research knows what was already said. */
  context?: { turns: Array<{ role: 'user' | 'assistant'; text: string }> }
  /** Pages the research starts from, such as the cited pages of the answer it deepens. */
  seed_sources?: Array<{ url: string; title?: string }>
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

/** Answer now: write the report from what was read, or answer without the research when nothing was. */
export async function answerNowResearch(id: string): Promise<ResearchRunView> {
  return httpClient(`/api/v1/research/${encodeURIComponent(id)}/input`, {
    method: 'POST',
    body: JSON.stringify({ type: 'answer_now' }),
    schema: PostApiResearchInputResponseSchema,
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
