import { httpClient } from './httpClient'

export interface OpenAiEndpoint {
  name: string
  label: string
  base_url: string
  has_api_key: boolean
  headers: Record<string, string>
  capabilities: string[]
}

export interface OpenAiEndpointsListResponse {
  success: boolean
  endpoints: OpenAiEndpoint[]
  capabilities: string[]
}

export interface OpenAiEndpointSaveRequest {
  name: string
  label?: string
  base_url: string
  // Omit / null → keep the stored key; '' → clear it.
  api_key?: string | null
  headers?: Record<string, string>
  capabilities?: string[]
}

export interface OpenAiEndpointTestRequest {
  name?: string
  base_url?: string
  api_key?: string | null
  headers?: Record<string, string>
}

export interface OpenAiEndpointTestResponse {
  ok: boolean
  status?: number
  model_count?: number
  sample?: string[]
  error?: string
}

export const adminOpenAiEndpointsApi = {
  list: async (): Promise<OpenAiEndpointsListResponse> => {
    return httpClient<OpenAiEndpointsListResponse>('/api/v1/admin/openai-endpoints')
  },
  save: async (req: OpenAiEndpointSaveRequest): Promise<OpenAiEndpointsListResponse> => {
    return httpClient<OpenAiEndpointsListResponse>('/api/v1/admin/openai-endpoints', {
      method: 'POST',
      body: JSON.stringify(req),
    })
  },
  test: async (req: OpenAiEndpointTestRequest): Promise<OpenAiEndpointTestResponse> => {
    return httpClient<OpenAiEndpointTestResponse>('/api/v1/admin/openai-endpoints/test', {
      method: 'POST',
      body: JSON.stringify(req),
    })
  },
  delete: async (name: string): Promise<{ success: boolean }> => {
    return httpClient<{ success: boolean }>(
      `/api/v1/admin/openai-endpoints/${encodeURIComponent(name)}`,
      {
        method: 'DELETE',
      },
    )
  },
}
