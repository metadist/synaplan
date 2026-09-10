export type RerankSaveFeedback = 'off' | 'inactive' | 'active'

const HTTP_ADAPTER = 'http'
const LLM_ADAPTER = 'llm'

/**
 * Toast kind after a successful rerank save.
 * Must match RerankRegistry::active(): a bound model uses only the HTTP
 * adapter and does not fall back to LLM, even when that adapter is healthy.
 * Rerank is consumed by document search, not chat.
 */
export function rerankSaveFeedback(status: {
  enabled: boolean
  modelKey?: string | null
  llmFallback?: boolean
  adapters: Array<{ key?: string; health: { available: boolean } }>
}): RerankSaveFeedback {
  if (!status.enabled) {
    return 'off'
  }

  const http = status.adapters.find((adapter) => adapter.key === HTTP_ADAPTER)
  const llm = status.adapters.find((adapter) => adapter.key === LLM_ADAPTER)

  if (status.modelKey) {
    return http?.health.available ? 'active' : 'inactive'
  }

  if (status.llmFallback) {
    return llm?.health.available ? 'active' : 'inactive'
  }

  return 'inactive'
}
