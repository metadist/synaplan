import { httpClient } from './httpClient'

export interface AdminModel {
  id: number
  service: string
  tag: string
  providerId: string
  name: string
  selectable: number
  active: number
  priceIn: number
  inUnit: string
  priceOut: number
  outUnit: string
  quality: number
  rating: number
  description: string | null
  json: Record<string, unknown>
  isSystemModel: boolean
}

export interface AdminModelsListResponse {
  success: boolean
  models: AdminModel[]
}

export interface AdminModelCreateRequest {
  service: string
  tag: string
  providerId: string
  name: string
  selectable?: number
  active?: number
  priceIn?: number
  inUnit?: string
  priceOut?: number
  outUnit?: string
  quality?: number
  rating?: number
  description?: string | null
  json?: Record<string, unknown>
}

export interface AdminModelUpdateRequest extends Partial<AdminModelCreateRequest> {}

export interface AdminModelUpsertResponse {
  success: boolean
  model: AdminModel
}

export interface AdminImportPreviewRequest {
  urls: string[]
  textDump: string
  allowDelete?: boolean
}

export interface AdminImportPreviewResponse {
  success: boolean
  sql: string
  ai: { provider: string | null; model: string | null }
  validation: {
    ok: boolean
    errors: string[]
    statements: string[]
  }
}

export interface AdminImportApplyRequest {
  sql: string
  allowDelete?: boolean
}

export interface AdminImportApplyResponse {
  success: boolean
  applied: number
  statements: string[]
}

export const adminModelsApi = {
  list: async (): Promise<AdminModelsListResponse> => {
    return httpClient<AdminModelsListResponse>('/api/v1/admin/models')
  },
  create: async (req: AdminModelCreateRequest): Promise<AdminModelUpsertResponse> => {
    return httpClient<AdminModelUpsertResponse>('/api/v1/admin/models', {
      method: 'POST',
      body: JSON.stringify(req),
    })
  },
  update: async (id: number, req: AdminModelUpdateRequest): Promise<AdminModelUpsertResponse> => {
    return httpClient<AdminModelUpsertResponse>(`/api/v1/admin/models/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(req),
    })
  },
  delete: async (id: number): Promise<{ success: boolean }> => {
    return httpClient<{ success: boolean }>(`/api/v1/admin/models/${id}`, {
      method: 'DELETE',
    })
  },
  importPreview: async (req: AdminImportPreviewRequest): Promise<AdminImportPreviewResponse> => {
    return httpClient<AdminImportPreviewResponse>('/api/v1/admin/models/import/preview', {
      method: 'POST',
      body: JSON.stringify(req),
    })
  },
  importApply: async (req: AdminImportApplyRequest): Promise<AdminImportApplyResponse> => {
    return httpClient<AdminImportApplyResponse>('/api/v1/admin/models/import/apply', {
      method: 'POST',
      body: JSON.stringify(req),
    })
  },
}
