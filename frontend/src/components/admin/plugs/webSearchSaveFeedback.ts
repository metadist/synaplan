export type WebSearchSaveFeedbackLevel = 'success' | 'warning'

export type WebSearchSaveFeedbackKey =
  'saved' | 'savedInactive' | 'savedInactiveFallback' | 'savedInactiveBoth'

export interface WebSearchSaveFeedback {
  level: WebSearchSaveFeedbackLevel
  key: WebSearchSaveFeedbackKey
  params: { provider: string; reason: string }
}

export interface WebSearchSaveStatus {
  active: string
  fallback: string
  providers: Array<{
    key: string
    label: string
    health: { available: boolean; reason: string | null }
  }>
}

/**
 * Toast after saving the active / fallback web-search provider.
 * A stored selection is not the same as a working search.
 */
export function webSearchSaveFeedback(status: WebSearchSaveStatus): WebSearchSaveFeedback {
  const active = status.providers.find((provider) => provider.key === status.active)
  const provider = active?.label ?? status.active
  const reason = active?.health.reason ?? ''

  if (active?.health.available) {
    return { level: 'success', key: 'saved', params: { provider, reason } }
  }

  const fallback = status.fallback
    ? status.providers.find((provider) => provider.key === status.fallback)
    : undefined

  if (fallback?.health.available) {
    return { level: 'warning', key: 'savedInactiveFallback', params: { provider, reason } }
  }

  if (status.fallback !== '') {
    return { level: 'warning', key: 'savedInactiveBoth', params: { provider, reason } }
  }

  return { level: 'warning', key: 'savedInactive', params: { provider, reason } }
}
