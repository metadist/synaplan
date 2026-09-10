import { httpClient } from './httpClient'
import { GetApiToolsListResponseSchema } from '@/generated/api-schemas'

export type RegistryTool = {
  name: string
  title: string
  description: string
  sideEffect: string
  source: string
  /** JSON Schema of the tool's arguments, when the tool publishes one. */
  inputSchema?: Record<string, unknown>
}

export const toolsApi = {
  async list(): Promise<RegistryTool[]> {
    const data = await httpClient('/api/v1/tools', {
      schema: GetApiToolsListResponseSchema,
    })
    return (data.tools ?? []).map((tool) => ({
      name: tool.name ?? '',
      title: tool.title ?? tool.name ?? '',
      description: tool.description ?? '',
      sideEffect: tool.sideEffect ?? 'read',
      source: tool.source ?? 'custom',
      ...(tool.inputSchema && typeof tool.inputSchema === 'object'
        ? { inputSchema: tool.inputSchema as Record<string, unknown> }
        : {}),
    }))
  },
}
