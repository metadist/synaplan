import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiToolsCustomListResponseSchema,
  PatchApiToolsCustomUpdateResponseSchema,
  PostApiToolsCustomCreateResponseSchema,
  PostApiToolsCustomImportApplyResponseSchema,
  PostApiToolsCustomImportPreviewResponseSchema,
  PostApiToolsCustomTryResponseSchema,
} from '@/generated/api-schemas'

export type CustomTool = z.infer<typeof GetApiToolsCustomListResponseSchema>['tools'][number]
export type OpenApiOperation = z.infer<
  typeof PostApiToolsCustomImportPreviewResponseSchema
>['operations'][number]

const fieldClass =
  'mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed'

export const customToolFieldClass = fieldClass

export const customToolsApi = {
  async list(): Promise<CustomTool[]> {
    const data = await httpClient('/api/v1/tools/custom', {
      schema: GetApiToolsCustomListResponseSchema,
    })
    return data.tools
  },

  async create(payload: Record<string, unknown>): Promise<CustomTool> {
    const data = await httpClient('/api/v1/tools/custom', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: PostApiToolsCustomCreateResponseSchema,
    })
    return data.tool
  },

  async update(id: number, payload: Record<string, unknown>): Promise<CustomTool> {
    const data = await httpClient(`/api/v1/tools/custom/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
      schema: PatchApiToolsCustomUpdateResponseSchema,
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
      schema: PostApiToolsCustomTryResponseSchema,
    })
  },

  async previewOpenApi(payload: { url?: string; document?: string }): Promise<{
    operations: OpenApiOperation[]
    notices: string[]
  }> {
    const data = await httpClient('/api/v1/tools/custom/import-openapi/preview', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: PostApiToolsCustomImportPreviewResponseSchema,
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
      schema: PostApiToolsCustomImportApplyResponseSchema,
    })
    return data.tools
  },
}
