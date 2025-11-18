import { api } from './apiService'

/**
 * RAG Search Service
 * Semantic search in vectorized documents
 */

export interface RagSearchRequest {
  query: string
  limit?: number
  min_score?: number
  group_key?: string
}

export interface RagSearchResult {
  chunk_id: number
  message_id: number
  text: string
  score: number
  start_line?: number
  end_line?: number
}

export interface RagSearchResponse {
  success: boolean
  query: string
  results: RagSearchResult[]
  total_results: number
  search_time_ms: number
  parameters: {
    limit: number
    min_score: number
    group_key: string | null
  }
  error?: string
}

export interface RagStats {
  total_documents: number
  total_chunks: number
  total_groups: number
  avg_chunk_size: number
}

/**
 * Semantic search in vectorized documents
 */
export const search = async (params: RagSearchRequest): Promise<RagSearchResponse> => {
  const response = await api.post<RagSearchResponse>('/api/v1/rag/search', {
    query: params.query,
    limit: params.limit || 10,
    min_score: params.min_score || 0.5,
    group_key: params.group_key || null
  })
  
  return response.data
}

/**
 * Get RAG statistics for current user
 */
export const getStats = async (): Promise<{ success: boolean; stats: RagStats }> => {
  const response = await api.get<{ success: boolean; stats: RagStats }>('/api/v1/rag/stats')
  return response.data
}

/**
 * Find similar documents to a given document
 */
export const findSimilar = async (
  chunkId: number,
  limit: number = 5
): Promise<{ success: boolean; results: RagSearchResult[] }> => {
  const response = await api.get<{ success: boolean; results: RagSearchResult[] }>(
    `/api/v1/rag/similar/${chunkId}?limit=${limit}`
  )
  return response.data
}

export default {
  search,
  getStats,
  findSimilar
}

