export type RerankSaveFeedback = 'off' | 'inactive' | 'active'

/**
 * Toast kind after a successful rerank save.
 * Activation is only confirmed when an adapter is actually available.
 * Rerank is consumed by document search, not chat.
 */
export function rerankSaveFeedback(status: {
  enabled: boolean
  adapters: Array<{ health: { available: boolean } }>
}): RerankSaveFeedback {
  if (!status.enabled) {
    return 'off'
  }

  const adapterReady = status.adapters.some((adapter) => adapter.health.available)
  return adapterReady ? 'active' : 'inactive'
}
