import { z } from 'zod'
import { ApiError, httpClient } from './httpClient'
import {
  GetApiAgentsListResponseSchema,
  GetApiAgentsGetResponseSchema,
  PostApiAgentsCreateResponseSchema,
  PatchApiAgentsUpdateResponseSchema,
  PostApiAgentsCloneResponseSchema,
  GetApiAgentsGalleryResponseSchema,
} from '@/generated/api-schemas'

export type Agent = NonNullable<z.infer<typeof GetApiAgentsGetResponseSchema>['agent']>
export type AgentSummary = NonNullable<
  z.infer<typeof GetApiAgentsListResponseSchema>['agents']
>[number]
export type GalleryCard = NonNullable<
  z.infer<typeof GetApiAgentsGalleryResponseSchema>['cards']
>[number]

export type AgentDraft = NonNullable<Agent>['draft']

export interface AgentWritePayload {
  name?: string
  description?: string | null
  icon?: string
  promptId?: number
  draft?: Record<string, unknown>
  routable?: boolean
}

export function agentFieldPath(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.details) {
    return null
  }
  const path = error.details.path
  return typeof path === 'string' && path !== '' ? path : null
}

export const agentsApi = {
  async list(): Promise<AgentSummary[]> {
    const data = await httpClient('/api/v1/agents', {
      method: 'GET',
      schema: GetApiAgentsListResponseSchema,
    })
    return data.agents ?? []
  },

  async gallery(): Promise<GalleryCard[]> {
    const data = await httpClient('/api/v1/agents/gallery', {
      method: 'GET',
      schema: GetApiAgentsGalleryResponseSchema,
    })
    return data.cards ?? []
  },

  async get(id: number): Promise<Agent> {
    const data = await httpClient(`/api/v1/agents/${id}`, {
      method: 'GET',
      schema: GetApiAgentsGetResponseSchema,
    })
    return requireAgent(data.agent)
  },

  async create(payload: AgentWritePayload): Promise<Agent> {
    const data = await httpClient('/api/v1/agents', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: PostApiAgentsCreateResponseSchema,
    })
    return requireAgent(data.agent)
  },

  async update(id: number, payload: AgentWritePayload): Promise<Agent> {
    const data = await httpClient(`/api/v1/agents/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
      schema: PatchApiAgentsUpdateResponseSchema,
    })
    return requireAgent(data.agent)
  },

  async remove(id: number): Promise<void> {
    await httpClient(`/api/v1/agents/${id}`, { method: 'DELETE' })
  },

  async clone(id: number): Promise<Agent> {
    const data = await httpClient(`/api/v1/agents/${id}/clone`, {
      method: 'POST',
      schema: PostApiAgentsCloneResponseSchema,
    })
    return requireAgent(data.agent)
  },
}

function requireAgent(agent: Agent | undefined): Agent {
  if (!agent) {
    throw new Error('Invalid API response format: agent missing')
  }
  return agent
}
