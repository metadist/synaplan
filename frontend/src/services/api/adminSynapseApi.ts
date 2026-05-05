import { httpClient } from './httpClient'
import type { RoutingTestResult } from './promptsApi'

export interface SynapseActiveModel {
  provider: string | null
  model: string | null
  modelId: number | null
  vectorDim: number
}

export interface SynapseCollectionInfo {
  name: string
  exists: boolean
  vectorDim: number | null
  pointsCount: number | null
  distance: string | null
}

export interface SynapsePerModelEntry {
  modelId: number | null
  provider: string | null
  model: string | null
  vectorDim: number | null
  count: number
}

export interface SynapseTopicEntry {
  topic: string
  ownerId: number
  enabled: boolean
  indexed: boolean
  stale: boolean
  embeddingModelId: number | null
  embeddingProvider: string | null
  embeddingModel: string | null
  vectorDim: number | null
  indexedAt: string | null
}

export interface SynapseStatusResponse {
  success: boolean
  activeModel: SynapseActiveModel
  collection: SynapseCollectionInfo
  totalIndexed: number
  staleCount: number
  dimensionMismatch: boolean
  perModel: SynapsePerModelEntry[]
  topics: SynapseTopicEntry[]
  aliases: Record<string, string>
}

export interface SynapseReindexRequest {
  force?: boolean
  recreate?: boolean
  topic?: string
}

export interface SynapseReindexResponse {
  success: boolean
  recreated: boolean
  force: boolean
  indexed: number
  skipped: number
  errors: number
  topic?: string
  topicResult?: string
}

class AdminSynapseApi {
  /**
   * Snapshot of the Synapse Routing index health.
   *
   * Aggregates the active VECTORIZE model, the live Qdrant collection state,
   * per-model point counts and a per-topic stale-flag so the admin UI can
   * surface dimension mismatches and outdated embeddings without a CLI hop.
   */
  async getStatus(): Promise<SynapseStatusResponse> {
    return await httpClient<SynapseStatusResponse>('/api/v1/admin/synapse/status', {
      method: 'GET',
    })
  }

  /**
   * Trigger a synchronous re-index of the synapse_topics collection.
   *
   * `force` bypasses the source-hash skip-when-unchanged optimisation.
   * `recreate` drops + recreates the Qdrant collection with the active
   * model dimension (required when switching to an embedding model with
   * a different output dimensionality).
   */
  async reindex(request: SynapseReindexRequest = {}): Promise<SynapseReindexResponse> {
    return await httpClient<SynapseReindexResponse>('/api/v1/admin/synapse/reindex', {
      method: 'POST',
      body: JSON.stringify(request),
    })
  }

  /**
   * Dry-run the SynapseRouter for a sample message.
   * Returns the Top-K Qdrant matches with stale-flag and alias resolution.
   */
  async dryRun(text: string, limit: number = 5): Promise<RoutingTestResult> {
    return await httpClient<RoutingTestResult>('/api/v1/admin/synapse/dry-run', {
      method: 'POST',
      body: JSON.stringify({ text, limit }),
    })
  }
}

export const adminSynapseApi = new AdminSynapseApi()
