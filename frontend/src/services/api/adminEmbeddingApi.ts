import { httpClient } from './httpClient'

export interface EmbeddingCurrentModel {
  modelId: number | null
  provider: string | null
  model: string | null
  vectorDim: number
}

export interface EmbeddingGuardStatus {
  canChange: boolean
  reason: 'requires_premium' | 'cooldown_active' | null
  currentLevel: string
  cooldownEndsAt: number | null
  cooldownSecondsRemaining: number
}

export type EmbeddingRunScope = 'documents' | 'memories' | 'synapse' | 'all'

export type EmbeddingRunStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'

export type EmbeddingSeverity = 'info' | 'warning' | 'critical'

export interface EmbeddingRun {
  id: number
  userId: number
  scope: EmbeddingRunScope
  fromModelId: number | null
  toModelId: number
  status: EmbeddingRunStatus
  severity: EmbeddingSeverity
  chunksTotal: number | null
  chunksProcessed: number
  chunksFailed: number
  tokensEstimated: number | null
  tokensProcessed: number
  costEstimatedUsd: string | null
  costActualUsd: string
  startedAt: number | null
  finishedAt: number | null
  created: number
  updated: number
  error: string | null
}

export interface EmbeddingStatusResponse {
  success: boolean
  currentModel: EmbeddingCurrentModel
  guard: EmbeddingGuardStatus
  activeRun: EmbeddingRun | null
  latestRun: EmbeddingRun | null
}

export interface EmbeddingScopeEstimate {
  chunks: number
  tokensEstimated: number
  costEstimatedUsd: number
}

export interface EmbeddingCostEstimate {
  success: boolean
  fromModelId: number | null
  toModelId: number
  fromModel: {
    provider: string | null
    model: string | null
    modelId: number | null
    vectorDim: number
  }
  toModel: {
    provider: string | null
    model: string | null
    modelId: number
    vectorDim: number
    pricePerMTokens: number
  }
  scopes: {
    documents: EmbeddingScopeEstimate
    memories: EmbeddingScopeEstimate
    synapse: EmbeddingScopeEstimate
  }
  totals: {
    chunks: number
    tokensEstimated: number
    costEstimatedUsd: number
  }
  severity: EmbeddingSeverity
  thresholds: {
    warning: number
    critical: number
  }
}

export interface EmbeddingSwitchRequest {
  toModelId: number
  scope?: EmbeddingRunScope
  confirmCritical?: boolean
}

export interface EmbeddingSwitchResponse {
  success: boolean
  runId: number
  estimate: Omit<EmbeddingCostEstimate, 'success'>
}

export interface EmbeddingRunsResponse {
  success: boolean
  runs: EmbeddingRun[]
}

export interface EmbeddingRunDetailResponse {
  success: boolean
  run: EmbeddingRun
}

class AdminEmbeddingApi {
  /**
   * Snapshot of the SafeModelChange (embedding) state: active model,
   * whether the current admin may switch right now (premium + cooldown),
   * the most recent run for any scope, and any active queued/running run.
   */
  async getStatus(): Promise<EmbeddingStatusResponse> {
    return await httpClient<EmbeddingStatusResponse>('/api/v1/admin/embedding/status', {
      method: 'GET',
    })
  }

  /**
   * Pre-flight cost estimate for switching to {toModelId}. Returns per-
   * scope chunk + token + USD breakdown plus the severity classification
   * the UI uses to colour the warning banner.
   */
  async costEstimate(toModelId: number): Promise<EmbeddingCostEstimate> {
    return await httpClient<EmbeddingCostEstimate>(
      `/api/v1/admin/embedding/cost-estimate?to=${toModelId}`,
      { method: 'GET' }
    )
  }

  /**
   * Persist the new VECTORIZE default and queue a re-vectorize job.
   * The backend dispatches an async ReVectorizeMessage; the UI polls
   * `/runs/{id}` for live progress.
   */
  async switch(request: EmbeddingSwitchRequest): Promise<EmbeddingSwitchResponse> {
    return await httpClient<EmbeddingSwitchResponse>('/api/v1/admin/embedding/switch', {
      method: 'POST',
      body: JSON.stringify(request),
    })
  }

  async runs(): Promise<EmbeddingRunsResponse> {
    return await httpClient<EmbeddingRunsResponse>('/api/v1/admin/embedding/runs', {
      method: 'GET',
    })
  }

  async runDetail(id: number): Promise<EmbeddingRunDetailResponse> {
    return await httpClient<EmbeddingRunDetailResponse>(`/api/v1/admin/embedding/runs/${id}`, {
      method: 'GET',
    })
  }

  /**
   * Synapse Routing has its own embedding-model binding — separate
   * from the user-facing VECTORIZE setting — so the highest-quality
   * model can be pinned for short multilingual prompt classification
   * without forcing every user's RAG to re-embed.
   */
  async getSynapseStatus(): Promise<SynapseEmbeddingStatusResponse> {
    return await httpClient<SynapseEmbeddingStatusResponse>(
      '/api/v1/admin/embedding/synapse/status',
      { method: 'GET' }
    )
  }

  async switchSynapse(toModelId: number): Promise<SynapseEmbeddingSwitchResponse> {
    return await httpClient<SynapseEmbeddingSwitchResponse>(
      '/api/v1/admin/embedding/synapse/switch',
      { method: 'POST', body: JSON.stringify({ toModelId }) }
    )
  }
}

export interface SynapseAvailableModel {
  id: number
  name: string
  service: string
  providerId: string
}

export interface SynapseEmbeddingStatusResponse {
  success: boolean
  currentModel: EmbeddingCurrentModel
  availableModels: SynapseAvailableModel[]
  latestRun: EmbeddingRun | null
  activeRun: EmbeddingRun | null
}

export interface SynapseEmbeddingSwitchResponse {
  success: boolean
  runId: number
  fromModelId: number | null
  toModelId: number
}

export const adminEmbeddingApi = new AdminEmbeddingApi()
