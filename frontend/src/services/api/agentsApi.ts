import { z } from 'zod'
import { ApiError, httpClient } from './httpClient'
import {
  AgentDefinitionV1,
  GetApiAgentsListResponseSchema,
  GetApiAgentsGetResponseSchema,
  PostApiAgentsCreateResponseSchema,
  PatchApiAgentsUpdateResponseSchema,
  PostApiAgentsCloneResponseSchema,
  GetApiAgentsGalleryResponseSchema,
  PostApiAgentsPublishResponseSchema,
  GetApiAgentsVersionsResponseSchema,
  GetApiAgentsVersionResponseSchema,
  GetApiAgentsUsageResponseSchema,
} from '@/generated/api-schemas'

/**
 * The `agent.v1` document as one artifact: generated from the backend's
 * `AgentDefinitionV1` OpenAPI component. Matches
 * {@see AgentDefinition::defaults()} so a missing section is never `undefined`
 * in the builder.
 */
export type AgentDraft = z.infer<typeof AgentDefinitionV1>

export function emptyAgentDraft(): AgentDraft {
  return {
    schema: 'agent.v1',
    models: { chat: null, vision: null, vectorize: null },
    knowledge: {
      ownFolder: true,
      folders: [],
      includeUserFiles: false,
      ragLimit: 8,
      ragMinScore: 0.6,
    },
    tools: { internet: true, files: true, mcpServers: [], allow: [], deny: [] },
    skills: { allow: [], deny: [] },
    parameters: { temperature: 0.7, maxTokens: 4000, language: 'auto', responseSchema: null },
    behaviour: { greeting: '', starterPrompts: [], memory: 'user' },
    triggers: { events: [], schedules: [] },
  }
}

export type Agent = NonNullable<z.infer<typeof GetApiAgentsGetResponseSchema>['agent']>
export type AgentSummary = NonNullable<
  z.infer<typeof GetApiAgentsListResponseSchema>['agents']
>[number]
export type GalleryCard = NonNullable<
  z.infer<typeof GetApiAgentsGalleryResponseSchema>['cards']
>[number]

export interface AgentWritePayload {
  name?: string
  description?: string | null
  icon?: string
  promptId?: number
  draft?: AgentDraft
  routable?: boolean
  status?: 'archived' | 'published'
}

export function agentFieldPath(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.details) {
    return null
  }
  const path = error.details.path
  return typeof path === 'string' && path !== '' ? path : null
}

export type AgentVersionCard = NonNullable<
  z.infer<typeof PostApiAgentsPublishResponseSchema>['version']
>
export type AgentVersionDetail = NonNullable<
  z.infer<typeof GetApiAgentsVersionResponseSchema>['version']
>
export type AgentUsage = {
  byVersion: NonNullable<z.infer<typeof GetApiAgentsUsageResponseSchema>['byVersion']>
  byDay: NonNullable<z.infer<typeof GetApiAgentsUsageResponseSchema>['byDay']>
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

  async publish(id: number, changelog: string): Promise<AgentVersionCard> {
    const data = await httpClient(`/api/v1/agents/${id}/publish`, {
      method: 'POST',
      body: JSON.stringify({ changelog }),
      schema: PostApiAgentsPublishResponseSchema,
    })
    if (!data.version) {
      throw new Error('Invalid API response format: version missing')
    }
    return data.version
  },

  async versions(id: number): Promise<AgentVersionCard[]> {
    const data = await httpClient(`/api/v1/agents/${id}/versions`, {
      method: 'GET',
      schema: GetApiAgentsVersionsResponseSchema,
    })
    return data.versions ?? []
  },

  async version(id: number, version: number): Promise<AgentVersionDetail> {
    const data = await httpClient(`/api/v1/agents/${id}/versions/${version}`, {
      method: 'GET',
      schema: GetApiAgentsVersionResponseSchema,
    })
    if (!data.version) {
      throw new Error('Invalid API response format: version missing')
    }
    return data.version
  },

  async usage(id: number, from?: number, to?: number): Promise<AgentUsage> {
    const query = new URLSearchParams()
    if (from) query.set('from', String(from))
    if (to) query.set('to', String(to))
    const suffix = query.size > 0 ? `?${query.toString()}` : ''
    const data = await httpClient(`/api/v1/agents/${id}/usage${suffix}`, {
      method: 'GET',
      schema: GetApiAgentsUsageResponseSchema,
    })
    return {
      byVersion: data.byVersion ?? [],
      byDay: data.byDay ?? [],
    }
  },
}

function requireAgent(agent: Agent | undefined): Agent {
  if (!agent) {
    throw new Error('Invalid API response format: agent missing')
  }
  return {
    ...agent,
    draft: AgentDefinitionV1.parse({
      ...emptyAgentDraft(),
      ...(agent.draft ?? {}),
    }),
  }
}
