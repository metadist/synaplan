import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiAgentTemplatesDiffResponseSchema,
  GetApiAgentTemplatesListResponseSchema,
  PostApiAgentTemplatesInstallResponseSchema,
  PostApiAgentTemplatesUpdateResponseSchema,
} from '@/generated/api-schemas'

export type AgentTemplate = NonNullable<
  z.infer<typeof GetApiAgentTemplatesListResponseSchema>['templates']
>[number]

export type AgentTemplateRequirement = NonNullable<AgentTemplate['requires']>[number]

export type AgentTemplateInstallResult = z.infer<typeof PostApiAgentTemplatesInstallResponseSchema>

const agentTemplatesApi = {
  async list(): Promise<{ peopleCount: number | null; templates: AgentTemplate[] }> {
    const data = await httpClient('/api/v1/agent-templates', {
      method: 'GET',
      schema: GetApiAgentTemplatesListResponseSchema,
    })
    return {
      peopleCount: data.peopleCount ?? null,
      templates: data.templates ?? [],
    }
  },

  async install(
    id: string,
    body: { audience: 'me' | 'everyone'; title: string; description: string; tryIt: string[] }
  ): Promise<AgentTemplateInstallResult> {
    return httpClient(`/api/v1/agent-templates/${id}/install`, {
      method: 'POST',
      body: JSON.stringify(body),
      schema: PostApiAgentTemplatesInstallResponseSchema,
    })
  },

  async diff(id: string): Promise<z.infer<typeof GetApiAgentTemplatesDiffResponseSchema>> {
    return httpClient(`/api/v1/agent-templates/${id}/diff`, {
      method: 'GET',
      schema: GetApiAgentTemplatesDiffResponseSchema,
    })
  },

  async update(id: string): Promise<z.infer<typeof PostApiAgentTemplatesUpdateResponseSchema>> {
    return httpClient(`/api/v1/agent-templates/${id}/update`, {
      method: 'POST',
      schema: PostApiAgentTemplatesUpdateResponseSchema,
    })
  },
}

export { agentTemplatesApi }
