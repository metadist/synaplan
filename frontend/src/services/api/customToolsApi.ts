import { z } from 'zod'
import { httpClient } from './httpClient'

const CustomToolSchema = z.object({
  id: z.number(),
  name: z.string(),
  title: z.string(),
  description: z.string().nullable().optional(),
  type: z.string(),
  sideEffect: z.string(),
  spec: z.record(z.unknown()),
  inputSchema: z.record(z.unknown()).nullable().optional(),
  credentialId: z.number().nullable().optional(),
  enabled: z.boolean(),
  sourceRef: z.string().nullable().optional(),
  created: z.number().optional(),
  updated: z.number().optional(),
  registryName: z.string().optional(),
})

const ListSchema = z.object({
  success: z.boolean(),
  tools: z.array(CustomToolSchema),
})

const OneSchema = z.object({
  success: z.boolean(),
  tool: CustomToolSchema,
})

const OperationSchema = z.object({
  operationId: z.string(),
  summary: z.string().optional(),
  method: z.string(),
  path: z.string(),
  sideEffect: z.string(),
  inputSchema: z.record(z.unknown()).optional(),
  sourceRef: z.string().optional(),
})

const PreviewSchema = z.object({
  success: z.boolean(),
  operations: z.array(OperationSchema),
  dropped: z.number().optional(),
  notices: z.array(z.string()).optional(),
})

export type CustomTool = z.infer<typeof CustomToolSchema>
export type OpenApiOperation = z.infer<typeof OperationSchema>

const fieldClass =
  'mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'

export const customToolFieldClass = fieldClass

export const customToolsApi = {
  async list(): Promise<CustomTool[]> {
    const data = await httpClient('/api/v1/tools/custom', { schema: ListSchema })
    return data.tools
  },

  async create(payload: Record<string, unknown>): Promise<CustomTool> {
    const data = await httpClient('/api/v1/tools/custom', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: OneSchema,
    })
    return data.tool
  },

  async update(id: number, payload: Record<string, unknown>): Promise<CustomTool> {
    const data = await httpClient(`/api/v1/tools/custom/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
      schema: OneSchema,
    })
    return data.tool
  },

  async remove(id: number): Promise<void> {
    await httpClient(`/api/v1/tools/custom/${id}`, { method: 'DELETE' })
  },

  async try(
    id: number,
    input: Record<string, unknown>
  ): Promise<{ sent: boolean; result?: unknown; request?: unknown }> {
    return httpClient(`/api/v1/tools/custom/${id}/try`, {
      method: 'POST',
      body: JSON.stringify({ input }),
      schema: z.object({
        success: z.boolean(),
        sent: z.boolean(),
        result: z.unknown().optional(),
        request: z.unknown().optional(),
      }),
    })
  },

  async previewOpenApi(payload: { url?: string; document?: string }): Promise<{
    operations: OpenApiOperation[]
    notices: string[]
  }> {
    const data = await httpClient('/api/v1/tools/custom/import-openapi/preview', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: PreviewSchema,
    })
    return { operations: data.operations, notices: data.notices ?? [] }
  },

  async applyOpenApi(
    operations: OpenApiOperation[],
    baseUrl: string,
    credentialId: number | null
  ): Promise<CustomTool[]> {
    const data = await httpClient('/api/v1/tools/custom/import-openapi/apply', {
      method: 'POST',
      body: JSON.stringify({ operations, baseUrl, credentialId }),
      schema: z.object({ success: z.boolean(), tools: z.array(CustomToolSchema) }),
    })
    return data.tools
  },
}
